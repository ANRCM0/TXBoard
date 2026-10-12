<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $enumColumns = [];

    private const TABLES = [
        'tx_subscription', 'tx_subscription_terms', 'tx_subscription_terms_transition',
        'tx_subscription_slot', 'tx_usage_period', 'tx_usage_adjustment', 'tx_access_subject',
        'tx_legacy_subscription_map', 'tx_legacy_subscription_exception', 'tx_migration_checkpoint',
    ];

    public function up(): void
    {
        $this->createShadowTable('tx_subscription', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->integer('plan_id')->nullable();
            $table->enum('status', ['pending', 'active', 'suspended', 'expired', 'revoked']);
            $table->unsignedBigInteger('current_terms_id')->nullable();
            $table->unsignedBigInteger('starts_at');
            $table->unsignedBigInteger('expires_at')->nullable();
            $table->unsignedBigInteger('ended_at')->nullable();
            $table->string('suspended_reason', 64)->nullable();
            $this->mutableColumns($table);
            $table->unique(['id', 'user_id'], 'tx_sub_owner_uq');
            $table->index(['user_id', 'status'], 'tx_sub_user_status_idx');
        });

        $this->createShadowTable('tx_subscription_terms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('version_no');
            $table->unsignedBigInteger('plan_revision_id')->nullable();
            $table->unsignedBigInteger('effective_from');
            $table->unsignedBigInteger('effective_until')->nullable();
            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->integer('group_id')->nullable();
            $table->unsignedInteger('speed_limit_mbps')->nullable();
            $table->unsignedInteger('device_limit')->nullable();
            $table->string('billing_timezone', 64)->nullable();
            $table->json('reset_policy');
            $table->json('legacy_snapshot')->nullable();
            $this->immutableColumns($table);
            $table->unique(['subscription_id', 'version_no'], 'tx_terms_version_uq');
            $table->unique(['id', 'subscription_id'], 'tx_terms_owner_uq');
            $table->index(['subscription_id', 'effective_from'], 'tx_terms_effective_idx');
            $table->foreign('subscription_id', 'tx_terms_sub_fk')->references('id')->on('tx_subscription')->restrictOnDelete();
        });

        $currentTerms = new Blueprint(DB::connection(), 'tx_subscription', function (Blueprint $table) {
            $table->foreign(['current_terms_id', 'id'], 'tx_sub_current_terms_fk')
                ->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
        });
        $this->ensureCommands('tx_subscription', $currentTerms->getCommands());

        $this->createShadowTable('tx_subscription_terms_transition', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('previous_terms_id');
            $table->unsignedBigInteger('next_terms_id');
            $table->unsignedBigInteger('effective_at');
            $table->string('business_key', 128);
            $this->immutableColumns($table);
            $table->unique('previous_terms_id', 'tx_transition_prev_uq');
            $table->unique('next_terms_id', 'tx_transition_next_uq');
            $table->unique(['subscription_id', 'business_key'], 'tx_transition_business_uq');
            $table->foreign(['previous_terms_id', 'subscription_id'], 'tx_transition_prev_fk')
                ->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
            $table->foreign(['next_terms_id', 'subscription_id'], 'tx_transition_next_fk')
                ->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
        });

        $this->createShadowTable('tx_subscription_slot', function (Blueprint $table) {
            $table->integer('user_id');
            $table->unsignedTinyInteger('slot_no')->default(1);
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('reserved_at')->nullable();
            $this->mutableColumns($table);
            $table->primary(['user_id', 'slot_no']);
            $table->unique('user_id', 'tx_slot_capacity_uq');
            $table->unique('subscription_id', 'tx_slot_sub_uq');
            $table->foreign(['subscription_id', 'user_id'], 'tx_slot_owner_fk')
                ->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
        });

        $this->createShadowTable('tx_usage_period', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('terms_id');
            $table->unsignedBigInteger('period_no');
            $table->unsignedBigInteger('starts_at')->nullable();
            $table->unsignedBigInteger('ends_at')->nullable();
            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->unsignedBigInteger('billable_bytes')->default(0);
            $table->enum('status', ['shadow', 'open', 'closed'])->default('shadow');
            $table->enum('boundary_kind', ['known', 'legacy_boundary_unknown']);
            $table->unsignedBigInteger('last_reset_at')->nullable();
            $table->unsignedBigInteger('next_reset_at')->nullable();
            $table->unsignedBigInteger('legacy_reset_count')->default(0);
            $this->mutableColumns($table);
            $table->unique(['subscription_id', 'period_no'], 'tx_period_number_uq');
            $table->unique(['id', 'subscription_id'], 'tx_period_owner_uq');
            $table->foreign(['terms_id', 'subscription_id'], 'tx_period_terms_fk')
                ->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
        });

        $this->createShadowTable('tx_usage_adjustment', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('usage_period_id');
            $table->unsignedBigInteger('terms_transition_id')->nullable();
            $table->string('business_key', 128);
            $table->enum('kind', ['quota_change', 'migration_opening', 'correction']);
            $table->bigInteger('quota_delta_bytes');
            $table->string('policy_version', 64);
            $this->immutableColumns($table);
            $table->unique(['usage_period_id', 'business_key'], 'tx_adjustment_business_uq');
            $table->foreign('usage_period_id', 'tx_adjustment_period_fk')->references('id')->on('tx_usage_period')->restrictOnDelete();
            $table->foreign('terms_transition_id', 'tx_adjustment_transition_fk')->references('id')->on('tx_subscription_terms_transition')->restrictOnDelete();
        });

        $this->createShadowTable('tx_access_subject', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('provider_id', 64);
            $table->string('provider_subject_ref', 128);
            $table->enum('status', ['pending', 'active', 'revoked'])->default('pending');
            $table->unsignedBigInteger('auth_version')->default(1);
            $table->unsignedBigInteger('revocation_fact_version')->default(0);
            $table->unsignedBigInteger('revoked_at')->nullable();
            $this->mutableColumns($table);
            $table->unique(['provider_id', 'provider_subject_ref'], 'tx_subject_provider_uq');
            $table->unique(['id', 'subscription_id'], 'tx_subject_owner_uq');
            $table->foreign('subscription_id', 'tx_subject_sub_fk')->references('id')->on('tx_subscription')->restrictOnDelete();
        });

        $this->createShadowTable('tx_legacy_subscription_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('legacy_user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('terms_id');
            $table->unsignedBigInteger('usage_period_id');
            $table->unsignedBigInteger('access_subject_id');
            $table->string('migration_key', 128);
            $table->string('run_key', 64);
            $table->char('source_hash', 64);
            $table->json('source_snapshot');
            $table->enum('state', ['mapped', 'source_changed', 'exception'])->default('mapped');
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('last_verified_at')->nullable();
            $this->mutableColumns($table);
            $table->unique('legacy_user_id', 'tx_legacy_map_user_uq');
            $table->unique('subscription_id', 'tx_legacy_map_sub_uq');
            $table->unique('migration_key', 'tx_legacy_map_key_uq');
            $table->index(['run_key', 'state'], 'tx_legacy_map_run_state_idx');
            $table->foreign(['subscription_id', 'legacy_user_id'], 'tx_legacy_map_owner_fk')
                ->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
            $table->foreign(['terms_id', 'subscription_id'], 'tx_legacy_map_terms_fk')
                ->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
            $table->foreign(['usage_period_id', 'subscription_id'], 'tx_legacy_map_period_fk')
                ->references(['id', 'subscription_id'])->on('tx_usage_period')->restrictOnDelete();
            $table->foreign(['access_subject_id', 'subscription_id'], 'tx_legacy_map_subject_fk')
                ->references(['id', 'subscription_id'])->on('tx_access_subject')->restrictOnDelete();
        });

        $this->createShadowTable('tx_legacy_subscription_exception', function (Blueprint $table) {
            $table->integer('legacy_user_id')->primary();
            $table->string('run_key', 64);
            $table->char('source_hash', 64)->nullable();
            $table->string('error_code', 64);
            $table->unsignedBigInteger('attempts')->default(1);
            $table->enum('state', ['unresolved', 'resolved'])->default('unresolved');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });

        $this->createShadowTable('tx_migration_checkpoint', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('run_key', 64)->unique();
            $table->enum('phase', ['backfilling', 'backfilled']);
            $table->integer('cursor_user_id')->default(0);
            $table->integer('high_water_user_id');
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->json('details');
            $this->mutableColumns($table);
        });
    }

    public function down(): void
    {
        // A rollback must never erase backfilled history or recovery checkpoints.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \RuntimeException('Subscription shadow contains data; restore a verified backup or fix forward.');
            }
        }
        Schema::table('tx_subscription', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['current_terms_id', 'id'] : 'tx_sub_current_terms_fk');
        });
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function mutableColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('row_version')->default(1);
        $table->unsignedBigInteger('created_at');
        $table->unsignedBigInteger('updated_at');
    }

    private function immutableColumns(Blueprint $table): void
    {
        $table->char('content_hash', 64);
        $table->unsignedBigInteger('created_at');
    }

    private function createShadowTable(string $table, \Closure $definition): void
    {
        if (!Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
        // Derive the recovery requirements from the original declaration, not a second schema manifest.
        $expected = new Blueprint(DB::connection(), $table);
        $expected->create();
        $definition($expected);
        $expected->toSql(); // Expands fluent unique/primary declarations without executing SQL.
        $this->enumColumns[$table] = array_values(array_filter($expected->getColumns(), fn ($column) => $column->type === 'enum'));
        $actualColumns = array_column(Schema::getColumns($table), null, 'name');
        $sqliteSql = DB::getDriverName() === 'sqlite'
            ? (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql') : '';
        if (DB::getDriverName() === 'sqlite') {
            foreach ($this->enumColumns[$table] as $enum) {
                $enumName = preg_quote($enum->name, '/');
                if (!preg_match('/check\s*\(\s*["`]?'.$enumName.'["`]?\s+in\s*\(([^)]*)\)\s*\)/i', $sqliteSql)) {
                    // SQLite alters rebuild tables; Laravel's introspection otherwise drops enum checks.
                    $this->alterPreservingSqliteEnums($table, fn (Blueprint $blueprint) => null);
                    $sqliteSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
                    break;
                }
            }
        }
        foreach ($expected->getColumns() as $column) {
            $actual = $actualColumns[$column->name] ?? null;
            $allowedTypes = match ($column->type) {
                'integer' => ['int', 'integer'],
                'bigInteger' => DB::getDriverName() === 'sqlite' ? ['integer'] : ['bigint'],
                'tinyInteger' => DB::getDriverName() === 'sqlite' ? ['integer'] : ['tinyint'],
                'string' => ['varchar'], 'char' => DB::getDriverName() === 'sqlite' ? ['varchar'] : ['char'],
                'enum' => ['enum', 'varchar'], 'json' => ['json', 'text', 'longtext'],
                default => [],
            };
            if ($actual === null || !in_array($actual['type_name'], $allowedTypes, true)
                || (!$column->autoIncrement && $actual['nullable'] !== (bool) $column->nullable)
                || (DB::getDriverName() !== 'sqlite' && (bool) $actual['auto_increment'] !== (bool) $column->autoIncrement)) {
                throw new \RuntimeException("Subscription shadow schema mismatch: {$table}.{$column->name}");
            }
            if (DB::getDriverName() === 'mysql' && in_array($column->type, ['integer', 'bigInteger', 'tinyInteger'], true)
                && str_contains($actual['type'], 'unsigned') !== (bool) $column->unsigned) {
                throw new \RuntimeException("Subscription shadow signedness mismatch: {$table}.{$column->name}");
            }
            if (DB::getDriverName() !== 'sqlite' && in_array($column->type, ['string', 'char'], true)
                && $actual['type'] !== $actual['type_name'].'('.$column->length.')') {
                throw new \RuntimeException("Subscription shadow column length mismatch: {$table}.{$column->name}");
            }
            $actualDefault = $actual['default'] === null || strtoupper((string) $actual['default']) === 'NULL'
                ? null : trim((string) $actual['default'], "()'\"");
            $expectedDefault = $column->default === null ? null : (string) $column->default;
            if ($actualDefault !== $expectedDefault) {
                throw new \RuntimeException("Subscription shadow default mismatch: {$table}.{$column->name}");
            }
            if ($column->type === 'enum') {
                $allowed = implode(',', array_map(fn ($value) => "'".str_replace("'", "''", $value)."'", $column->allowed));
                if (DB::getDriverName() === 'sqlite') {
                    $columnName = preg_quote($column->name, '/');
                    preg_match('/check\s*\(\s*["`]?'.$columnName.'["`]?\s+in\s*\(([^)]*)\)\s*\)/i', $sqliteSql, $matches);
                    $actualAllowed = isset($matches[1]) ? preg_replace('/\s+/', '', $matches[1]) : null;
                } else {
                    $actualAllowed = substr($actual['type'], 5, -1);
                }
                if ($actualAllowed !== $allowed) {
                    throw new \RuntimeException("Subscription shadow enum mismatch: {$table}.{$column->name}");
                }
            }
            if ($column->autoIncrement && !Schema::hasIndex($table, [$column->name], 'primary')) {
                throw new \RuntimeException("Subscription shadow primary key mismatch: {$table}");
            }
        }
        $this->ensureCommands($table, $expected->getCommands());
    }

    private function ensureCommands(string $table, array $commands): void
    {
        foreach (Schema::getForeignKeys($table) as $actual) {
            if (!in_array($actual['on_delete'], ['restrict', 'no action'], true)
                || !in_array($actual['on_update'], ['restrict', 'no action'], true)) {
                throw new \RuntimeException("Subscription shadow foreign key must preserve history: {$table}");
            }
            if (in_array($actual['foreign_table'], ['tx_user', 'tx_plan'], true)) {
                throw new \RuntimeException("Subscription shadow must not restrict legacy source deletion: {$table}");
            }
        }
        foreach ($commands as $command) {
            if (in_array($command->name, ['primary', 'unique', 'index'], true)) {
                $type = $command->name === 'index' ? null : $command->name;
                if (Schema::hasIndex($table, $command->columns, $type)) {
                    continue;
                }
                if (Schema::hasIndex($table, $command->index)) {
                    throw new \RuntimeException("Subscription shadow index mismatch: {$table}.{$command->index}");
                }
                // MySQL may have committed CREATE TABLE before a later index statement failed.
                Schema::table($table, function (Blueprint $blueprint) use ($command) {
                    $method = $command->name;
                    $blueprint->$method($command->columns, $command->index);
                });
            } elseif ($command->name === 'foreign') {
                $found = false;
                foreach (Schema::getForeignKeys($table) as $actual) {
                    if ($actual['columns'] === $command->columns || $actual['name'] === $command->index) {
                        if ($actual['columns'] !== $command->columns || $actual['foreign_table'] !== $command->on
                            || $actual['foreign_columns'] !== (array) $command->references
                            || !in_array($actual['on_delete'], ['restrict', 'no action'], true)
                            || !in_array($actual['on_update'], ['restrict', 'no action'], true)) {
                            throw new \RuntimeException("Subscription shadow foreign key mismatch: {$table}.{$command->index}");
                        }
                        $found = true;
                    }
                }
                if (!$found) {
                    $this->alterPreservingSqliteEnums($table, function (Blueprint $blueprint) use ($command) {
                        $blueprint->foreign($command->columns, $command->index)->references($command->references)
                            ->on($command->on)->restrictOnDelete();
                    });
                }
            }
        }
    }

    private function alterPreservingSqliteEnums(string $table, \Closure $alter): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table, $alter) {
            $alter($blueprint);
            if (DB::getDriverName() === 'sqlite') {
                foreach ($this->enumColumns[$table] ?? [] as $column) {
                    $definition = $blueprint->enum($column->name, $column->allowed)->nullable((bool) $column->nullable)->change();
                    if ($column->default !== null) {
                        $definition->default($column->default);
                    }
                }
            }
        });
    }
};
