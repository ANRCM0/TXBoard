<?php

namespace App\Domains\CredentialProviderFixture;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Local shadow fencing/cleanup only; never changes a live Subject or claims Node convergence. */
final class FixtureLifecycleService
{
    public static function assertIssuable(object $registration, string $subjectId): void
    {
        $lifecycle = DB::table('tx_credential_provider_shadow_lifecycle')->where('registration_id', $registration->id)->sharedLock()->first();
        if (!$registration->enabled || ($lifecycle && $lifecycle->state !== 'enabled')) { throw new DomainException('PROVIDER_DISABLED'); }
        if (DB::table('tx_credential_provider_shadow_revocation')->where('registration_id', $registration->id)
            ->where('access_subject_id', $subjectId)->sharedLock()->exists()) { throw new DomainException('REVOKED'); }
    }

    /** Called only while the actual registration is locked by the recording transaction. */
    public static function allocateFact(object $registration): string
    {
        $lifecycle = self::lockedState($registration);
        $version = self::increment((string) $lifecycle->last_fact_version);
        DB::table('tx_credential_provider_shadow_lifecycle')->where('id', $lifecycle->id)->update([
            'last_fact_version' => $version, 'version_no' => self::increment((string) $lifecycle->version_no), 'updated_at' => time(),
        ]);
        return $version;
    }

    public static function assertFact(object $operation): void
    {
        $input = json_decode($operation->input_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $fact = DB::table('tx_credential_provider_shadow_revocation')->where('operation_id', $operation->id)->sharedLock()->first();
        if (!$fact || $operation->operation !== 'revoke' || !hash_equals($operation->input_hash, FixtureCodec::hash($input))
            || !hash_equals($fact->content_hash, $operation->input_hash)
            || (string) $fact->registration_id !== (string) $operation->registration_id
            || (string) $fact->instance_epoch !== (string) $operation->instance_epoch
            || (string) $fact->access_subject_id !== (string) $operation->access_subject_id
            || ($input['revocation_fact_domain'] ?? '') !== 'provider-subject/v1'
            || (string) $fact->fact_version !== ($input['revocation_fact_version'] ?? null)
            || (string) $fact->expected_subject_version !== ($input['expected_subject_version'] ?? null)
            || $fact->reason_code !== ($input['reason_code'] ?? null)
            || (int) $fact->effective_at !== ($input['effective_at'] ?? null)) {
            throw new DomainException('PROVIDER_OPERATION_DAMAGED');
        }
    }

    public static function revokeResult(array $request, mixed $result): array
    {
        $keys = ['access_subject_id', 'accepted_revocation_fact_version', 'provider_revoked_at',
            'affected_subject_count', 'removed_secret_count', 'private_cleanup_complete'];
        if (!is_array($result) || count($result) !== count($keys) || array_diff(array_keys($result), $keys)
            || $result['access_subject_id'] !== $request['input']['access_subject_id']
            || $result['accepted_revocation_fact_version'] !== $request['input']['revocation_fact_version']
            || !is_int($result['provider_revoked_at']) || $result['provider_revoked_at'] < $request['input']['effective_at']
            || $result['provider_revoked_at'] > time() + 5 || !in_array($result['affected_subject_count'], ['0', '1'], true)
            || !in_array($result['removed_secret_count'], ['0', '1'], true) || $result['private_cleanup_complete'] !== true) {
            throw new DomainException('PROVIDER_INVALID_RESPONSE');
        }
        if (($request['input']['known_issue'] ?? null) !== null && $result['affected_subject_count'] !== '1') {
            throw new DomainException('PROVIDER_INVALID_RESPONSE');
        }
        return $result;
    }

    public function disable(string $providerId, string $businessKey): array
    {
        $this->guard(); FixtureCodec::provider($providerId); self::businessKey($businessKey);
        return DB::transaction(function () use ($providerId, $businessKey) {
            // Core can fence a broken/unavailable runtime; cleanup still requires current runtime grants.
            $registration = FixtureRegistration::lock($providerId, null, false);
            return $this->disableLocked($registration, $businessKey);
        }, 5);
    }

    /** SQL-only primitive: the Plugin boundary holds plugin/binding before taking this registration lock. */
    public function fenceLockedRegistration(object $registration, string $businessKey): array
    {
        $this->guard(true); self::businessKey($businessKey);
        if (DB::transactionLevel() === 0 || config('credential_provider_fixture.plugin_bridge_enabled') !== true) {
            throw new DomainException('PROVIDER_OUTER_TRANSACTION_UNSUPPORTED');
        }
        $current = FixtureRegistration::lock($registration->provider_id, null, false);
        if ((string) $current->id !== (string) $registration->id
            || (string) $current->instance_epoch !== (string) $registration->instance_epoch) {
            throw new DomainException('PROVIDER_REGISTRATION_CONFLICT');
        }
        return $this->disableLocked($current, $businessKey);
    }

    private function disableLocked(object $registration, string $businessKey): array
    {
        $providerId = $registration->provider_id;
        $lifecycle = self::lockedState($registration);
        $input = ['provider_id' => $providerId, 'provider_instance_epoch' => (string) $registration->instance_epoch,
            'actor_ref' => 'local-fixture-operator', 'authorization_ref' => 'local-fixture-grant/v1', 'policy' => 'disable-fixture-cleanup/v1'];
        if ($lifecycle->disable_key !== null) {
            $this->checkDisable($lifecycle, $businessKey, $input);
            return $this->progress($registration, $lifecycle, '0');
        }
        $last = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)
            ->where('operation', 'issue')->orderByDesc('id')->sharedLock()->first();
        DB::table('tx_credential_provider_shadow_registration')->where('id', $registration->id)->update(['enabled' => false]);
        DB::table('tx_credential_provider_shadow_lifecycle')->where('id', $lifecycle->id)->update([
            'state' => 'disabling', 'version_no' => self::increment((string) $lifecycle->version_no),
            'disable_operation_ref' => 'dl_'.bin2hex(random_bytes(16)), 'disable_key' => $businessKey,
            'disable_input' => FixtureCodec::json($input), 'disable_hash' => FixtureCodec::hash($input),
            'high_water_id' => $last->id ?? 0, 'cursor_id' => 0, 'started_at' => time(), 'updated_at' => time(),
        ]);
        return $this->progress($registration, self::lockedState($registration), '0');
    }

