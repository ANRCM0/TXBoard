<?php

namespace App\Services\Subscription;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Installation\InstallationAuthority;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Durable non-secret Provider operations; an issue receipt is not a Node grant. */
final class NativeCredentialProviderService
{
    public const SUBJECT = 'core-access-subject/v1';
    public const OPERATION = 'core-credential-operation/v1';
    private const REGISTRATIONS = 'tx_credential_provider_registration';
    private const OPERATIONS = 'tx_credential_provider_operation';
    private const SUBJECTS = 'tx_access_subject';

    public function __construct(
        private NativeSubscriptionStore $store,
        private ProviderTransport $transport,
    ) {}

    public function register(string $providerId, string $businessKey): array
    {
        $this->freshTransaction();
        $this->key($businessKey);
        $configuration = $this->transport->configuration($providerId);
        return DB::transaction(function () use ($configuration, $businessKey): array {
            app(InstallationAuthority::class)->assertCatalogMutation();
            $installation = app(InstallationAuthority::class)->inspect();
            $snapshot = [
                'installation_id' => $installation['installation_id'],
                'provider_id' => $configuration['provider_id'],
                'provider_instance_epoch' => (string) $configuration['provider_instance_epoch'],
                'config_digest' => $configuration['config_digest'],
                'profiles' => $configuration['profiles'], 'business_key' => $businessKey,
            ];
            $rows = DB::table(self::REGISTRATIONS)->where('provider_id', $configuration['provider_id'])
                ->orWhere('business_key', $businessKey)->orderBy('id')->lockForUpdate()->get();
            if ($rows->isNotEmpty()) {
                $this->require($rows->count() === 1, 'REGISTRATION_CONFLICT');
                $row = $rows->first();
                $this->verifyRegistration($row, $installation['installation_id']);
                $this->require(hash_equals($row->content_hash, $this->hash($snapshot)), 'REGISTRATION_CONFLICT');
                return $this->publicRegistration($row);
            }
            $id = DB::table(self::REGISTRATIONS)->insertGetId(array_replace($snapshot, [
                'profiles' => $this->json($snapshot['profiles']),
                'content_hash' => $this->hash($snapshot), 'created_at' => time(),
            ]));
            return $this->publicRegistration(DB::table(self::REGISTRATIONS)->find($id));
        }, 3);
    }

