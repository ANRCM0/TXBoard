<?php

namespace App\Domains\UsageV2;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Domains\Installation\NativeRuntimeReadiness;
use App\Models\Server;
use App\Services\Subscription\NativeSubscriptionStore;
use Illuminate\Support\Facades\DB;

final class NativeUsageLedger
{
    public static function enabled(): bool
    {
        return (app(InstallationAuthority::class)->inspect()['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION;
    }

    /** Caller owns installation, account/history/Subject and Node locks in that order. */
    public function issueLocked(Server $node, array $rows, int $now, int $expires): array
    {
        $installation = app(InstallationAuthority::class)->assertNativeMutation();
        NativeRuntimeReadiness::assertSupportedNode($node);
        $this->require($expires > $now && $expires <= $now + 120, 'NATIVE_GRANT_WINDOW');
        $instance = $installation['installation_id'];
        $source = DB::table('tx_node_usage_source')->where('node_id', $node->id)->orderBy('source_epoch')->lockForUpdate()->get();
        $this->require($source->count() <= 1, 'NATIVE_SOURCE_AMBIGUOUS');
        $source = $source->first();
        if ($source === null) {
            $id = DB::table('tx_node_usage_source')->insertGetId(['node_id' => $node->id, 'source_epoch' => 1,
                'installation_ref' => 'native:'.$instance, 'core_instance_id' => $instance,
                'receipt_generation' => '1', 'current' => true, 'created_at' => $now]);
            $source = DB::table('tx_node_usage_source')->where('id', $id)->first();
        }
        $this->require($source->installation_ref === 'native:'.$instance && $source->core_instance_id === $instance
            && (string) $source->receipt_generation === '1' && (string) $source->source_epoch === '1'
            && (bool) $source->current, 'NATIVE_SOURCE_INVALID');
        [$whole, $fraction] = array_pad(explode('.', (string) $node->getRawOriginal('rate'), 2), 2, '');
        $numerator = (string) ((int) $whole * 100 + (int) str_pad($fraction, 2, '0'));
        $tariff = DB::table('tx_tariff_revision')->where('node_id', $node->id)->where('numerator', $numerator)
            ->where('denominator', '100')->where('effective_from', '<=', $now)->where('effective_until', '>=', $expires)
            ->orderByDesc('id')->lockForUpdate()->first();
        if (!$tariff) {
            $data = ['node_id' => (int) $node->id, 'numerator' => $numerator, 'denominator' => '100',
                'effective_from' => $now, 'effective_until' => 9007199254740991, 'rounding_revision' => 'floor-per-direction-v1'];
            $id = DB::table('tx_tariff_revision')->insertGetId($data + ['content_hash' => UsageRegistry::hash($data), 'created_at' => $now]);
            $tariff = DB::table('tx_tariff_revision')->where('id', $id)->first();
        }
        self::verifyTariff($tariff);
        $grants = [];
        foreach ($rows as $row) {
            $e = $row['entitlement']; $input = $row['input'];
            $subject = DB::table('tx_access_subject')->where('id', $input['access_subject_id'])->sharedLock()->first();
            $operation = DB::table('tx_credential_provider_operation')->where('operation_ref', $subject->current_credential_operation_ref)->sharedLock()->first();
            $this->require($operation !== null && $operation->status === 'succeeded' && is_string($operation->result_hash), 'NATIVE_CREDENTIAL_EVIDENCE');
            $data = ['source_id' => (int) $source->id, 'user_id' => (int) $e['user_id'],
                'access_subject_id' => (int) $input['access_subject_id'], 'subscription_id' => $e['subscription_id'],
                'terms_id' => (int) $e['terms_id'], 'usage_period_id' => (int) $e['usage_period_id'],
                'tariff_revision_id' => (int) $tariff->id, 'auth_version' => (string) $subject->auth_version,
                'revocation_version' => (string) $subject->revocation_fact_version,
                'issued_at' => $now, 'lease_expires_at' => $expires];
            $id = DB::table('tx_auth_snapshot')->insertGetId($data + ['content_hash' => UsageRegistry::hash($data), 'created_at' => $now]);
            $subscription = DB::table('tx_subscription')->where('id', $e['subscription_id'])->sharedLock()->first();
            $period = DB::table('tx_usage_period')->where('id', $e['usage_period_id'])->sharedLock()->first();
            $coverage = app(UsageTermsBoundary::class)->freeze($e['subscription_id'], $e['terms_id'], $period, $now, $expires)
                + ['subscription_started_at' => (int) $subscription->starts_at,
                    'subscription_expires_at' => $subscription->expires_at === null ? null : (int) $subscription->expires_at,
                    'period_started_at' => (int) $period->starts_at,
                    'period_ended_at' => $period->ends_at === null ? null : (int) $period->ends_at];
            $binding = ['grant' => $data, 'installation_id' => $instance, 'credential' => $input,
                'credential_expires_at' => $row['credential_expires_at'], 'operation_ref' => $operation->operation_ref,
                'receipt_hash' => $operation->result_hash, 'coverage' => $coverage];
            DB::table('tx_native_usage_grant')->insert(['grant_id' => $id, 'installation_id' => $instance,
                'binding_snapshot' => json_encode($binding, JSON_THROW_ON_ERROR), 'binding_hash' => UsageRegistry::hash($binding),
                'created_at' => $now]);
            $grants[] = ['user_id' => $e['user_id'], 'access_subject_id' => $input['access_subject_id'],
                'auth_snapshot_id' => (string) $id, 'tariff_revision_id' => (string) $tariff->id,
                'terms_id' => $e['terms_id'], 'usage_period_id' => $e['usage_period_id'],
                'issued_at' => $now, 'lease_expires_at' => $expires, 'revocation_version' => (string) $subject->revocation_fact_version];
        }
        return ['protocol_version' => 2, 'core_instance_id' => $instance, 'node_id' => (string) $node->id,
            'source_epoch' => '1', 'receipt_generation' => '1',
            'retention_seconds' => (int) config('core_usage_v2.retention_seconds'),
            'max_late_report_seconds' => (int) config('core_usage_v2.max_late_report_seconds'),
            'receipt_retention_seconds' => (int) config('core_usage_v2.receipt_retention_seconds'),
            'issued_at' => $now, 'grants' => $grants];
    }

    public function bindEnvelopeLocked(array $session, array $envelope): void
    {
        $bytes = base64_decode(strtr($envelope['payload'], '-_', '+/'), true);
        $digest = hash('sha256', $bytes);
        foreach ($session['grants'] as $grant) {
            $row = DB::table('tx_native_usage_grant')->where('grant_id', $grant['auth_snapshot_id'])->lockForUpdate()->first();
            $binding = json_decode($row->binding_snapshot, true, 32, JSON_THROW_ON_ERROR);
            $binding['authorization_digest'] = $digest;
            DB::table('tx_native_usage_grant')->where('grant_id', $row->grant_id)->update([
                'authorization_digest' => $digest, 'binding_snapshot' => json_encode($binding, JSON_THROW_ON_ERROR),
                'binding_hash' => UsageRegistry::hash($binding)]);
        }
    }

    public function grantLocators(array $entries): array
    {
        $ids = array_values(array_unique(array_column($entries, 'auth_snapshot_id')));
        $this->require(count($ids) > 0 && count($ids) <= 10000, 'NATIVE_GRANT_LIMIT');
        $rows = DB::table('tx_auth_snapshot')->useWritePdo()->whereIn('id', $ids)->orderBy('user_id')->orderBy('id')
            ->get(['id', 'user_id', 'subscription_id', 'access_subject_id'])->all();
        $this->require(count($rows) === count($ids), 'NATIVE_GRANT_MISSING');
        return $rows;
    }

    /** Locator values are rechecked against the locked immutable grants before acceptance. */
    public function lockOwners(array $locators): void
    {
        $installation = app(InstallationAuthority::class)->assertNativeMutation();
        $users = array_values(array_unique(array_map(static fn ($row): int => (int) $row->user_id, $locators)));
        sort($users, SORT_NUMERIC);
        foreach ($users as $userId) {
            $this->require(DB::table('tx_user')->where('id', $userId)->sharedLock()->exists(), 'NATIVE_USER_MISSING');
        }
        $store = app(NativeSubscriptionStore::class);
        foreach ($users as $userId) {
            DB::table('tx_subscription_slot')->where('user_id', $userId)->orderBy('slot_no')->sharedLock()->get();
            $subscriptions = DB::table('tx_subscription')->forceIndex('tx_sub_user_id_idx')->where('user_id', $userId)
                ->orderBy('id')->limit(1001)->lockForUpdate()->get();
            $this->require($subscriptions->count() <= 1000, 'NATIVE_HISTORY_LIMIT');
            foreach ($subscriptions as $subscription) {
                $store->assertOwnership($subscription, $userId, $installation['installation_id']);
                $store->verifyHistory($subscription, $store->lockHistory($subscription));
            }
        }
        $subjects = array_values(array_unique(array_map(static fn ($row) => (int) $row->access_subject_id, $locators)));
        sort($subjects, SORT_NUMERIC);
        // Receipts update the Subject after locking the source; take the write lock before that order boundary.
        foreach ($subjects as $subjectId) { DB::table('tx_access_subject')->where('id', $subjectId)->lockForUpdate()->first(); }
    }

    public function verifyNativeGrant(object $grant): array
    {
        self::verifyGrant($grant);
        $proof = DB::table('tx_native_usage_grant')->where('grant_id', $grant->id)->sharedLock()->first();
        $installation = app(InstallationAuthority::class)->assertNativeRead();
        $this->require($proof !== null && $proof->installation_id === $installation['installation_id']
            && is_string($proof->authorization_digest) && preg_match('/\A[0-9a-f]{64}\z/D', $proof->authorization_digest) === 1,
            'NATIVE_GRANT_PROOF_MISSING');
        $binding = json_decode($proof->binding_snapshot, true, 32, JSON_THROW_ON_ERROR);
        $this->require(is_array($binding) && hash_equals($proof->binding_hash, UsageRegistry::hash($binding))
            && ($binding['authorization_digest'] ?? null) === $proof->authorization_digest
            && ($binding['installation_id'] ?? null) === $installation['installation_id']
            && UsageRegistry::hash($binding['grant'] ?? []) === $grant->content_hash, 'NATIVE_GRANT_PROOF_INVALID');
        $operation = DB::table('tx_credential_provider_operation')->where('operation_ref', $binding['operation_ref'])->first();
        $this->require($operation !== null && $operation->status === 'succeeded'
            && (string) $operation->access_subject_id === (string) $grant->access_subject_id
            && (string) $operation->subscription_id === (string) $grant->subscription_id
            && (string) $operation->user_id === (string) $grant->user_id
            && $operation->result_hash === $binding['receipt_hash'], 'NATIVE_GRANT_CREDENTIAL_INVALID');
        $receipt = json_decode($operation->result_snapshot, true, 32, JSON_THROW_ON_ERROR);
        $this->require(hash_equals($operation->result_hash, UsageRegistry::hash($receipt)), 'NATIVE_GRANT_CREDENTIAL_INVALID');
        foreach (['access_subject_id', 'credential_ref', 'credential_generation', 'provider_subject_ref', 'credential_profile_id'] as $field) {
            $this->require(($receipt[$field] ?? null) === ($binding['credential'][$field] ?? null), 'NATIVE_GRANT_CREDENTIAL_INVALID');
        }
        $this->require(($receipt['credential_expires_at'] ?? null) === ($binding['credential_expires_at'] ?? null)
            && (int) $grant->lease_expires_at <= $binding['credential_expires_at'], 'NATIVE_GRANT_CREDENTIAL_INVALID');
        $coverage = $binding['coverage'] ?? null;
        $this->require(is_array($coverage), 'NATIVE_GRANT_PROOF_INVALID');
        foreach ([['subscription_started_at', 'subscription_expires_at'],
            ['period_started_at', 'period_ended_at'], ['terms_effective_from', 'terms_effective_until']] as [$from, $until]) {
            $this->require(isset($coverage[$from]) && array_key_exists($until, $coverage)
                && is_int($coverage[$from]) && ($coverage[$until] === null || is_int($coverage[$until]))
                && UsageRegistry::covers((int) $grant->issued_at, (int) $grant->lease_expires_at, $coverage[$from], $coverage[$until]),
                'NATIVE_GRANT_PROOF_INVALID');
        }
        return $binding;
    }

    public static function verifyGrant(object $grant): void
    {
        $data = [];
        foreach (['source_id', 'user_id', 'access_subject_id', 'terms_id', 'usage_period_id', 'tariff_revision_id', 'issued_at', 'lease_expires_at'] as $field) {
            $data[$field] = (int) $grant->$field;
        }
        foreach (['subscription_id', 'auth_version', 'revocation_version'] as $field) { $data[$field] = (string) $grant->$field; }
        if (!hash_equals($grant->content_hash, UsageRegistry::hash($data))) { throw new UsageError('USAGE_GRANT_EVIDENCE_INVALID'); }
    }

    public static function verifyTariff(object $tariff): void
    {
        $data = ['node_id' => (int) $tariff->node_id, 'numerator' => (string) $tariff->numerator,
            'denominator' => (string) $tariff->denominator, 'effective_from' => (int) $tariff->effective_from,
            'effective_until' => (int) $tariff->effective_until, 'rounding_revision' => $tariff->rounding_revision];
        if (!hash_equals($tariff->content_hash, UsageRegistry::hash($data))) { throw new UsageError('USAGE_TARIFF_EVIDENCE_INVALID'); }
    }

    private function require(bool $valid, string $code): void
    {
        if (!$valid) { throw new UsageError('USAGE_'.$code); }
    }
}