    public function status(string $providerId, string $businessKey): array
    {
        $this->guard(); FixtureCodec::provider($providerId); self::businessKey($businessKey);
        return DB::transaction(function () use ($providerId, $businessKey) {
            $registration = FixtureRegistration::lock($providerId, null, false);
            $lifecycle = self::lockedState($registration, false);
            $this->checkDisable($lifecycle, $businessKey);
            return $this->progress($registration, $lifecycle, '0');
        });
    }

    public function cleanup(string $providerId, string $businessKey, int $limit, int $deadline): array
    {
        $this->guard(); FixtureCodec::provider($providerId); self::businessKey($businessKey); FixtureCodec::time($deadline);
        if ($limit < 1 || $limit > 32) { throw new DomainException('PROVIDER_BATCH_LIMIT'); }
        if ($deadline <= time() || $deadline > time() + 5) { throw new DomainException('DEADLINE_EXCEEDED'); }
        $page = DB::transaction(function () use ($providerId, $businessKey, $limit) {
            $registration = FixtureRegistration::lock($providerId, 'query');
            $lifecycle = self::lockedState($registration, false);
            $this->checkDisable($lifecycle, $businessKey);
            if ($registration->enabled || $lifecycle->state === 'enabled') { throw new DomainException('PROVIDER_LIFECYCLE_DAMAGED'); }
            $rows = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)->where('operation', 'issue')
                ->where('id', '>', $lifecycle->cursor_id)->where('id', '<=', $lifecycle->high_water_id)->orderBy('id')->limit($limit)->sharedLock()->get();
            return ['registration' => $registration, 'lifecycle' => $lifecycle, 'rows' => $rows];
        });
        $processed = 0; $blocking = null;
        $provider = app(FixtureProviderService::class);
        foreach ($page['rows'] as $issue) {
            if ($deadline <= time()) { $blocking = 'DEADLINE_EXCEEDED'; break; }
            $scope = new FixtureScope($providerId, (string) $issue->access_subject_id);
            try {
                $receipt = $provider->query($scope, $issue->operation_ref, $deadline);
                if (!in_array($receipt['status'], ['succeeded', 'rejected'], true)) { $blocking = 'CLEANUP_ISSUE_UNKNOWN'; break; }
                $record = DB::transaction(function () use ($providerId, $scope) {
                    $registration = FixtureRegistration::lock($providerId, 'revoke');
                    $fact = DB::table('tx_credential_provider_shadow_revocation')->where('registration_id', $registration->id)
                        ->where('access_subject_id', $scope->subjectId)->sharedLock()->first();
                    if ($fact) {
                        $operation = DB::table('tx_credential_provider_shadow_operation')->where('id', $fact->operation_id)->sharedLock()->first();
                        if (!$operation) { throw new DomainException('PROVIDER_OPERATION_DAMAGED'); }
                        self::assertFact($operation);
                        return ['operation_ref' => $operation->operation_ref];
                    }
                    $subject = DB::table('tx_access_subject')->where('id', $scope->subjectId)->sharedLock()->first();
                    if (!$subject || $subject->provider_id !== $providerId) { throw new DomainException('UNAUTHORIZED_SCOPE'); }
                    return ['subject_version' => (string) $subject->auth_version];
                });
                $revoked = isset($record['operation_ref']) ? $provider->query($scope, $record['operation_ref'], $deadline)
                    : $provider->revoke($scope, 'fixture_cleanup_'.$page['lifecycle']->id.'_'.$issue->id,
                        ['expected_subject_version' => $record['subject_version'], 'reason_code' => 'provider_disable'], $deadline);
                if ($revoked['status'] !== 'succeeded' || ($revoked['result']['private_cleanup_complete'] ?? false) !== true) {
                    $blocking = 'CLEANUP_REVOKE_UNKNOWN'; break;
                }
                DB::transaction(function () use ($providerId, $businessKey, $issue) {
                    $registration = FixtureRegistration::lock($providerId, 'query');
                    $lifecycle = self::lockedState($registration, false); $this->checkDisable($lifecycle, $businessKey);
                    if ($registration->enabled) { throw new DomainException('PROVIDER_LIFECYCLE_DAMAGED'); }
                    // Independent workers may finish the same prefix; never regress the stored cursor.
                    if ((int) $issue->id > (int) $lifecycle->cursor_id) {
                        DB::table('tx_credential_provider_shadow_lifecycle')->where('id', $lifecycle->id)->update([
                            'cursor_id' => $issue->id, 'version_no' => self::increment((string) $lifecycle->version_no), 'updated_at' => time(),
                        ]);
                    }
                });
                $processed++;
            } catch (DomainException $error) {
                $safe = ['UNSUPPORTED_OPERATION', 'UNSUPPORTED_PROFILE', 'PROVIDER_REGISTRATION_CONFLICT', 'UNAUTHORIZED_SCOPE',
                    'PROVIDER_OPERATION_DAMAGED', 'VERSION_CONFLICT', 'RUNTIME_UNAVAILABLE', 'DEADLINE_EXCEEDED'];
                $blocking = in_array($error->getMessage(), $safe, true) ? $error->getMessage() : 'CLEANUP_BLOCKED';
                break;
            }
        }
        return DB::transaction(function () use ($providerId, $businessKey, $blocking, $processed) {
            $registration = FixtureRegistration::lock($providerId, null, false);
            $lifecycle = self::lockedState($registration, false); $this->checkDisable($lifecycle, $businessKey);
            if ($registration->enabled) { throw new DomainException('PROVIDER_LIFECYCLE_DAMAGED'); }
            $issues = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)->where('operation', 'issue');
            $growth = (clone $issues)->where('id', '>', $lifecycle->high_water_id)->sharedLock()->exists();
            $remaining = (clone $issues)->where('id', '>', $lifecycle->cursor_id)->sharedLock()->exists();
            $unfinished = $this->unfinished($registration);
            $missing = (clone $issues)->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('tx_credential_provider_shadow_revocation as f')
                    ->join('tx_credential_provider_shadow_operation as r', 'r.id', '=', 'f.operation_id')
                    ->whereColumn('f.registration_id', 'tx_credential_provider_shadow_operation.registration_id')
                    ->whereColumn('f.access_subject_id', 'tx_credential_provider_shadow_operation.access_subject_id')
                    ->where('r.operation', 'revoke')->where('r.status', 'succeeded');
            })->sharedLock()->exists();
            $code = $growth ? 'CLEANUP_SOURCE_GROWTH' : $blocking;
            if (!$code && !$remaining && ($unfinished !== 0 || $missing)) { $code = 'CLEANUP_OPERATIONS_PENDING'; }
            if (!$code && !$remaining) { $code = $this->completionEvidence($registration, $lifecycle); }
            $complete = !$growth && !$blocking && !$remaining && $unfinished === 0 && !$missing;
            $complete = $complete && $code === null;
            $nextState = $complete ? 'disabled' : 'disabling';
            if ($lifecycle->state !== $nextState || $lifecycle->last_error_code !== $code) {
                DB::table('tx_credential_provider_shadow_lifecycle')->where('id', $lifecycle->id)->update([
                    'state' => $nextState, 'disabled_at' => $complete ? ($lifecycle->disabled_at ?? time()) : null,
                    'last_error_code' => $code, 'version_no' => self::increment((string) $lifecycle->version_no), 'updated_at' => time(),
                ]);
            }
            return $this->progress($registration, self::lockedState($registration), (string) $processed);
        });
    }

    private function unfinished(object $registration): int
    {
        return DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)
            ->where(function ($q) { $q->whereIn('status', ['recorded', 'unknown'])->orWhere(function ($q) {
                $q->where('operation', 'revoke')->where('status', '!=', 'succeeded');
            }); })->count();
    }

    private function progress(object $registration, object $lifecycle, string $processed): array
    {
        $remaining = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)->where('operation', 'issue')
            ->where('id', '>', $lifecycle->cursor_id)->count();
        $evidenceError = $lifecycle->state === 'disabled' ? $this->completionEvidence($registration, $lifecycle) : null;
        return ['mode' => 'local_synthetic_fixture', 'provider_id' => $registration->provider_id,
            'operation_ref' => $lifecycle->disable_operation_ref, 'state' => $lifecycle->state,
            'lifecycle_version' => (string) $lifecycle->version_no, 'revocation_fact_version' => (string) $lifecycle->last_fact_version,
            'high_water_id' => (string) $lifecycle->high_water_id, 'cursor' => (string) $lifecycle->cursor_id,
            'processed_count' => $processed, 'remaining_count' => (string) $remaining,
            'unfinished_count' => (string) $this->unfinished($registration), 'has_more' => $remaining > 0,
            'fixture_cleanup_complete' => $lifecycle->state === 'disabled' && $evidenceError === null, 'node_convergence' => 'not_implemented',
            'error_code' => $evidenceError ?? $lifecycle->last_error_code];
    }

    /** The finite fixture history is revalidated; a stored disabled label alone is not proof. */
    private function completionEvidence(object $registration, object $lifecycle): ?string
    {
        if ($registration->enabled) { return 'PROVIDER_LIFECYCLE_DAMAGED'; }
        $rows = DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)->orderBy('id')->limit(513)->sharedLock()->get();
        if ($rows->count() > 512) { return 'CLEANUP_HISTORY_LIMIT'; }
        $issued = []; $revoked = [];
        try {
            foreach ($rows as $row) {
                $input = json_decode($row->input_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if ((string) $row->instance_epoch !== (string) $registration->instance_epoch
                    || ($input['provider_id'] ?? '') !== $registration->provider_id
                    || ($input['provider_instance_epoch'] ?? '') !== (string) $registration->instance_epoch
                    || ($input['access_subject_id'] ?? '') !== (string) $row->access_subject_id
                    || ($input['actor_ref'] ?? '') !== 'local-fixture-operator'
                    || ($input['authorization_ref'] ?? '') !== 'local-fixture-grant/v1'
                    || !hash_equals($row->input_hash, FixtureCodec::hash($input))) { return 'PROVIDER_OPERATION_DAMAGED'; }
                if ($row->operation === 'issue' && (int) $row->id > (int) $lifecycle->high_water_id) { return 'CLEANUP_SOURCE_GROWTH'; }
                if (in_array($row->status, ['recorded', 'unknown'], true) || $row->operation === 'revoke' && $row->status !== 'succeeded') {
                    return 'CLEANUP_OPERATIONS_PENDING';
                }
                $result = $row->result_snapshot === null ? null : json_decode($row->result_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if ($row->status === 'succeeded' && (!is_array($result) || !hash_equals($row->result_hash ?? '', FixtureCodec::hash($result)))) {
                    return 'PROVIDER_OPERATION_DAMAGED';
                }
                if ($row->operation === 'revoke') {
                    self::assertFact($row); self::revokeResult(['input' => $input], $result); $revoked[(string) $row->access_subject_id] = true;
                } elseif ($row->operation === 'issue') {
                    if ($row->status === 'rejected' && ($result !== null || $row->result_hash !== null)) { return 'PROVIDER_OPERATION_DAMAGED'; }
                    if ($row->status === 'succeeded' && (count($result) !== 5
                        || !preg_match('/^cr_[a-f0-9]{32}$/D', $result['credential_ref'] ?? '')
                        || !preg_match('/^ps_[a-f0-9]{32}$/D', $result['provider_subject_ref'] ?? '')
                        || ($result['credential_generation'] ?? '') !== '1'
                        || ($result['credential_expires_at'] ?? null) !== ($input['credential_expires_at'] ?? null)
                        || !is_int($result['issued_at'] ?? null))) { return 'PROVIDER_OPERATION_DAMAGED'; }
                    $issued[(string) $row->access_subject_id] = true;
                } else { return 'PROVIDER_OPERATION_DAMAGED'; }
            }
        } catch (\Throwable) { return 'PROVIDER_OPERATION_DAMAGED'; }
        foreach ($issued as $subject => $_) { if (!isset($revoked[$subject])) { return 'CLEANUP_OPERATIONS_PENDING'; } }
        return null;
    }

    private function checkDisable(?object $lifecycle, string $businessKey, ?array $input = null): void
    {
        if (!$lifecycle || $lifecycle->disable_key === null) { throw new DomainException('PROVIDER_DISABLE_REQUIRED'); }
        if ($lifecycle->disable_key !== $businessKey) { throw new DomainException('IDEMPOTENCY_CONFLICT'); }
        $stored = json_decode($lifecycle->disable_input, true, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals($lifecycle->disable_hash ?? '', FixtureCodec::hash($stored))) { throw new DomainException('PROVIDER_LIFECYCLE_DAMAGED'); }
        if ($input === null) {
            $registration = DB::table('tx_credential_provider_shadow_registration')->where('id', $lifecycle->registration_id)->sharedLock()->first();
            $input = ['provider_id' => $registration->provider_id, 'provider_instance_epoch' => (string) $registration->instance_epoch,
                'actor_ref' => 'local-fixture-operator', 'authorization_ref' => 'local-fixture-grant/v1', 'policy' => 'disable-fixture-cleanup/v1'];
            if (!hash_equals(FixtureCodec::hash($input), $lifecycle->disable_hash)
                || (int) $lifecycle->cursor_id > (int) $lifecycle->high_water_id) { throw new DomainException('PROVIDER_LIFECYCLE_DAMAGED'); }
        } elseif (!hash_equals(FixtureCodec::hash($input), $lifecycle->disable_hash)) { throw new DomainException('IDEMPOTENCY_CONFLICT'); }
    }

    private static function lockedState(object $registration, bool $create = true): ?object
    {
        if ($create) {
            DB::table('tx_credential_provider_shadow_lifecycle')->insertOrIgnore([
                'registration_id' => $registration->id, 'instance_epoch' => $registration->instance_epoch,
                'state' => $registration->enabled ? 'enabled' : 'disabling', 'version_no' => 1, 'last_fact_version' => 0,
                'created_at' => time(), 'updated_at' => time(),
            ]);
        }
        $row = DB::table('tx_credential_provider_shadow_lifecycle')->where('registration_id', $registration->id)->lockForUpdate()->first();
        if ($row && (string) $row->instance_epoch !== (string) $registration->instance_epoch) { throw new DomainException('PROVIDER_REGISTRATION_CONFLICT'); }
        return $row;
    }

    private static function increment(string $value): string
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) || strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) >= 0) {
            throw new DomainException('VERSION_CONFLICT');
        }
        return (string) ((int) $value + 1);
    }

    public static function businessKey(string $key): void
    {
        if (!preg_match('/^fixture_[a-z0-9_-]{1,96}$/D', $key)) { throw new DomainException('PROVIDER_INPUT_INVALID'); }
    }

    private function guard(bool $allowOuterTransaction = false): void
    {
        if (!config('credential_provider_fixture.enabled') || !config('core_shadow.isolated_copy') || !app()->environment('local', 'testing')) {
            throw new DomainException('CAPABILITY_UNAVAILABLE');
        }
        if (!$allowOuterTransaction && DB::transactionLevel() > 0) { throw new DomainException('PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); }
    }
}
