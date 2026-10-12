<?php

namespace App\Domains\CredentialProviderFixture;

use App\Services\Subscription\SubscriptionTermsTimeline;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Durable local fixture operations. Receipt storage never activates/binds a Subject for authorization. */
final class FixtureProviderService
{
    public function __construct(private FixtureProcess $process, private SubscriptionTermsTimeline $timeline) {}

    public function register(string $providerId, string $epoch, array $operations = ['issue', 'query'], array $profiles = ['synthetic-v1']): string
    {
        $this->guard();
        FixtureCodec::provider($providerId); FixtureCodec::decimal($epoch);
        sort($operations); sort($profiles);
        if (!$operations || array_diff($operations, ['issue', 'query', 'revoke']) || count(array_unique($operations)) !== count($operations)
            || $profiles !== ['synthetic-v1']) { throw new DomainException('UNSUPPORTED_OPERATION'); }
        $identity = ['provider_id' => $providerId, 'instance_epoch' => $epoch, 'runtime_code' => 'synthetic-fixture-v1',
            'script_digest' => FixtureProcess::digest(), 'operation_grants' => FixtureCodec::json($operations),
            'profile_grants' => FixtureCodec::json($profiles)];
        // Atomic database uniqueness handles initial registration across process instances.
        DB::table('tx_credential_provider_shadow_registration')->insertOrIgnore($identity + ['enabled' => true, 'created_at' => time()]);
        return DB::transaction(function () use ($providerId, $identity) {
            $row = DB::table('tx_credential_provider_shadow_registration')->where('provider_id', $providerId)->lockForUpdate()->first();
            if (!$row) { throw new DomainException('RUNTIME_UNAVAILABLE'); }
            foreach ($identity as $field => $value) {
                if (in_array($field, ['operation_grants', 'profile_grants'], true)) {
                    if (json_decode($row->$field, true, flags: JSON_THROW_ON_ERROR) !== json_decode($value, true, flags: JSON_THROW_ON_ERROR)) {
                        throw new DomainException('PROVIDER_REGISTRATION_CONFLICT');
                    }
                } elseif ((string) $row->$field !== $value) { throw new DomainException('PROVIDER_REGISTRATION_CONFLICT'); }
            }
            return (string) $row->id;
        });
    }

