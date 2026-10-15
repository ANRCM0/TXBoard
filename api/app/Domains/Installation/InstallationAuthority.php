<?php

namespace App\Domains\Installation;

use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Domains\AuthorityMigration\AuthorityInventory;
use App\Domains\AuthorityMigration\AuthorityFinanceInventory;
use App\Domains\AuthorityMigration\AuthorityNativeInventory;
use App\Domains\AuthorityMigration\AuthorityIdentityInventory;
use App\Domains\AuthorityMigration\AuthorityTicketInventory;
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
            // No version of this release can create a ready native record.
            // Domain integration must not turn catalog preparation into activation.
            $this->require($state !== null && $state['authority'] === 'native'
                && $state['phase'] === 'open', 'NATIVE_NOT_READY');
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
            && (is_string($epoch) || is_int($epoch)) && (string) $epoch === '1', 'WORKER_MISMATCH');
        if ($state === null) {
            $this->require($mode === 'legacy', 'RECORD_REQUIRED');
            return;
        }
        $this->require($state['authority'] === $mode && $state['authority_epoch'] === (string) $epoch, 'WORKER_MISMATCH');
    }

    private function historical(array $row): bool
    {
        $versions = [
            AuthorityWriterFence::SCOPE => AuthorityWriterFence::VERSION,
            AuthorityInventory::SCOPE => AuthorityInventory::VERSION,
            AuthorityFinanceInventory::SCOPE => AuthorityFinanceInventory::VERSION,
            AuthorityNativeInventory::SCOPE => AuthorityNativeInventory::VERSION,
            AuthorityIdentityInventory::SCOPE => AuthorityIdentityInventory::VERSION,
            AuthorityTicketInventory::SCOPE => AuthorityTicketInventory::VERSION,
        ];
        return $row['authority'] === 'legacy'
            && ($versions[$row['scope']] ?? null) === $row['version'];
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
        $this->require($state['id'] === '1' && $state['version'] === self::VERSION
            && $state['scope'] === self::VERSION && $state['authority_epoch'] === '1' && $state['row_version'] === '1'
            && $state['schema_version'] === '1' && $state['domain_version'] === '1'
            && in_array($state['authority'], ['legacy', 'native'], true)
            && $state['phase'] === ($state['authority'] === 'native' ? 'initializing' : 'open')
            && $state['prepare_key'] === null && $state['inventory_hash'] === null, 'STATE_INVALID');
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
        return ['initialized' => true, 'mode' => $state['authority'], 'phase' => $state['phase'],
            'installation_id' => $state['installation_id'], 'authority_epoch' => $state['authority_epoch'],
            'schema_version' => (int) $state['schema_version'], 'domain_version' => (int) $state['domain_version']];
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