    public function issue(int $userId, int $subscriptionId, string $providerId, string $profile,
        string $businessKey, int|string $expectedVersion): array
    {
        $this->freshTransaction();
        $request = [
            'kind' => 'issue', 'user_id' => $this->decimal($userId),
            'subscription_id' => $this->decimal($subscriptionId), 'provider_id' => $providerId,
            'credential_profile_id' => $profile, 'business_key' => $this->key($businessKey),
            'expected_version' => $this->decimal($expectedVersion),
        ];
        $this->require((bool) preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $providerId)
            && in_array($profile, ['vless-uuid/v1', 'vmess-uuid/v1'], true), 'INPUT_INVALID');
        $reference = DB::transaction(function () use ($request, $userId, $subscriptionId, $providerId, $profile): string {
            $now = time();
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            [$user, $slot, $subscriptions] = $this->store->lockAccount($userId, $now, $installation['installation_id']);
            $subscription = $subscriptions->firstWhere('id', $subscriptionId);
            $this->require($subscription !== null, 'SUBSCRIPTION_MISSING');
            $history = $this->store->lockHistory($subscription);
            $subjects = DB::table(self::SUBJECTS)->forceIndex('tx_native_subject_sub_idx')->where('subscription_id', $subscriptionId)
                ->orderBy('id')->limit(1001)->lockForUpdate()->get();
            $this->require($subjects->count() <= 1000, 'SUBJECT_HISTORY_LIMIT');
            $registration = DB::table(self::REGISTRATIONS)->where('provider_id', $providerId)->lockForUpdate()->first();
            $this->require($registration !== null, 'REGISTRATION_MISSING');
            $this->verifyRegistration($registration, $installation['installation_id']);
            $existing = DB::table(self::OPERATIONS)->where('user_id', $userId)
                ->where('business_key', $request['business_key'])->lockForUpdate()->first();
            if ($existing) {
                $this->verifyOperation($existing);
                $this->require(hash_equals($existing->request_hash, $this->hash($request)), 'IDEMPOTENCY_CONFLICT');
                return $existing->operation_ref;
            }
            $this->require((string) $subscription->row_version === $request['expected_version'], 'VERSION_CONFLICT');
            $this->require(in_array($profile, $this->decode($registration->profiles), true), 'PROFILE_UNSUPPORTED');
            $this->entitlement($user, $slot, $subscription, $history, $now);
            foreach ($subjects as $subject) {
                $this->verifySubject($subject, $subscription, $installation['installation_id']);
                $this->require($subject->provider_id !== $providerId || $subject->credential_profile_id !== $profile
                    || $subject->status === 'revoked', 'SUBJECT_ALREADY_EXISTS');
            }
            $subjectId = DB::table(self::SUBJECTS)->insertGetId([
                'subscription_id' => $subscriptionId, 'provider_id' => $providerId,
                'provider_subject_ref' => 'subject_'.bin2hex(random_bytes(16)),
                'status' => 'pending', 'auth_version' => 1, 'revocation_fact_version' => 0,
                'row_version' => 1, 'created_at' => $now, 'updated_at' => $now,
                'authority_version' => self::SUBJECT, 'installation_id' => $installation['installation_id'],
                'provider_registration_id' => $registration->id, 'credential_profile_id' => $profile,
                'bound_subscription_version' => $subscription->row_version,
                'bound_terms_id' => $subscription->current_terms_id,
            ]);
            $subject = DB::table(self::SUBJECTS)->find($subjectId);
            $expiresAt = min($now + 31 * 86400, $subscription->expires_at === null ? PHP_INT_MAX : (int) $subscription->expires_at);
            $input = [
                'installation_id' => $installation['installation_id'],
                'access_subject_id' => (string) $subjectId, 'subscription_id' => (string) $subscriptionId,
                'terms_id' => (string) $subscription->current_terms_id,
                'provider_subject_ref' => $subject->provider_subject_ref, 'expected_subject_version' => '1',
                'credential_profile_id' => $profile, 'not_before_at' => $now, 'credential_expires_at' => $expiresAt,
            ];
            return $this->record($request, $subject, $registration, $input, $now);
        }, 3);
        return $this->drive($userId, $reference);
    }

    public function revoke(int $userId, int $subjectId, string $businessKey, int|string $expectedVersion,
        string $reason = 'MANUAL'): array
    {
        $this->freshTransaction();
        $request = [
            'kind' => 'revoke', 'user_id' => $this->decimal($userId), 'access_subject_id' => $this->decimal($subjectId),
            'business_key' => $this->key($businessKey), 'expected_version' => $this->decimal($expectedVersion),
            'reason_code' => $reason,
        ];
        $this->require(in_array($reason, ['MANUAL', 'SUBSCRIPTION_CHANGED', 'ACCOUNT_BANNED'], true), 'INPUT_INVALID');
        $locator = DB::table(self::SUBJECTS)->useWritePdo()->where('id', $subjectId)->first();
        $this->require($locator !== null, 'SUBJECT_MISSING');
        $reference = DB::transaction(function () use ($request, $userId, $subjectId, $locator, $reason): string {
            $now = time();
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            [, , $subscriptions] = $this->store->lockAccount($userId, $now, $installation['installation_id']);
            $subscription = $subscriptions->firstWhere('id', $locator->subscription_id);
            $this->require($subscription !== null, 'SUBJECT_MISSING');
            $subject = DB::table(self::SUBJECTS)->where('id', $subjectId)->lockForUpdate()->first();
            $this->verifySubject($subject, $subscription, $installation['installation_id']);
            $registration = DB::table(self::REGISTRATIONS)->where('id', $subject->provider_registration_id)->lockForUpdate()->first();
            $this->verifyRegistration($registration, $installation['installation_id']);
            $existing = DB::table(self::OPERATIONS)->where('user_id', $userId)
                ->where('business_key', $request['business_key'])->lockForUpdate()->first();
            if ($existing) {
                $this->verifyOperation($existing);
                $this->require(hash_equals($existing->request_hash, $this->hash($request)), 'IDEMPOTENCY_CONFLICT');
                return $existing->operation_ref;
            }
            $this->require((string) $subject->row_version === $request['expected_version'], 'VERSION_CONFLICT');
            return $this->recordRevoke($request, $subject, $registration, $reason, $now);
        }, 3);
        return $this->drive($userId, $reference);
    }

    public function recover(int $userId, string $operationRef): array
    {
        $this->freshTransaction();
        $this->decimal($userId);
        $this->require((bool) preg_match('/\Acp_[0-9a-f]{32}\z/D', $operationRef), 'INPUT_INVALID');
        return $this->drive($userId, $operationRef, true);
    }

    public function operation(int $userId, string $operationRef): array
    {
        $this->decimal($userId);
        $this->require((bool) preg_match('/\Acp_[0-9a-f]{32}\z/D', $operationRef), 'INPUT_INVALID');
        return $this->withOperation($userId, $operationRef,
            fn ($operation, $subject): array => $this->publicOperation($operation, $subject), true);
    }

    private function drive(int $userId, string $reference, bool $recovery = false): array
    {
        $attempt = $this->withOperation($userId, $reference, function ($operation, $subject, $registration): array {
            if (in_array($operation->status, ['succeeded', 'rejected', 'orphaned'], true)) {
                return ['terminal' => true, 'public' => $this->publicOperation($operation, $subject)];
            }
            $this->require(in_array($operation->status, ['pending', 'unknown'], true), 'OPERATION_DAMAGED');
            $wire = $this->decode($operation->wire_request);
            $queryFirst = (int) $operation->attempts > 0;
            DB::table(self::OPERATIONS)->where('id', $operation->id)->update([
                'status' => 'unknown', 'attempts' => (int) $operation->attempts + 1, 'updated_at' => time(),
            ]);
            return ['terminal' => false, 'query_first' => $queryFirst, 'wire' => $wire,
                'registration' => array_replace((array) $registration, [
                    'provider_instance_epoch' => (string) $registration->provider_instance_epoch,
                ])];
        });
        if ($attempt['terminal']) {
            return $this->recoverRemediation($userId, $attempt['public']);
        }
        try {
            $wire = $attempt['wire'];
            $registration = $attempt['registration'];
            if ($recovery || $attempt['query_first']) {
                $query = $this->envelope('query', $registration, $wire['operation_id'], $wire['idempotency_key'],
                    ['operation_id' => $wire['operation_id']]);
                $response = $this->transport->call($registration + ['original_request' => $wire], $query);
                if ($response['status'] === 'succeeded') {
                    $queried = $response['result'];
                    $this->require(($queried['operation'] ?? null) === $wire['operation']
                        && ($queried['input_hash'] ?? null) === $wire['input_hash'], 'RECEIPT_INVALID');
                    $response['result'] = $queried['result'];
                } elseif ($response['status'] === 'unknown'
                    && ($response['error']['code'] ?? null) === 'OPERATION_UNKNOWN') {
                    $response = $this->transport->call($registration, $this->refreshEnvelope($wire));
                }
            } else {
                $response = $this->transport->call($registration, $this->refreshEnvelope($wire));
            }
        } catch (Throwable $error) {
            $code = $error instanceof ProviderError ? $error->codeName : 'PROVIDER_RESPONSE_INVALID';
            return $this->unknown($userId, $reference, $code);
        }
        if ($response['status'] !== 'succeeded') {
            $code = $response['error']['code'] ?? 'PROVIDER_OUTCOME_UNKNOWN';
            if ($response['status'] !== 'rejected' || ($response['error']['retryable'] ?? true)) {
                return $this->unknown($userId, $reference, $code);
            }
            return $this->withOperation($userId, $reference, function ($operation, $subject) use ($code): array {
                if (!in_array($operation->status, ['succeeded', 'rejected', 'orphaned'], true)) {
                    DB::table(self::OPERATIONS)->where('id', $operation->id)->update([
                        'status' => 'rejected', 'error_code' => $this->diagnostic($code), 'updated_at' => time(),
                    ]);
                    $operation->status = 'rejected';
                    $operation->error_code = $this->diagnostic($code);
                    if ($operation->kind === 'issue' && $subject->status !== 'revoked') {
                        $this->denySubject($subject, time());
                    }
                }
                return $this->publicOperation($operation, $subject);
            });
        }
        try {
            $public = $this->finalize($userId, $reference, $response['result']);
        } catch (DomainException $error) {
            if ($error->getMessage() !== 'NATIVE_CREDENTIAL_RECEIPT_INVALID') {
                throw $error;
            }
            return $this->unknown($userId, $reference, 'PROVIDER_RESPONSE_INVALID');
        }
        return $this->recoverRemediation($userId, $public);
    }

    private function finalize(int $userId, string $reference, array $result): array
    {
        return $this->withOperation($userId, $reference,
            function ($operation, $subject, $registration, $subscription, $user, $slot, $history) use ($result): array {
                if (in_array($operation->status, ['succeeded', 'rejected', 'orphaned'], true)) {
                    return $this->publicOperation($operation, $subject);
                }
                $now = time();
                $wire = $this->decode($operation->wire_request);
                $this->verifyReceipt($wire, $result);
                $status = 'succeeded';
                $remediation = null;
                if ($operation->kind === 'issue') {
                    $usable = $subject->status === 'pending'
                        && (string) $subject->row_version === $wire['input']['expected_subject_version']
                        && (string) $subscription->row_version === (string) $subject->bound_subscription_version
                        && (string) $subscription->current_terms_id === (string) $subject->bound_terms_id
                        && $result['credential_expires_at'] > $now;
                    try {
                        $this->entitlement($user, $slot, $subscription, $history, $now);
                    } catch (Throwable) {
                        $usable = false;
                    }
                    $metadata = [
                        'credential_ref' => $result['credential_ref'], 'credential_generation' => $result['credential_generation'],
                        'issued_at' => $result['issued_at'], 'not_before_at' => $result['not_before_at'],
                        'credential_expires_at' => $result['credential_expires_at'], 'updated_at' => $now,
                    ];
                    if ($usable) {
                        $metadata += ['status' => 'active', 'row_version' => (int) $subject->row_version + 1];
                    }
                    DB::table(self::SUBJECTS)->where('id', $subject->id)->update($metadata);
                    foreach ($metadata as $key => $value) {
                        $subject->$key = $value;
                    }
                    if (!$usable) {
                        $status = 'orphaned';
                        $reason = (bool) $user->banned ? 'ACCOUNT_BANNED' : 'SUBSCRIPTION_CHANGED';
                        $request = ['kind' => 'revoke', 'user_id' => (string) $user->id,
                            'access_subject_id' => (string) $subject->id,
                            'business_key' => 'orphan:'.$operation->operation_ref,
                            'expected_version' => (string) $subject->row_version, 'reason_code' => $reason];
                        $remediation = $this->recordRevoke($request, $subject, $registration, $reason, $now);
                    }
                } else {
                    $this->require($subject->status === 'revoked', 'SUBJECT_DAMAGED');
                    $subject->provider_revoked_at = max((int) ($subject->provider_revoked_at ?? 0), $result['provider_revoked_at']);
                    DB::table(self::SUBJECTS)->where('id', $subject->id)->update([
                        'provider_revoked_at' => $subject->provider_revoked_at, 'updated_at' => $now,
                    ]);
                }
                DB::table(self::OPERATIONS)->where('id', $operation->id)->update([
                    'status' => $status, 'error_code' => null, 'result_snapshot' => $this->json($result),
                    'result_hash' => $this->hash($result), 'remediation_operation_ref' => $remediation, 'updated_at' => $now,
                ]);
                $operation->status = $status;
                $operation->error_code = null;
                $operation->remediation_operation_ref = $remediation;
                return $this->publicOperation($operation, $subject);
            });
    }

    private function recoverRemediation(int $userId, array $public): array
    {
        if ($public['remediation_operation_ref'] !== null) {
            $this->drive($userId, $public['remediation_operation_ref'], true);
            return $this->withOperation($userId, $public['operation_ref'],
                fn ($operation, $subject): array => $this->publicOperation($operation, $subject));
        }
        return $public;
    }

    private function unknown(int $userId, string $reference, string $code): array
    {
        return $this->withOperation($userId, $reference, function ($operation, $subject) use ($code): array {
            if (!in_array($operation->status, ['succeeded', 'rejected', 'orphaned'], true)) {
                $operation->status = 'unknown';
                $operation->error_code = $this->diagnostic($code);
                DB::table(self::OPERATIONS)->where('id', $operation->id)->update([
                    'status' => $operation->status, 'error_code' => $operation->error_code, 'updated_at' => time(),
                ]);
            }
            return $this->publicOperation($operation, $subject);
        });
    }

    private function withOperation(int $userId, string $reference, callable $callback, bool $readOnly = false): array
    {
        $this->freshTransaction();
        $locator = DB::table(self::OPERATIONS)->useWritePdo()->where('operation_ref', $reference)
            ->where('user_id', $userId)->first(['id', 'subscription_id', 'access_subject_id']);
        $this->require($locator !== null, 'OPERATION_MISSING');
        return DB::transaction(function () use ($userId, $locator, $callback, $readOnly): array {
            $installation = $readOnly ? app(InstallationAuthority::class)->assertNativeRead()
                : app(InstallationAuthority::class)->assertNativeMutation();
            if ($readOnly) {
                DB::table('tx_user')->where('id', $userId)->lockForUpdate()->first(['id']);
                $this->require(DB::table('tx_subscription_slot')->where('user_id', $userId)
                    ->where('slot_no', 1)->sharedLock()->exists(), 'SUBJECT_INVALID');
            }
            [$user, $slot, $subscriptions] = $this->store->lockAccount($userId, time(), $installation['installation_id']);
            $subscription = $subscriptions->firstWhere('id', $locator->subscription_id);
            $this->require($subscription !== null, 'SUBSCRIPTION_MISSING');
            $history = $this->store->lockHistory($subscription);
            $subject = DB::table(self::SUBJECTS)->where('id', $locator->access_subject_id)->lockForUpdate()->first();
            $this->verifySubject($subject, $subscription, $installation['installation_id']);
            $registration = DB::table(self::REGISTRATIONS)->where('id', $subject->provider_registration_id)->lockForUpdate()->first();
            $this->verifyRegistration($registration, $installation['installation_id']);
            $this->require($subject->provider_id === $registration->provider_id, 'SUBJECT_INVALID');
            $operation = DB::table(self::OPERATIONS)->where('id', $locator->id)->lockForUpdate()->first();
            $this->verifyOperation($operation);
            $this->require((string) $operation->user_id === (string) $userId
                && (string) $operation->subscription_id === (string) $subscription->id
                && (string) $operation->access_subject_id === (string) $subject->id
                && (string) $operation->provider_registration_id === (string) $registration->id, 'OPERATION_DAMAGED');
            $wire = $this->decode($operation->wire_request);
            $this->require($wire['provider_id'] === $registration->provider_id
                && $wire['provider_instance_epoch'] === (string) $registration->provider_instance_epoch
                && $wire['input']['installation_id'] === $installation['installation_id']
                && $wire['input']['provider_subject_ref'] === $subject->provider_subject_ref, 'OPERATION_DAMAGED');
            $this->verifyReceiptBinding($operation, $subject, $wire);
            return $callback($operation, $subject, $registration, $subscription, $user, $slot, $history);
        }, 3);
    }

    private function recordRevoke(array $request, object $subject, object $registration, string $reason, int $now): string
    {
        $this->denySubject($subject, $now, true);
        $input = [
            'installation_id' => $subject->installation_id, 'access_subject_id' => (string) $subject->id,
            'provider_subject_ref' => $subject->provider_subject_ref,
            'revocation_fact_version' => (string) $subject->revocation_fact_version,
            'effective_at' => $now, 'reason_code' => $reason,
        ];
        return $this->record($request, $subject, $registration, $input, $now);
    }

    private function denySubject(object $subject, int $now, bool $newFact = false): void
    {
        if ($subject->status === 'revoked' && !$newFact) {
            return;
        }
        $this->require((int) $subject->row_version < PHP_INT_MAX && (int) $subject->auth_version < PHP_INT_MAX
            && (int) $subject->revocation_fact_version < PHP_INT_MAX, 'VERSION_OVERFLOW');
        $changes = ['status' => 'revoked', 'revoked_at' => $subject->revoked_at ?? $now,
            'revocation_fact_version' => (int) $subject->revocation_fact_version + 1,
            'auth_version' => (int) $subject->auth_version + 1,
            'row_version' => (int) $subject->row_version + 1, 'updated_at' => $now];
        DB::table(self::SUBJECTS)->where('id', $subject->id)->update($changes);
        foreach ($changes as $key => $value) {
            $subject->$key = $value;
        }
    }

    private function record(array $request, object $subject, object $registration, array $input, int $now): string
    {
        $reference = 'cp_'.bin2hex(random_bytes(16));
        $wire = $this->envelope($request['kind'], (array) $registration, $reference, $reference, $input);
        DB::table(self::OPERATIONS)->insert([
            'operation_ref' => $reference, 'user_id' => $request['user_id'], 'subscription_id' => $subject->subscription_id,
            'access_subject_id' => $subject->id, 'provider_registration_id' => $registration->id,
            'business_key' => $request['business_key'], 'kind' => $request['kind'], 'status' => 'pending',
            'request_hash' => $this->hash($request), 'request_snapshot' => $this->json($request),
            'wire_request' => $this->json($wire), 'wire_hash' => $this->hash($wire),
            'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $reference;
    }

    private function entitlement(object $user, object $slot, object $subscription, array $history, int $now): void
    {
        $this->require(in_array($user->banned, [true, false, 0, 1, '0', '1'], true) && !(bool) $user->banned
            && (string) $slot->subscription_id === (string) $subscription->id
            && $subscription->status === 'active' && $subscription->ended_at === null
            && (int) $subscription->starts_at <= $now
            && ($subscription->expires_at === null || (int) $subscription->expires_at > $now), 'ENTITLEMENT_DENIED');
        $current = $this->store->verifyHistory($subscription, $history);
        $period = $current['period'];
        $this->require($period !== null && (int) $period->starts_at <= $now
            && ($period->ends_at === null || (int) $period->ends_at > $now)
            && ($period->next_reset_at === null || (int) $period->next_reset_at > $now)
            && (int) $period->billable_bytes < (int) $period->quota_bytes, 'ENTITLEMENT_DENIED');
    }

    private function verifyRegistration(?object $row, string $installationId): void
    {
        $this->require($row !== null && $row->installation_id === $installationId, 'REGISTRATION_INVALID');
        $snapshot = [
            'installation_id' => $row->installation_id, 'provider_id' => $row->provider_id,
            'provider_instance_epoch' => (string) $row->provider_instance_epoch, 'config_digest' => $row->config_digest,
            'profiles' => $this->decode($row->profiles), 'business_key' => $row->business_key,
        ];
        $this->require(hash_equals($row->content_hash, $this->hash($snapshot)), 'REGISTRATION_INVALID');
    }

    private function verifySubject(?object $subject, object $subscription, string $installationId): void
    {
        $this->require($subject !== null && $subject->authority_version === self::SUBJECT
            && $subject->installation_id === $installationId
            && (string) $subject->subscription_id === (string) $subscription->id
            && in_array($subject->status, ['pending', 'active', 'revoked'], true)
            && (bool) preg_match('/\Asubject_[0-9a-f]{32}\z/D', $subject->provider_subject_ref)
            && $subject->provider_registration_id !== null && $subject->bound_terms_id !== null
            && $subject->bound_subscription_version !== null, 'SUBJECT_INVALID');
        foreach (['row_version', 'auth_version', 'bound_subscription_version', 'bound_terms_id'] as $field) {
            $this->decimal($subject->$field);
        }
        $this->require($subject->status !== 'revoked'
            || ((int) $subject->revocation_fact_version > 0 && $subject->revoked_at !== null), 'SUBJECT_INVALID');
    }

    private function verifyOperation(?object $operation): void
    {
        $this->require($operation !== null, 'OPERATION_DAMAGED');
        $request = $this->decode($operation->request_snapshot);
        $wire = $this->decode($operation->wire_request);
        $this->require(hash_equals($operation->request_hash, $this->hash($request))
            && hash_equals($operation->wire_hash, $this->hash($wire))
            && ($request['kind'] ?? null) === $operation->kind
            && ($request['user_id'] ?? null) === (string) $operation->user_id
            && ($request['business_key'] ?? null) === $operation->business_key
            && ($wire['operation_id'] ?? null) === $operation->operation_ref
            && ($wire['operation'] ?? null) === $operation->kind
            && ($wire['input']['access_subject_id'] ?? null) === (string) $operation->access_subject_id
            && ($wire['input_hash'] ?? null) === ProviderCodec::inputHash($wire['operation'], $wire['provider_id'],
                $wire['provider_instance_epoch'], $wire['input']), 'OPERATION_DAMAGED');
        if (in_array($operation->status, ['succeeded', 'orphaned'], true)) {
            $this->require(is_string($operation->result_snapshot) && is_string($operation->result_hash), 'OPERATION_DAMAGED');
            $result = $this->decode($operation->result_snapshot);
            $this->require(hash_equals($operation->result_hash, $this->hash($result)), 'OPERATION_DAMAGED');
            try {
                $this->verifyReceipt($wire, $result);
            } catch (Throwable) {
                throw new DomainException('NATIVE_CREDENTIAL_OPERATION_DAMAGED');
            }
        } else {
            $this->require(in_array($operation->status, ['pending', 'unknown', 'rejected'], true)
                && $operation->result_snapshot === null && $operation->result_hash === null, 'OPERATION_DAMAGED');
        }
    }

    private function verifyReceiptBinding(object $operation, object $subject, array $wire): void
    {
        $input = $wire['input'];
        if ($operation->kind === 'issue') {
            $request = $this->decode($operation->request_snapshot);
            $this->require($input['subscription_id'] === (string) $subject->subscription_id
                && $input['terms_id'] === (string) $subject->bound_terms_id
                && $input['credential_profile_id'] === $subject->credential_profile_id
                && ($request['expected_version'] ?? null) === (string) $subject->bound_subscription_version,
                'OPERATION_DAMAGED');
        }
        if (!in_array($operation->status, ['succeeded', 'orphaned'], true)) {
            return;
        }
        $result = $this->decode($operation->result_snapshot);
        if ($operation->kind === 'issue') {
            $this->require(in_array($subject->status, ['active', 'revoked'], true)
                && ($operation->status !== 'orphaned' || $subject->status === 'revoked'), 'OPERATION_DAMAGED');
            foreach (['credential_ref', 'credential_generation', 'issued_at', 'not_before_at', 'credential_expires_at'] as $field) {
                $this->require($subject->$field !== null && (string) $subject->$field === (string) $result[$field], 'OPERATION_DAMAGED');
            }
        } else {
            $this->require($operation->status === 'succeeded' && $subject->status === 'revoked'
                && (int) $subject->revocation_fact_version >= (int) $input['revocation_fact_version']
                && $subject->provider_revoked_at !== null
                && (int) $subject->provider_revoked_at === $result['provider_revoked_at'], 'OPERATION_DAMAGED');
        }
    }

    private function verifyReceipt(array $wire, array $result): void
    {
        $input = $wire['input'];
        $this->require(($result['access_subject_id'] ?? null) === $input['access_subject_id']
            && ($result['provider_subject_ref'] ?? null) === $input['provider_subject_ref'], 'RECEIPT_INVALID');
        if ($wire['operation'] === 'issue') {
            $keys = ['access_subject_id', 'provider_subject_ref', 'credential_profile_id', 'credential_ref',
                'credential_generation', 'issued_at', 'not_before_at', 'credential_expires_at'];
            $this->require(($result['credential_profile_id'] ?? null) === $input['credential_profile_id']
                && ($result['credential_generation'] ?? null) === '1'
                && is_string($result['credential_ref'] ?? null)
                && (bool) preg_match('/\Acredential_[0-9a-f]{32}\z/D', $result['credential_ref'])
                && is_int($result['issued_at'] ?? null) && $result['issued_at'] >= $input['not_before_at']
                && $result['issued_at'] < $input['credential_expires_at']
                && ($result['not_before_at'] ?? null) === $input['not_before_at']
                && ($result['credential_expires_at'] ?? null) === $input['credential_expires_at'], 'RECEIPT_INVALID');
        } else {
            $keys = ['access_subject_id', 'provider_subject_ref', 'accepted_revocation_fact_version', 'provider_revoked_at'];
            $accepted = $result['accepted_revocation_fact_version'] ?? null;
            $this->require(is_string($accepted) && (bool) preg_match('/\A[1-9][0-9]{0,18}\z/D', $accepted)
                && (strlen($accepted) < strlen((string) PHP_INT_MAX) || strcmp($accepted, (string) PHP_INT_MAX) <= 0)
                && (int) $accepted >= (int) $input['revocation_fact_version']
                && is_int($result['provider_revoked_at'] ?? null)
                && $result['provider_revoked_at'] >= 0, 'RECEIPT_INVALID');
        }
        $actual = array_keys($result);
        sort($actual);
        sort($keys);
        $this->require($actual === $keys, 'RECEIPT_INVALID');
    }

    private function envelope(string $kind, array $registration, string $reference, string $key, array $input): array
    {
        $wire = [
            'contract_version' => 'credential-provider/1', 'operation' => $kind,
            'request_id' => 'request_'.bin2hex(random_bytes(16)), 'operation_id' => $reference,
            'provider_id' => $registration['provider_id'],
            'provider_instance_epoch' => (string) $registration['provider_instance_epoch'],
            'idempotency_key' => $key, 'input_hash' => ProviderCodec::inputHash($kind, $registration['provider_id'],
                (string) $registration['provider_instance_epoch'], $input),
            'deadline_at' => time() + 25, 'input' => $input,
        ];
        ProviderCodec::validateRequest($wire);
        return $wire;
    }

    private function refreshEnvelope(array $wire): array
    {
        $wire['request_id'] = 'request_'.bin2hex(random_bytes(16));
        $wire['deadline_at'] = time() + 25;
        ProviderCodec::validateRequest($wire);
        return $wire;
    }

    private function publicOperation(object $operation, object $subject): array
    {
        return [
            'schema' => self::OPERATION, 'operation_ref' => $operation->operation_ref,
            'operation' => $operation->kind, 'status' => $operation->status, 'error_code' => $operation->error_code,
            'subject_id' => (string) $subject->id, 'subject_status' => $subject->status,
            'subject_version' => (string) $subject->row_version, 'provider_id' => $subject->provider_id,
            'credential_profile_id' => $subject->credential_profile_id, 'credential_ref' => $subject->credential_ref,
            'credential_generation' => $subject->credential_generation === null ? null : (string) $subject->credential_generation,
            'not_before_at' => $subject->not_before_at === null ? null : (int) $subject->not_before_at,
            'credential_expires_at' => $subject->credential_expires_at === null ? null : (int) $subject->credential_expires_at,
            'revocation_fact_version' => (string) $subject->revocation_fact_version,
            'provider_revoked_at' => $subject->provider_revoked_at === null ? null : (int) $subject->provider_revoked_at,
            'remediation_operation_ref' => $operation->remediation_operation_ref,
        ];
    }

    private function publicRegistration(object $row): array
    {
        return [
            'schema' => 'core-credential-provider-registration/v1', 'registration_id' => (string) $row->id,
            'installation_id' => $row->installation_id, 'provider_id' => $row->provider_id,
            'provider_instance_epoch' => (string) $row->provider_instance_epoch,
            'config_digest' => $row->config_digest, 'profiles' => $this->decode($row->profiles),
        ];
    }

    private function freshTransaction(): void
    {
        $this->require(DB::transactionLevel() === 0, 'OUTER_TRANSACTION_UNSUPPORTED');
    }

    private function decimal(int|string $value): string
    {
        try {
            return SubscriptionTermsTimeline::decimal($value, true);
        } catch (Throwable) {
            throw new DomainException('NATIVE_CREDENTIAL_INPUT_INVALID');
        }
    }

    private function key(string $value): string
    {
        $this->require((bool) preg_match('/\A[A-Za-z0-9._~:-]{1,128}\z/D', $value), 'BUSINESS_KEY_INVALID');
        return $value;
    }

    private function json(array $value): string
    {
        return ProviderCodec::canonical($value);
    }

    private function hash(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function decode(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException('NATIVE_CREDENTIAL_EVIDENCE_INVALID');
        }
        $this->require(is_array($decoded), 'EVIDENCE_INVALID');
        return $decoded;
    }

    private function diagnostic(string $code): string
    {
        return preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/D', $code) ? $code : 'PROVIDER_OUTCOME_UNKNOWN';
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new DomainException('NATIVE_CREDENTIAL_'.$code);
        }
    }
}
