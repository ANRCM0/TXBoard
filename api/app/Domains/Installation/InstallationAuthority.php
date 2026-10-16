<?php

namespace App\Domains\Installation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class InstallationAuthority
{
    public const VERSION = 'core-installation/v1';
    private const TABLE = 'tx_authority_writer_fence';
    private const METADATA = ['installation_id', 'bootstrap_key', 'bootstrap_request_hash', 'schema_version', 'domain_version'];
    private const COLUMNS = ['id', 'scope', 'version', 'authority', 'phase', 'authority_epoch', 'prepare_key',
        'row_version', 'updated_at', 'content_hash', 'inventory_hash', ...self::METADATA];
    private const BOOTSTRAP_METADATA = ['migrations', 'tx_settings', 'tx_subscribe_templates'];
    // Recognize retained prototype records without retaining their runtime.
    private const HISTORICAL_FENCE_VERSIONS = [
        'admin-user-editor/v1' => 'authority-writer-fence/v1',
        'native-sql-writers/v2' => 'authority-writer-fence/v2',
        'native-sql-writers/v3' => 'authority-writer-fence/v3',
        'native-sql-writers/v4' => 'authority-writer-fence/v4',
        'native-sql-writers/v5' => 'authority-writer-fence/v5',
        'native-sql-writers/v6' => 'authority-writer-fence/v6',
    ];

    public function initialize(string $installationId, string $mode, string $bootstrapKey): array
    {
        return $this->storage(fn () => $this->initializeRecord($installationId, $mode, $bootstrapKey));
    }