    public function issue(FixtureScope $scope, string $businessKey, array $input, int $deadline): array
    {
        $this->guard(); $this->deadline($deadline);
        if (!preg_match('/^fixture_[a-z0-9_-]{1,96}$/D', $businessKey)
            || count($input) !== 4 || array_diff(array_keys($input), ['expected_subject_version', 'credential_profile_id', 'not_before_at', 'credential_expires_at'])) {
            throw new DomainException('PROVIDER_INPUT_INVALID');
        }
        FixtureCodec::decimal($input['expected_subject_version'] ?? null);
        FixtureCodec::time($input['not_before_at'] ?? null); FixtureCodec::time($input['credential_expires_at'] ?? null);
        if (($input['credential_profile_id'] ?? '') !== 'synthetic-v1') { throw new DomainException('UNSUPPORTED_PROFILE'); }
        $record = DB::transaction(function () use ($scope, $businessKey, $input) {
            $registration = $this->registration($scope->providerId, 'issue');
            $logical = $input + ['provider_id' => $scope->providerId, 'provider_instance_epoch' => (string) $registration->instance_epoch,
                'access_subject_id' => $scope->subjectId, 'actor_ref' => $scope->actorRef, 'authorization_ref' => $scope->authorizationRef];
            $hash = FixtureCodec::hash($logical);
            $existing = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)
                ->where('operation', 'issue')->where('idempotency_key', $businessKey)->lockForUpdate()->first();
            if ($existing) {
                $this->scope($scope, $existing);
                $stored = json_decode($existing->input_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if (!hash_equals($existing->input_hash, FixtureCodec::hash($stored))) { throw new DomainException('PROVIDER_OPERATION_DAMAGED'); }
                $storedLogical = array_diff_key($stored, ['subscription_id' => true, 'terms_id' => true]);
                if (!hash_equals($hash, FixtureCodec::hash($storedLogical))) { throw new DomainException('IDEMPOTENCY_CONFLICT'); }
                return ['operation' => $existing, 'registration' => $registration, 'new' => false];
            }
            FixtureLifecycleService::assertIssuable($registration, $scope->subjectId);
            if ($input['not_before_at'] < time() || $input['credential_expires_at'] <= $input['not_before_at']
                || $input['credential_expires_at'] > time() + 3600) { throw new DomainException('PROVIDER_INPUT_INVALID'); }
            // Follow the existing B3 subject -> subscription read order; no domain mutation here.
            $subject = DB::table('tx_access_subject')->where('id', $scope->subjectId)->sharedLock()->first();
            if (!$subject || $subject->provider_id !== $scope->providerId) { throw new DomainException('UNAUTHORIZED_SCOPE'); }
            if (!in_array($subject->status, ['pending', 'active'], true) || $subject->revoked_at !== null) { throw new DomainException('REVOKED'); }
            if ((string) $subject->auth_version !== $input['expected_subject_version']) { throw new DomainException('VERSION_CONFLICT'); }
            $sub = DB::table('tx_subscription')->where('id', $subject->subscription_id)->sharedLock()->first();
            if (!$sub || $sub->status !== 'active' || $sub->ended_at !== null
                || ($sub->expires_at !== null && $input['credential_expires_at'] > (int) $sub->expires_at)) {
                throw new DomainException('UNAUTHORIZED_SCOPE');
            }
            $terms = $this->timeline->at((string) $sub->id, time(), true);
            $this->issuanceTerms($terms, $sub, $input);
            if (DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)
                ->where('access_subject_id', $scope->subjectId)->where('operation', 'issue')->sharedLock()->exists()) {
                throw new DomainException('SUBJECT_BINDING_CONFLICT');
            }
            $snapshot = $logical + ['subscription_id' => (string) $sub->id, 'terms_id' => (string) $sub->current_terms_id];
            $id = DB::table('tx_credential_provider_shadow_operation')->insertGetId([
                'registration_id' => $registration->id, 'instance_epoch' => $registration->instance_epoch,
                'access_subject_id' => $scope->subjectId, 'operation' => 'issue', 'operation_ref' => 'op_'.bin2hex(random_bytes(16)),
                'idempotency_key' => $businessKey, 'input_hash' => FixtureCodec::hash($snapshot), 'input_snapshot' => FixtureCodec::json($snapshot),
                'status' => 'recorded', 'created_at' => time(), 'updated_at' => time(),
            ]);
            return ['operation' => DB::table('tx_credential_provider_shadow_operation')->where('id', $id)->lockForUpdate()->first(),
                'registration' => $registration, 'new' => true];
        }, 5);
        if ($record['operation']->status === 'succeeded' || $record['operation']->status === 'rejected') {
            return $this->receipt($record['operation']);
        }
        // Only the transaction that created the durable operation may send issue.
        return $this->exchange($scope, $record['registration'], $record['operation'], $record['new'] ? 'issue' : 'query', $deadline);
    }

    public function revoke(FixtureScope $scope, string $businessKey, array $input, int $deadline): array
    {
        $this->guard(); $this->deadline($deadline); FixtureLifecycleService::businessKey($businessKey);
        if (count($input) !== 2 || array_diff(array_keys($input), ['expected_subject_version', 'reason_code'])
            || !in_array($input['reason_code'] ?? '', ['operator_revoke', 'provider_disable'], true)) { throw new DomainException('PROVIDER_INPUT_INVALID'); }
        FixtureCodec::decimal($input['expected_subject_version'] ?? null);
        $record = DB::transaction(function () use ($scope, $businessKey, $input) {
            // A granted Core fact can fence issuance even when the registered executable is unavailable.
            $registration = FixtureRegistration::lock($scope->providerId, 'revoke', false);
            $logical = $input + ['provider_id' => $scope->providerId, 'provider_instance_epoch' => (string) $registration->instance_epoch,
                'access_subject_id' => $scope->subjectId, 'actor_ref' => $scope->actorRef, 'authorization_ref' => $scope->authorizationRef];
            $existing = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)
                ->where('operation', 'revoke')->where('idempotency_key', $businessKey)->lockForUpdate()->first();
            if ($existing) {
                $this->scope($scope, $existing); FixtureLifecycleService::assertFact($existing);
                $stored = json_decode($existing->input_snapshot, true, flags: JSON_THROW_ON_ERROR);
                $storedLogical = array_diff_key($stored, ['revocation_fact_version' => true, 'effective_at' => true, 'revocation_fact_domain' => true, 'known_issue' => true]);
                if (!hash_equals(FixtureCodec::hash($logical), FixtureCodec::hash($storedLogical))) { throw new DomainException('IDEMPOTENCY_CONFLICT'); }
                return ['registration' => $registration, 'operation' => $existing, 'new' => false];
            }
            if (DB::table('tx_credential_provider_shadow_revocation')->where('registration_id', $registration->id)
                ->where('access_subject_id', $scope->subjectId)->sharedLock()->exists()) { throw new DomainException('REVOKED'); }
            $subject = DB::table('tx_access_subject')->where('id', $scope->subjectId)->sharedLock()->first();
            if (!$subject || $subject->provider_id !== $scope->providerId) { throw new DomainException('UNAUTHORIZED_SCOPE'); }
            if ((string) $subject->auth_version !== $input['expected_subject_version']) { throw new DomainException('VERSION_CONFLICT'); }
            $knownIssue = null;
            $issue = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)
                ->where('access_subject_id', $scope->subjectId)->where('operation', 'issue')->sharedLock()->first();
            if ($issue && $issue->status === 'succeeded') {
                $result = $this->receipt($issue)['result'];
                if (!is_array($result) || !is_string($result['credential_ref'] ?? null)
                    || !preg_match('/^cr_[a-f0-9]{32}$/D', $result['credential_ref'])) { throw new DomainException('PROVIDER_OPERATION_DAMAGED'); }
                $knownIssue = ['operation_ref' => $issue->operation_ref, 'credential_ref' => $result['credential_ref']];
            }
            $snapshot = $logical + ['revocation_fact_domain' => 'provider-subject/v1', 'known_issue' => $knownIssue,
                'revocation_fact_version' => FixtureLifecycleService::allocateFact($registration), 'effective_at' => time()];
            $hash = FixtureCodec::hash($snapshot);
            $id = DB::table('tx_credential_provider_shadow_operation')->insertGetId([
                'registration_id' => $registration->id, 'instance_epoch' => $registration->instance_epoch,
                'access_subject_id' => $scope->subjectId, 'operation' => 'revoke', 'operation_ref' => 'op_'.bin2hex(random_bytes(16)),
                'idempotency_key' => $businessKey, 'input_hash' => $hash, 'input_snapshot' => FixtureCodec::json($snapshot),
                'status' => 'recorded', 'created_at' => time(), 'updated_at' => time(),
            ]);
            DB::table('tx_credential_provider_shadow_revocation')->insert([
                'registration_id' => $registration->id, 'instance_epoch' => $registration->instance_epoch, 'access_subject_id' => $scope->subjectId,
                'operation_id' => $id, 'fact_version' => $snapshot['revocation_fact_version'],
                'expected_subject_version' => $input['expected_subject_version'], 'reason_code' => $input['reason_code'],
                'effective_at' => $snapshot['effective_at'], 'content_hash' => $hash, 'created_at' => time(),
            ]);
            return ['registration' => $registration,
                'operation' => DB::table('tx_credential_provider_shadow_operation')->where('id', $id)->lockForUpdate()->first(), 'new' => true];
        }, 5);
        if (in_array($record['operation']->status, ['succeeded', 'rejected'], true)) { return $this->receipt($record['operation']); }
        return $this->exchange($scope, $record['registration'], $record['operation'], $record['new'] ? 'revoke' : 'query', $deadline);
    }

    public function query(FixtureScope $scope, string $operationRef, int $deadline): array
    {
        $this->guard(); $this->deadline($deadline);
        if (!preg_match('/^op_[a-f0-9]{32}$/D', $operationRef)) { throw new DomainException('PROVIDER_INPUT_INVALID'); }
        $record = DB::transaction(function () use ($scope, $operationRef) {
            $registration = $this->registration($scope->providerId, 'query');
            $operation = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)
                ->where('operation_ref', $operationRef)->lockForUpdate()->first();
            if (!$operation) { throw new DomainException('UNAUTHORIZED_SCOPE'); }
            if ((string) $operation->instance_epoch !== (string) $registration->instance_epoch) { throw new DomainException('PROVIDER_REGISTRATION_CONFLICT'); }
            $this->scope($scope, $operation);
            if ($operation->operation === 'revoke') { FixtureLifecycleService::assertFact($operation); }
            return ['registration' => $registration, 'operation' => $operation];
        });
        if (in_array($record['operation']->status, ['succeeded', 'rejected'], true)) { return $this->receipt($record['operation']); }
        return $this->exchange($scope, $record['registration'], $record['operation'], 'query', $deadline);
    }

    public function unsupported(string $operation): never
    {
        $this->guard();
        throw new DomainException('UNSUPPORTED_OPERATION');
    }

    private function exchange(FixtureScope $scope, object $registration, object $operation, string $kind, int $deadline): array
    {
        try {
            // The prior recording transaction has committed. Recheck current grants/epoch and
            // retain their row lock through bounded IPC, serializing local grant revocation.
            $next = DB::transaction(function () use ($scope, $registration, $operation, $kind, $deadline) {
                $current = $this->registration($scope->providerId, $kind);
                if ((string) $current->id !== (string) $registration->id
                    || (string) $current->instance_epoch !== (string) $operation->instance_epoch) {
                    throw new DomainException('PROVIDER_REGISTRATION_CONFLICT');
                }
                if ($kind === 'issue') { FixtureLifecycleService::assertIssuable($current, $scope->subjectId); }
                $row = DB::table('tx_credential_provider_shadow_operation')->where('id', $operation->id)->lockForUpdate()->first();
                $this->scope($scope, $row);
                if (in_array($row->status, ['succeeded', 'rejected'], true)) { return $row; }
                $input = json_decode($row->input_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if (!hash_equals($row->input_hash, FixtureCodec::hash($input))) { throw new DomainException('PROVIDER_OPERATION_DAMAGED'); }
                if ($row->operation === 'revoke') { FixtureLifecycleService::assertFact($row); }
                if ($kind === 'issue') {
                    $subject = DB::table('tx_access_subject')->where('id', $scope->subjectId)->sharedLock()->first();
                    if (!$subject || $subject->provider_id !== $scope->providerId || (string) $subject->subscription_id !== $input['subscription_id']) {
                        throw new DomainException('UNAUTHORIZED_SCOPE');
                    }
                    if ($subject->status === 'revoked' || $subject->revoked_at !== null) { throw new DomainException('REVOKED'); }
                    if ((string) $subject->auth_version !== $input['expected_subject_version']) { throw new DomainException('VERSION_CONFLICT'); }
                    $sub = DB::table('tx_subscription')->where('id', $subject->subscription_id)->sharedLock()->first();
                    if (!$sub || $sub->status !== 'active' || $sub->ended_at !== null || (string) $sub->current_terms_id !== $input['terms_id']
                        || ($sub->expires_at !== null && $input['credential_expires_at'] > (int) $sub->expires_at)) {
                        throw new DomainException('UNAUTHORIZED_SCOPE');
                    }
                    $this->issuanceTerms($this->timeline->at((string) $sub->id, time(), true), $sub, $input);
                }
                $request = ['contract_version' => FixtureCodec::VERSION, 'operation' => $kind, 'request_id' => 'rq_'.bin2hex(random_bytes(16)),
                    'operation_id' => $row->operation_ref, 'idempotency_key' => $row->idempotency_key,
                    'provider_id' => $scope->providerId, 'provider_instance_epoch' => (string) $current->instance_epoch,
                    'deadline_at' => $deadline, 'input_hash' => $row->input_hash, 'input' => $input];
                $response = $this->process->call($request);
                $result = $this->validateResponse($request, $response, $row->operation);
                DB::table('tx_credential_provider_shadow_operation')->where('id', $row->id)->update([
                    'status' => $response['status'] === 'pending' ? 'unknown' : $response['status'],
                    'result_snapshot' => $result === null ? null : FixtureCodec::json($result),
                    'result_hash' => $result === null ? null : FixtureCodec::hash($result),
                    'last_error_code' => $response['error_code'], 'updated_at' => time(),
                ]);
                return DB::table('tx_credential_provider_shadow_operation')->where('id', $row->id)->lockForUpdate()->first();
            });
        } catch (\Throwable $error) {
            if ($error instanceof DomainException && in_array($error->getMessage(),
                ['UNSUPPORTED_OPERATION', 'UNSUPPORTED_PROFILE', 'PROVIDER_DISABLED', 'PROVIDER_REGISTRATION_CONFLICT', 'UNAUTHORIZED_SCOPE', 'PROVIDER_OPERATION_DAMAGED', 'REVOKED', 'VERSION_CONFLICT'], true)) {
                throw $error;
            }
            $known = ['RUNTIME_UNAVAILABLE', 'RUNTIME_UNKNOWN', 'DEADLINE_EXCEEDED', 'PROVIDER_RESPONSE_LIMIT', 'PROVIDER_INVALID_RESPONSE'];
            $code = $error instanceof DomainException && in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'CORE_FINALIZATION_UNKNOWN';
            $next = DB::transaction(function () use ($operation, $code) {
                $row = DB::table('tx_credential_provider_shadow_operation')->where('id', $operation->id)->lockForUpdate()->first();
                if (!in_array($row->status, ['succeeded', 'rejected'], true)) {
                    DB::table('tx_credential_provider_shadow_operation')->where('id', $row->id)->update([
                        'status' => 'unknown', 'last_error_code' => $code, 'updated_at' => time(),
                    ]);
                }
                return DB::table('tx_credential_provider_shadow_operation')->where('id', $row->id)->lockForUpdate()->first();
            });
        }
        return $this->receipt($next);
    }

    private function issuanceTerms(?array $terms, object $subscription, array $input): void
    {
        if (!$terms || $terms['terms_id'] !== (string) $subscription->current_terms_id
            || $input['credential_expires_at'] <= time() || $input['not_before_at'] < $terms['effective_from']
            || ($terms['effective_until'] !== null && $input['credential_expires_at'] > $terms['effective_until'])) {
            throw new DomainException('UNAUTHORIZED_SCOPE');
        }
    }

    private function validateResponse(array $request, array $response, string $originalOperation): ?array
    {
        $keys = ['contract_version', 'request_id', 'operation_id', 'provider_id', 'provider_instance_epoch', 'input_hash', 'status', 'result', 'error_code'];
        if (count($response) !== count($keys) || array_diff(array_keys($response), $keys)) { throw new DomainException('PROVIDER_INVALID_RESPONSE'); }
        foreach (['contract_version', 'request_id', 'operation_id', 'provider_id', 'provider_instance_epoch', 'input_hash'] as $field) {
            if ($response[$field] !== $request[$field]) { throw new DomainException('PROVIDER_INVALID_RESPONSE'); }
        }
        if (!in_array($response['status'], ['succeeded', 'pending', 'rejected'], true)) { throw new DomainException('PROVIDER_INVALID_RESPONSE'); }
        if ($response['status'] !== 'succeeded') {
            if ($response['result'] !== null || !in_array($response['error_code'], ['OPERATION_UNKNOWN', 'IDEMPOTENCY_CONFLICT', 'UNSUPPORTED_PROFILE', 'SUBJECT_BINDING_CONFLICT', 'PROVIDER_PRIVATE_LIMIT', 'REVOKED', 'REVOCATION_VERSION_CONFLICT'], true)) {
                throw new DomainException('PROVIDER_INVALID_RESPONSE');
            }
            return null;
        }
        $result = $response['result'];
        if ($originalOperation === 'revoke') {
            if ($response['error_code'] !== null) { throw new DomainException('PROVIDER_INVALID_RESPONSE'); }
            return FixtureLifecycleService::revokeResult($request, $result);
        }
        $keys = ['credential_ref', 'provider_subject_ref', 'credential_generation', 'issued_at', 'credential_expires_at'];
        if ($response['error_code'] !== null || !is_array($result) || count($result) !== 5 || array_diff(array_keys($result), $keys)
            || !is_string($result['credential_ref'] ?? null) || !preg_match('/^cr_[a-f0-9]{32}$/D', $result['credential_ref'])
            || !is_string($result['provider_subject_ref'] ?? null) || !preg_match('/^ps_[a-f0-9]{32}$/D', $result['provider_subject_ref'])
            || $result['credential_generation'] !== '1' || !is_int($result['issued_at']) || $result['issued_at'] < 0 || $result['issued_at'] > time() + 5
            || $result['credential_expires_at'] !== $request['input']['credential_expires_at']) {
            throw new DomainException('PROVIDER_INVALID_RESPONSE');
        }
        return $result;
    }

    private function receipt(object $row): array
    {
        $result = $row->result_snapshot === null ? null : json_decode($row->result_snapshot, true, flags: JSON_THROW_ON_ERROR);
        if ($result !== null && !hash_equals($row->result_hash ?? '', FixtureCodec::hash($result))) {
            throw new DomainException('PROVIDER_OPERATION_DAMAGED');
        }
        $receipt = ['mode' => 'local_synthetic_fixture', 'operation_ref' => $row->operation_ref,
            'status' => $row->status === 'recorded' ? 'unknown' : $row->status, 'result' => $result, 'error_code' => $row->last_error_code];
        if ($row->operation === 'revoke') { $receipt['node_convergence'] = 'not_implemented'; }
        return $receipt;
    }

    private function registration(string $provider, string $operation): object
    {
        return FixtureRegistration::lock($provider, $operation);
    }

    private function scope(FixtureScope $scope, object $operation): void
    {
        if ((string) $operation->access_subject_id !== $scope->subjectId) { throw new DomainException('UNAUTHORIZED_SCOPE'); }
        $input = json_decode($operation->input_snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals($operation->input_hash, FixtureCodec::hash($input))) { throw new DomainException('PROVIDER_OPERATION_DAMAGED'); }
        if ($input['provider_id'] !== $scope->providerId || $input['actor_ref'] !== $scope->actorRef
            || $input['authorization_ref'] !== $scope->authorizationRef) { throw new DomainException('UNAUTHORIZED_SCOPE'); }
    }

    private function deadline(int $deadline): void
    {
        FixtureCodec::time($deadline);
        if ($deadline <= time() || $deadline > time() + 5) { throw new DomainException('DEADLINE_EXCEEDED'); }
    }

    private function guard(): void
    {
        if (!config('credential_provider_fixture.enabled') || !config('core_shadow.isolated_copy') || !app()->environment('local', 'testing')) {
            throw new DomainException('CAPABILITY_UNAVAILABLE');
        }
        if (DB::transactionLevel() > 0) { throw new DomainException('PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); }
    }
}
