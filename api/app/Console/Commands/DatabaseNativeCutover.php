<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\AtomicNativeRename;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Explicit maintenance-window cutover; never run as a Laravel migration.
 * A reviewed approved plan and external restore evidence are mandatory.
 */
final class DatabaseNativeCutover extends Command
{
    protected $signature = 'txboard:database-cutover
        {--plan= : Path to a manually approved full-table rename plan}
        {--direction=up : up or down}
        {--execute : Actually issue the atomic MySQL RENAME TABLE statement}';

    protected $description = 'Review or execute a coordinated v2_* <-> tx_* MySQL cutover';

    public function handle(): int
    {
        try {
            if (DB::connection()->getDriverName() !== 'mysql') {
                throw new \RuntimeException('Native database cutover requires MySQL');
            }
            $direction = (string) $this->option('direction');
            if (!in_array($direction, ['up', 'down'], true)) {
                throw new \InvalidArgumentException('Invalid direction (up or down)');
            }
            $path = (string) $this->option('plan');
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                throw new \RuntimeException('An existing, readable approved plan is required');
            }
            $plan = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($plan)) {
                throw new \RuntimeException('Plan must be a JSON object');
            }
            $existing = $this->tableNames();
            // AtomicNativeRename validates identifiers, approval, collisions and
            // missing sources before producing executable SQL.
            $statement = AtomicNativeRename::sql($plan, $existing, $direction);
            $renames = $plan['proposedRenames'];
            $sourceKey = $direction === 'up' ? 'from' : 'to';
            $targetKey = $direction === 'up' ? 'to' : 'from';
            $expected = array_column($renames, $sourceKey);
            sort($expected, SORT_STRING);
            $prefix = $direction === 'up' ? 'v2_' : 'tx_';
            $actual = array_values(array_filter($existing, static fn (string $name) => str_starts_with($name, $prefix)));
            sort($actual, SORT_STRING);
            if ($actual !== $expected) {
                throw new \RuntimeException('Plan must include EVERY existing ' . $prefix . ' table; unknown or omitted tables block cutover');
            }

            $this->info(sprintf('Preflight OK: %d tables, %s; %s.',
                count($renames), $direction, $this->option('execute') ? 'execution requested' : 'dry run only'));
            if (!$this->option('execute')) {
                $this->warn('No SQL executed. Add --execute only in a backed-up maintenance window.');
                return self::SUCCESS;
            }

            if (getenv('TXBOARD_CUTOVER_APPROVED') !== '1' ||
                getenv('TXBOARD_BACKUP_VERIFIED') !== '1' ||
                !app()->isDownForMaintenance()) {
                throw new \RuntimeException('Execution requires explicit approval, verified backup and application maintenance mode');
            }
            // MySQL 8.4 applies all pairs as ONE atomic DDL statement. Never
            // loop over Schema::rename or expose a partially renamed schema.
            DB::statement($statement);

            $after = array_flip($this->tableNames());
            foreach ($renames as $rename) {
                if (isset($after[$rename[$sourceKey]]) || !isset($after[$rename[$targetKey]])) {
                    throw new \RuntimeException('Post-cutover schema verification failed');
                }
            }
            $this->info('Atomic rename committed and names verified. Keep writers frozen until full row-level verification.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Native cutover refused or failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function tableNames(): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->table_name,
            DB::select("SELECT TABLE_NAME AS table_name FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")
        );
    }
}