    private function initializeRecord(string $installationId, string $mode, string $bootstrapKey): array
    {
        $this->require((bool) preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $installationId)
            && in_array($mode, ['legacy', 'native'], true)
            && (bool) preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $bootstrapKey), 'INPUT_INVALID');
        $this->require($this->hasSchema(), 'SCHEMA_REQUIRED');
        $requestHash = $this->hash(['installation_id' => $installationId, 'mode' => $mode, 'bootstrap_key' => $bootstrapKey]);

        return $this->bootstrapTransaction(function () use ($installationId, $mode, $bootstrapKey, $requestHash): array {
            $rows = DB::table(self::TABLE)->orderBy('id')->lockForUpdate()->limit(2)->get(self::COLUMNS);
            $this->require($rows->count() <= 1, 'STATE_INVALID');
            $this->assertNoHistoricalEvidence(true);
            if ($rows->isNotEmpty()) {
                $row = (array) $rows->first();
                $this->require($row['scope'] === self::VERSION, 'HISTORICAL_FENCE_PRESENT');
                $state = $this->validate($row);
                $this->require($state['installation_id'] === $installationId
                    && $state['authority'] === $mode && $state['bootstrap_key'] === $bootstrapKey
                    && hash_equals($state['bootstrap_request_hash'], $requestHash), 'BOOTSTRAP_CONFLICT');
                return $this->result($state);
            }
            if ($mode === 'native') {
                $this->assertFreshDatabase();
            }
            $state = ['id' => '1', 'scope' => self::VERSION, 'version' => self::VERSION,
                'authority' => $mode, 'phase' => $mode === 'native' ? 'initializing' : 'open',
                'authority_epoch' => '1', 'prepare_key' => null, 'row_version' => '1',
                'updated_at' => (string) time(), 'inventory_hash' => null,
                'installation_id' => $installationId, 'bootstrap_key' => $bootstrapKey,
                'bootstrap_request_hash' => $requestHash, 'schema_version' => '1', 'domain_version' => '1'];
            $state['content_hash'] = $this->hash($state);
            DB::table(self::TABLE)->insert($state);
            return $this->result($state);
        });
    }

    private function bootstrapTransaction(callable $operation): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return DB::transaction($operation, 3);
        }
        // Empty-key gap locks are compatible: serialize first insertion before
        // entering the transaction rather than exhausting deadlock retries.
        $name = 'tx-core.bootstrap:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 40);
        $lock = DB::selectOne('SELECT GET_LOCK(?, 30) AS acquired', [$name], false);
        $this->require((string) ($lock->acquired ?? '') === '1', 'BOOTSTRAP_BUSY');
        try {
            return DB::transaction($operation, 3);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name], false);
        }
    }

    public function inspect(): array
    {
        return $this->storage(function (): array {
            // Do not establish an RR snapshot before the caller takes aggregate locks.
            $state = $this->read(DB::transactionLevel() > 0);
            return $state === null ? $this->legacyResult() : $this->result($state);
        });
    }

    public function assertRuntime(): void
    {
        $this->storage(function (): void {
            $state = $this->read();
            $this->assertExpected($state);
            $this->require($state === null || $state['authority'] === 'legacy', 'NATIVE_NOT_READY');
        });
    }

    public function assertLegacyWriter(): void
    {
        $this->storage(function (): void {
            $state = $this->read(true);
            $this->assertExpected($state);
            $this->require($state === null || $state['authority'] === 'legacy', 'NATIVE_WRITER_REJECTED');
        });
    }

    public function readiness(): array
    {
        return $this->storage(function (): array {
            $this->require(DB::transactionLevel() === 0, 'PROBE_TRANSACTION_FORBIDDEN');
            $readiness = app(NativeRuntimeReadiness::class);
            [$state, $preflight] = DB::transaction(function () use ($readiness): array {
                $this->assertCatalogMutation();
                $state = $this->read(true);
                return [$state, $readiness->preflightLocked($state['installation_id'])];
            }, 3);
            $snapshot = $readiness->probe($preflight);
            return DB::transaction(function () use ($readiness, $state, $preflight, $snapshot): array {
                $this->assertCatalogMutation();
                $current = $this->read(true);
                $this->require(hash_equals($state['content_hash'], $current['content_hash'])
                    && hash_equals(NativeRuntimeReadiness::hash($preflight),
                        NativeRuntimeReadiness::hash($readiness->preflightLocked($current['installation_id']))),
                    'ACTIVATION_CONFIGURATION_CHANGED');
                return ['schema' => NativeRuntimeAdmission::VERSION, 'ready_to_activate' => true,
                    'installation_id' => $current['installation_id'], 'expected_version' => $current['row_version'],
                    'configuration_digest' => NativeRuntimeReadiness::hash($snapshot), 'next_worker_epoch' => '2',
                    'provider_capabilities' => $snapshot['provider_capabilities']];
            }, 3);
        });
    }

    public function activate(string $key, string $expectedVersion, string $configurationDigest): array
    {
        return $this->storage(function () use ($key, $expectedVersion, $configurationDigest): array {
            $this->require(DB::transactionLevel() === 0 && preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $key) === 1
                && $expectedVersion === '1' && preg_match('/\A[0-9a-f]{64}\z/D', $configurationDigest) === 1, 'ACTIVATION_INPUT_INVALID');
            $candidate = DB::transaction(fn (): array =>
                $this->activationCandidateLocked($key, $expectedVersion, $configurationDigest), 3);
            if (isset($candidate['result'])) { return $candidate['result']; }
            $snapshot = app(NativeRuntimeReadiness::class)->probe($candidate['preflight']);
            return DB::transaction(function () use ($key, $expectedVersion, $configurationDigest, $candidate, $snapshot): array {
                $current = $this->activationCandidateLocked($key, $expectedVersion, $configurationDigest);
                if (isset($current['result'])) { return $current['result']; }
                $this->require(hash_equals($candidate['state']['content_hash'], $current['state']['content_hash'])
                    && hash_equals(NativeRuntimeReadiness::hash($candidate['preflight']), NativeRuntimeReadiness::hash($current['preflight']))
                    && hash_equals($configurationDigest, NativeRuntimeReadiness::hash($snapshot)), 'ACTIVATION_CONFIGURATION_CHANGED');
                $state = $current['state'];
                $now = time();
                DB::table('tx_native_runtime_activation')->insert([
                    'installation_id' => $state['installation_id'], 'business_key' => $key,
                    'runtime_version' => NativeRuntimeAdmission::VERSION, 'request_hash' => NativeRuntimeReadiness::hash($current['request']),
                    'configuration_snapshot' => \App\Domains\CredentialProvider\ProviderCodec::canonical($snapshot),
                    'configuration_hash' => $configurationDigest, 'authority_epoch' => '2', 'activated_at' => $now,
                ]);
                $state = array_replace($state, ['phase' => 'open', 'authority_epoch' => '2', 'row_version' => '2',
                    'schema_version' => '2', 'domain_version' => '2', 'prepare_key' => $key,
                    'inventory_hash' => $configurationDigest, 'updated_at' => (string) $now]);
                unset($state['content_hash']);
                $state['content_hash'] = $this->hash($state);
                $this->require(DB::table(self::TABLE)->where('id', 1)->where('row_version', 1)->update($state) === 1, 'ACTIVATION_CONFLICT');
                return $this->result($state);
            }, 3);
        });
    }

    private function activationCandidateLocked(string $key, string $expectedVersion, string $configurationDigest): array
    {
        $rows = DB::table(self::TABLE)->orderBy('id')->limit(2)->lockForUpdate()->get(self::COLUMNS);
        $this->require($rows->count() === 1, 'RECORD_REQUIRED');
        $this->assertNoHistoricalEvidence(true);
        $state = $this->validate((array) $rows->first());
        $this->require($state['authority'] === 'native' && config('core_installation.mode') === 'native', 'ACTIVATION_NATIVE_REQUIRED');
        $request = ['installation_id' => $state['installation_id'], 'business_key' => $key,
            'expected_version' => $expectedVersion, 'configuration_digest' => $configurationDigest];
        $existing = DB::table('tx_native_runtime_activation')->where('installation_id', $state['installation_id'])->lockForUpdate()->first();
        if ($existing) {
            $this->require($existing->business_key === $key
                && hash_equals($existing->request_hash, NativeRuntimeReadiness::hash($request))
                && $state['phase'] === 'open', 'ACTIVATION_CONFLICT');
            return ['result' => $this->result($state)];
        }
        $this->assertExpected($state);
        $this->require($state['phase'] === 'initializing' && $state['row_version'] === $expectedVersion, 'ACTIVATION_CONFLICT');
        return ['state' => $state, 'request' => $request,
            'preflight' => app(NativeRuntimeReadiness::class)->preflightLocked($state['installation_id'])];
    }

    public function assertNativeRuntime(): array
    {
        return DB::transaction(fn (): array => $this->assertNativeDomain(), 3);
    }

    public function assertHttp(string $method, string $path): void
    {
        if ($this->inspect()['mode'] !== 'native') { $this->assertRuntime(); return; }
        $this->assertNativeRuntime();
        $this->require(NativeRuntimeAdmission::http($method, $path), 'NATIVE_CAPABILITY_UNAVAILABLE');
    }

    public function assertCatalogMutation(): void
    {
        $this->storage(function (): void {
            $this->require(DB::transactionLevel() > 0, 'TRANSACTION_REQUIRED');
            $state = $this->read(true);
            $this->assertExpected($state);
            $this->require($state !== null && $state['authority'] === 'native'
                && $state['phase'] === 'initializing', 'CATALOG_REJECTED');
        });
    }

    public function assertNativeMutation(): array
    {
        return $this->assertNativeDomain();
    }

    public function assertNativeRead(): array
    {
        return $this->assertNativeDomain();
    }

    private function assertNativeDomain(): array
    {
        return $this->storage(function (): array {
            $this->require(DB::transactionLevel() > 0, 'TRANSACTION_REQUIRED');
            $state = $this->read(true);
            $this->assertExpected($state);
            $this->require($state !== null && $state['authority'] === 'native'
                && $state['phase'] === 'open', 'NATIVE_NOT_READY');
            $configuration = app(NativeRuntimeReadiness::class)->configuration($state['installation_id']);
            $receipt = DB::table('tx_native_runtime_activation')->where('installation_id', $state['installation_id'])->sharedLock()->first();
            $snapshot = json_decode($receipt->configuration_snapshot, true, 32, JSON_THROW_ON_ERROR);
            $approved = app(\App\Domains\CredentialProvider\NativeProviderTrust::class)
                ->currentLocked($state['installation_id'], $snapshot['configuration']);
            $this->require(hash_equals(NativeRuntimeReadiness::hash($approved),
                NativeRuntimeReadiness::hash($configuration)), 'NATIVE_CONFIGURATION_CHANGED');
            return $this->result($state);
        });
    }

    private function read(bool $locked = false): ?array
    {
        if (!$this->hasSchema()) {
            if (Schema::hasTable(self::TABLE)) {
                $this->require(Schema::hasColumns(self::TABLE, ['scope', 'version', 'authority']), 'STATE_INVALID');
                $metadata = array_values(array_intersect(self::METADATA, Schema::getColumnListing(self::TABLE)));
                $query = DB::table(self::TABLE)->useWritePdo()->limit(2);
                if ($locked && DB::transactionLevel() > 0) {
                    $query->sharedLock();
                }
                $rows = $query->get(['scope', 'version', 'authority', ...$metadata]);
                $this->require($rows->count() <= 1, 'STATE_INVALID');
                foreach ($rows as $row) {
                    $this->require($this->historical((array) $row)
                        && count(array_filter($metadata, fn ($field) => $row->$field !== null)) === 0, 'STATE_INVALID');
                }
            }
            // A native process never treats a pre-install or old schema as legacy.
            $this->require(config('core_installation.mode', 'legacy') === 'legacy', 'RECORD_REQUIRED');
            return null;
        }
        // Runtime admission cannot trust a replica that has not observed native initialization.
        $query = DB::table(self::TABLE)->useWritePdo()->orderBy('id')->limit(2);
        if ($locked && DB::transactionLevel() > 0) {
            $query->sharedLock();
        }
        $rows = $query->get(self::COLUMNS);
        $this->require($rows->count() <= 1, 'STATE_INVALID');
        if ($rows->isEmpty()) {
            return null;
        }
        $row = (array) $rows->first();
        if ($row['scope'] !== self::VERSION) {
            $this->require($this->historical($row)
                && count(array_filter(self::METADATA, fn ($field) => $row[$field] !== null)) === 0, 'STATE_INVALID');
            return null;
        }
        if ($locked) {
            $this->require(DB::transactionLevel() > 0, 'TRANSACTION_REQUIRED');
        }
        $this->assertNoHistoricalEvidence();
        return $this->validate($row);
    }

    private function assertExpected(?array $state): void
    {
        $mode = config('core_installation.mode', 'legacy');
        $epoch = config('core_installation.worker_epoch', '1');
        $this->require(in_array($mode, ['legacy', 'native'], true)
            && (is_string($epoch) || is_int($epoch)) && in_array((string) $epoch, ['1', '2'], true), 'WORKER_MISMATCH');
        if ($state === null) {
            $this->require($mode === 'legacy', 'RECORD_REQUIRED');
            return;
        }
        $this->require($state['authority'] === $mode && $state['authority_epoch'] === (string) $epoch, 'WORKER_MISMATCH');
    }

    private function historical(array $row): bool
    {
        return $row['authority'] === 'legacy'
            && (self::HISTORICAL_FENCE_VERSIONS[$row['scope']] ?? null) === $row['version'];
    }

    private function validate(array $state): array
    {
        foreach (['id', 'authority_epoch', 'row_version', 'updated_at', 'schema_version', 'domain_version'] as $field) {
            $this->require((is_int($state[$field]) || is_string($state[$field]))
                && (bool) preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $state[$field])
                && (strlen((string) $state[$field]) < strlen((string) PHP_INT_MAX)
                    || (strlen((string) $state[$field]) === strlen((string) PHP_INT_MAX)
                        && strcmp((string) $state[$field], (string) PHP_INT_MAX) <= 0)), 'STATE_INVALID');
            $state[$field] = (string) $state[$field];
        }
        $initial = $state['authority_epoch'] === '1' && $state['row_version'] === '1'
            && $state['schema_version'] === '1' && $state['domain_version'] === '1'
            && $state['phase'] === ($state['authority'] === 'native' ? 'initializing' : 'open')
            && $state['prepare_key'] === null && $state['inventory_hash'] === null;
        $activated = $state['authority'] === 'native' && $state['phase'] === 'open'
            && $state['authority_epoch'] === '2' && $state['row_version'] === '2'
            && $state['schema_version'] === '2' && $state['domain_version'] === '2'
            && is_string($state['prepare_key']) && is_string($state['inventory_hash']);
        $this->require($state['id'] === '1' && $state['version'] === self::VERSION && $state['scope'] === self::VERSION
            && in_array($state['authority'], ['legacy', 'native'], true) && ($initial || $activated), 'STATE_INVALID');
        if ($activated) {
            $query = DB::table('tx_native_runtime_activation')->useWritePdo()->where('installation_id', $state['installation_id']);
            if (DB::transactionLevel() > 0) { $query->sharedLock(); }
            $receipt = $query->first();
            $this->require($receipt !== null && $receipt->runtime_version === NativeRuntimeAdmission::VERSION
                && $receipt->business_key === $state['prepare_key'] && (string) $receipt->authority_epoch === '2'
                && hash_equals($receipt->configuration_hash, $state['inventory_hash']), 'STATE_INVALID');
            $snapshot = json_decode($receipt->configuration_snapshot, true, 32, JSON_THROW_ON_ERROR);
            $this->require(is_array($snapshot) && hash_equals($receipt->configuration_hash, NativeRuntimeReadiness::hash($snapshot))
                && ($snapshot['configuration']['admission_digest'] ?? null) === NativeRuntimeAdmission::digest()
                && ($snapshot['configuration']['installation_id'] ?? null) === $state['installation_id']
                && ($snapshot['provider_capabilities']['node_delivery'] ?? null) === true
                && ($snapshot['provider_capabilities']['subscription_render'] ?? null) === true, 'STATE_INVALID');
            $this->require(hash_equals($receipt->request_hash, NativeRuntimeReadiness::hash([
                'installation_id' => $state['installation_id'], 'business_key' => $state['prepare_key'],
                'expected_version' => '1', 'configuration_digest' => $receipt->configuration_hash])), 'STATE_INVALID');
        }
        $this->require(is_string($state['installation_id']) && (bool) preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $state['installation_id'])
            && is_string($state['bootstrap_key']) && (bool) preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $state['bootstrap_key'])
            && is_string($state['bootstrap_request_hash']) && is_string($state['content_hash']), 'STATE_INVALID');
        $expectedRequest = $this->hash(['installation_id' => $state['installation_id'], 'mode' => $state['authority'], 'bootstrap_key' => $state['bootstrap_key']]);
        $this->require(hash_equals($expectedRequest, $state['bootstrap_request_hash']), 'STATE_INVALID');
        $content = $state['content_hash'];
        unset($state['content_hash']);
        $this->require(hash_equals($this->hash($state), $content), 'STATE_INVALID');
        $state['content_hash'] = $content;
        return $state;
    }

    private function hasSchema(): bool
    {
        return Schema::hasTable(self::TABLE) && Schema::hasColumns(self::TABLE, self::COLUMNS);
    }

    private function assertNoHistoricalEvidence(bool $exclusive = false): void
    {
        foreach (['tx_authority_prepare_receipt', 'tx_authority_inventory_upgrade', 'tx_authority_finance_upgrade',
            'tx_authority_native_upgrade', 'tx_authority_identity_upgrade', 'tx_authority_ticket_upgrade'] as $table) {
            if (Schema::hasTable($table)) {
                $query = DB::table($table)->useWritePdo();
                if (DB::transactionLevel() > 0) {
                    $exclusive ? $query->lockForUpdate() : $query->sharedLock();
                }
                $this->require(!$query->exists(), 'HISTORICAL_FENCE_PRESENT');
            }
        }
    }

    private function assertFreshDatabase(): void
    {
        foreach (Schema::getTableListing(null, false) as $table) {
            if (in_array($table, self::BOOTSTRAP_METADATA, true)) {
                continue;
            }
            if ($table === 'tx_user') {
                $this->assertBootstrapAdministrators();
                continue;
            }
            if ($table === 'tx_plugin_package_fence') {
                // Inactive package identities are coordination inventory, not adopted business data.
                $this->require(!DB::table($table)->whereNotNull('mutation_token')->lockForUpdate()->exists(),
                    'DATABASE_NOT_EMPTY');
                continue;
            }
            $this->require(!DB::table($table)->lockForUpdate()->exists(), 'DATABASE_NOT_EMPTY');
        }
    }

    private function assertBootstrapAdministrators(): void
    {
        $columns = Schema::getColumnListing('tx_user');
        $this->require(in_array('is_admin', $columns, true), 'DATABASE_NOT_EMPTY');
        $nullFields = array_values(array_intersect(['plan_id', 'group_id', 'invite_user_id', 'telegram_id',
            'speed_limit', 'device_limit', 'last_reset_at', 'next_reset_at'], $columns));
        $zeroFields = array_values(array_intersect(['expired_at', 'transfer_enable', 'u', 'd', 'balance',
            'commission_balance', 'reset_count', 'discount', 'commission_rate', 'commission_type', 't'], $columns));
        $invalid = DB::table('tx_user')->where(function ($query) use ($nullFields, $zeroFields): void {
            $query->whereNull('is_admin')->orWhere('is_admin', '<>', 1);
            foreach ($nullFields as $field) {
                $query->orWhereNotNull($field);
            }
            foreach ($zeroFields as $field) {
                $query->orWhere(function ($query) use ($field): void {
                    $query->whereNotNull($field)->where($field, '<>', 0);
                });
            }
        });
        $this->require(!$invalid->lockForUpdate()->exists(), 'DATABASE_NOT_EMPTY');
    }

    private function result(array $state): array
    {
        $result = ['initialized' => true, 'mode' => $state['authority'], 'phase' => $state['phase'],
            'installation_id' => $state['installation_id'], 'authority_epoch' => $state['authority_epoch'],
            'schema_version' => (int) $state['schema_version'], 'domain_version' => (int) $state['domain_version']];
        if ($state['authority'] === 'native' && $state['phase'] === 'open') { $result['runtime_version'] = NativeRuntimeAdmission::VERSION; }
        return $result;
    }

    private function legacyResult(): array
    {
        return ['initialized' => false, 'mode' => 'legacy', 'phase' => null, 'installation_id' => null,
            'authority_epoch' => null, 'schema_version' => null, 'domain_version' => null];
    }

    private function hash(array $value): string
    {
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function require(bool $valid, string $code): void
    {
        if (!$valid) {
            throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_'.$code);
        }
    }

    private function storage(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InstallationAuthorityException $error) {
            throw $error;
        } catch (Throwable) {
            throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_STORAGE_FAILED');
        }
    }
}
