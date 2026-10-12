<?php

namespace Tests\Feature\Subscription;

use App\Models\AccessSubject;
use App\Models\Subscription;
use App\Models\SubscriptionTerms;
use App\Models\UsagePeriod;
use App\Models\User;
use App\Services\Subscription\SubscriptionSlotService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class SubscriptionSlotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'mysql') {
            if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') {
                $this->markTestSkipped('MySQL slot tests require the dedicated txboard_concurrency_test database.');
            }
            $this->clearDedicatedMysqlFixture();
        } else {
            config([
                'database.default' => 'subscription_slot_testing',
                'database.connections.subscription_slot_testing' => array_merge(config('database.connections.sqlite'), [
                    'database' => ':memory:', 'foreign_key_constraints' => true,
                ]),
            ]);
            DB::purge('subscription_slot_testing');
        }
        Schema::create('tx_user', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('email');
            $table->string('password');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'mysql' && DB::connection()->getDatabaseName() === 'txboard_concurrency_test') {
            $this->clearDedicatedMysqlFixture();
        }
        DB::purge('subscription_slot_testing');
        parent::tearDown();
    }

    public function test_reservation_is_idempotent_and_does_not_change_legacy_user(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $subscription = $this->subscription($user, 'active');
        $service = new SubscriptionSlotService();
        $service->reserve($user->id, $subscription->id, 100);
        $first = DB::table('tx_subscription_slot')->first();
        $service->reserve($user->id, $subscription->id, 101);
        $this->assertEquals($first, DB::table('tx_subscription_slot')->first());
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_independent_service_instances_share_a_persisted_capacity_constraint(): void
    {
        $user = $this->user();
        $first = $this->subscription($user);
        $second = $this->subscription($user);
        (new SubscriptionSlotService())->transition($user->id, $first->id, 'active', 100);
        try {
            (new SubscriptionSlotService())->transition($user->id, $second->id, 'active', 100);
            $this->fail('A second activation must not overwrite the existing reservation.');
        } catch (DomainException $exception) {
            $this->assertSame('subscription_slot_occupied', $exception->getMessage());
        }
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame($first->id, (int) DB::table('tx_subscription_slot')->value('subscription_id'));
        $this->assertSame(1, Subscription::query()->where('status', 'active')->count());
    }

    public function test_suspended_subscription_keeps_its_reservation(): void
    {
        $user = $this->user();
        $first = $this->subscription($user);
        $second = $this->subscription($user);
        $service = new SubscriptionSlotService();
        $service->transition($user->id, $first->id, 'active', 100);
        $service->transition($user->id, $first->id, 'suspended', 101);
        $this->expectExceptionMessage('subscription_slot_occupied');
        $service->transition($user->id, $second->id, 'active', 102);
    }

    public function test_expiry_is_reclaimed_at_the_exact_boundary_inside_activation_transaction(): void
    {
        $user = $this->user();
        $first = $this->subscription($user, 'active', 200);
        $second = $this->subscription($user);
        $service = new SubscriptionSlotService();
        $service->reserve($user->id, $first->id, 100);
        try {
            $service->transition($user->id, $second->id, 'active', 199);
            $this->fail('The first reservation must remain live before its boundary.');
        } catch (DomainException $exception) {
            $this->assertSame('subscription_slot_occupied', $exception->getMessage());
        }
        $service->transition($user->id, $second->id, 'active', 200);
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame(200, $first->fresh()->ended_at);
        $this->assertSame($second->id, (int) DB::table('tx_subscription_slot')->value('subscription_id'));
    }

    public function test_enclosing_transaction_failure_rolls_back_status_and_slot_together(): void
    {
        $user = $this->user();
        $subscription = $this->subscription($user);
        try {
            DB::transaction(function () use ($user, $subscription) {
                (new SubscriptionSlotService())->transition($user->id, $subscription->id, 'active', 100);
                throw new \RuntimeException('Simulated fulfillment failure.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated fulfillment failure.', $exception->getMessage());
        }
        $this->assertSame('pending', $subscription->fresh()->status);
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
    }

    public function test_releasing_a_live_subscription_cannot_bypass_capacity(): void
    {
        $user = $this->user();
        $subscription = $this->subscription($user, 'suspended');
        $service = new SubscriptionSlotService();
        $service->reserve($user->id, $subscription->id, 100);
        $this->expectExceptionMessage('subscription_still_reserves_slot');
        $service->release($user->id, $subscription->id, 100);
    }

    public function test_revocation_releases_capacity_and_preserves_the_empty_lock_row(): void
    {
        $user = $this->user();
        $subscription = $this->subscription($user, 'active');
        $service = new SubscriptionSlotService();
        $service->reserve($user->id, $subscription->id, 100);
        $service->transition($user->id, $subscription->id, 'revoked', 101);
        $service->transition($user->id, $subscription->id, 'revoked', 102);
        $this->assertSame(101, $subscription->fresh()->ended_at);
        $slot = DB::table('tx_subscription_slot')->first();
        $this->assertNull($slot->subscription_id);
        $this->assertNull($slot->reserved_at);
        $this->assertSame(1, DB::table('tx_subscription_slot')->count());
        $this->expectExceptionMessage('subscription_revoked');
        $service->transition($user->id, $subscription->id, 'active', 102);
    }

    public function test_database_rejects_another_users_subscription_in_a_slot(): void
    {
        $user = $this->user();
        $otherUser = $this->user();
        $subscription = $this->subscription($otherUser, 'active');
        $this->expectException(QueryException::class);
        DB::table('tx_subscription_slot')->insert([
            'user_id' => $user->id, 'slot_no' => 1, 'subscription_id' => $subscription->id,
            'row_version' => 1, 'created_at' => 100, 'updated_at' => 100,
        ]);
    }

    public function test_database_rejects_a_second_slot_even_with_a_different_slot_number(): void
    {
        $user = $this->user();
        $subscription = $this->subscription($user, 'active');
        (new SubscriptionSlotService())->reserve($user->id, $subscription->id, 100);
        $this->expectException(QueryException::class);
        DB::table('tx_subscription_slot')->insert([
            'user_id' => $user->id, 'slot_no' => 2, 'row_version' => 1,
            'created_at' => 100, 'updated_at' => 100,
        ]);
    }

    public function test_database_rejects_current_terms_owned_by_another_subscription(): void
    {
        $user = $this->user();
        $first = $this->subscription($user);
        $second = $this->subscription($user);
        $terms = $this->terms($first);
        $this->expectException(QueryException::class);
        $second->current_terms_id = $terms->id;
        $second->save();
    }

    public function test_terms_are_immutable_and_json_preserves_decimal_values_and_timestamps(): void
    {
        $terms = $this->terms($this->subscription($this->user()));
        $json = $terms->toArray();
        $this->assertSame((string) $terms->id, $json['id']);
        $this->assertSame('9007199254740993', $json['quota_bytes']);
        $this->assertSame('1', $json['version_no']);
        $this->assertSame(100, $json['effective_from']);
        $this->assertNull($json['effective_until']);
        $this->assertIsInt($terms->id);
        $terms->quota_bytes = '1';
        $this->expectException(LogicException::class);
        $terms->save();
    }

    public function test_history_model_deletion_is_rejected(): void
    {
        $terms = $this->terms($this->subscription($this->user()));
        $this->expectException(LogicException::class);
        $terms->delete();
    }

    public function test_zero_quota_and_overuse_are_preserved_in_shadow_period(): void
    {
        $subscription = $this->subscription($this->user());
        $terms = $this->terms($subscription);
        $period = UsagePeriod::create([
            'subscription_id' => $subscription->id, 'terms_id' => $terms->id, 'period_no' => 1,
            'starts_at' => null, 'ends_at' => null, 'quota_bytes' => '0',
            'upload_bytes' => '4', 'download_bytes' => '7', 'billable_bytes' => '11',
            'status' => 'shadow', 'boundary_kind' => 'legacy_boundary_unknown',
        ]);
        $this->assertSame('0', $period->fresh()->quota_bytes);
        $this->assertSame('11', $period->fresh()->billable_bytes);
        $this->assertNull($period->fresh()->starts_at);
    }

    public function test_subject_binding_cannot_be_reassigned(): void
    {
        $user = $this->user();
        $first = $this->subscription($user);
        $second = $this->subscription($user);
        $subject = AccessSubject::create([
            'subscription_id' => $first->id, 'provider_id' => 'legacy-shadow',
            'provider_subject_ref' => 'user:'.$user->id, 'status' => 'pending',
        ]);
        $subject->subscription_id = $second->id;
        $this->expectException(LogicException::class);
        $subject->save();
    }

    public function test_shadow_references_do_not_block_existing_legacy_user_deletion(): void
    {
        $user = $this->user();
        $subscription = $this->subscription($user, 'active');
        (new SubscriptionSlotService())->reserve($user->id, $subscription->id, 100);
        $user->delete();
        $this->assertNotNull($subscription->fresh());
        $this->assertSame(1, DB::table('tx_subscription_slot')->count());
    }

    public function test_rollback_refuses_to_erase_backfilled_data(): void
    {
        $this->subscription($this->user());
        try {
            $this->migration()->down();
            $this->fail('Automatic rollback must not delete business history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('contains data', $exception->getMessage());
        }
        $this->assertSame(1, Subscription::query()->count());
        $this->assertTrue(Schema::hasTable('tx_migration_checkpoint'));
    }

    public function test_empty_expansion_can_be_rolled_back_and_reapplied(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('tx_subscription'));
        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('tx_subscription'));
    }

    public function test_schema_replay_preserves_backfilled_data(): void
    {
        $subscription = $this->subscription($this->user());
        $this->migration()->up();
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame($subscription->id, Subscription::query()->first()->id);
    }

    public function test_schema_replay_recovers_a_missing_later_table_and_constraint(): void
    {
        Schema::drop('tx_migration_checkpoint');
        Schema::table('tx_subscription_slot', function (Blueprint $table) {
            $table->dropUnique('tx_slot_capacity_uq');
        });
        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('tx_migration_checkpoint'));
        $this->assertTrue(Schema::hasIndex('tx_subscription_slot', ['user_id'], 'unique'));
    }

    public function test_schema_replay_recovers_a_missing_current_terms_foreign_key(): void
    {
        Schema::table('tx_subscription', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'mysql' ? 'tx_sub_current_terms_fk' : ['current_terms_id', 'id']);
        });
        $this->migration()->up();
        $keys = Schema::getForeignKeys('tx_subscription');
        $this->assertCount(1, $keys);
        $this->assertSame(['current_terms_id', 'id'], $keys[0]['columns']);
    }

    public function test_schema_replay_rejects_an_existing_table_with_missing_required_columns(): void
    {
        Schema::drop('tx_migration_checkpoint');
        Schema::create('tx_migration_checkpoint', function (Blueprint $table) {
            $table->bigIncrements('id');
        });
        $this->expectExceptionMessage('schema mismatch: tx_migration_checkpoint.run_key');
        $this->migration()->up();
    }

    public function test_real_mysql_sessions_race_to_one_activation_with_losing_transactions_rolled_back(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires a dedicated MySQL database and pcntl for independent concurrent sessions.');
        }
        $user = $this->user();
        $subscriptions = [];
        for ($i = 0; $i < 32; $i++) {
            $subscriptions[] = $this->subscription($user)->id;
        }
        $connectionName = DB::getDefaultConnection();
        $directory = sys_get_temp_dir().'/txboard-slot-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        try {
            DB::disconnect($connectionName);
            for ($worker = 0; $worker < 32; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Could not fork subscription race worker.');
                }
                if ($pid === 0) {
                    $exitCode = 2;
                    try {
                        DB::purge($connectionName);
                        $deadline = microtime(true) + 20;
                        while (!is_file($directory.'/go') && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (!is_file($directory.'/go')) {
                            throw new \RuntimeException('Subscription race barrier timed out.');
                        }
                        (new SubscriptionSlotService())->transition($user->id, $subscriptions[$worker], 'active', 100);
                        $result = ['result' => 'active'];
                        $exitCode = 0;
                    } catch (DomainException $exception) {
                        $result = ['result' => $exception->getMessage()];
                        $exitCode = $exception->getMessage() === 'subscription_slot_occupied' ? 0 : 2;
                    } catch (\Throwable $exception) {
                        $result = ['error' => get_class($exception).': '.$exception->getMessage()];
                    }
                    file_put_contents($directory.'/worker-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                    exit($exitCode);
                }
                $children[] = $pid;
            }
            touch($directory.'/go');
            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wexitstatus($status);
            }
            $children = [];
            DB::purge($connectionName);
            $results = [];
            for ($worker = 0; $worker < 32; $worker++) {
                $result = json_decode((string) file_get_contents($directory.'/worker-'.$worker), true, flags: JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $result, json_encode($result));
                $results[] = $result['result'];
            }
            $counts = array_count_values($results);
            $this->assertSame(array_fill(0, 32, 0), $exitCodes);
            $this->assertSame(1, $counts['active'] ?? 0);
            $this->assertSame(31, $counts['subscription_slot_occupied'] ?? 0);
            $this->assertSame(1, Subscription::query()->where('status', 'active')->count());
            $this->assertSame(31, Subscription::query()->where('status', 'pending')->count());
            $this->assertSame(1, DB::table('tx_subscription_slot')->whereNotNull('subscription_id')->count());
            $this->assertSame(Subscription::query()->where('status', 'active')->value('id'),
                (int) DB::table('tx_subscription_slot')->value('subscription_id'));
        } finally {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::purge($connectionName);
        }
    }

    private function clearDedicatedMysqlFixture(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach ([
                'tx_legacy_subscription_map', 'tx_legacy_subscription_exception', 'tx_migration_checkpoint',
                'tx_usage_adjustment', 'tx_access_subject', 'tx_usage_period', 'tx_subscription_slot',
                'tx_subscription_terms_transition', 'tx_subscription_terms', 'tx_subscription', 'tx_user',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function test_schema_replay_rejects_a_named_index_with_different_columns(): void
    {
        Schema::table('tx_subscription_slot', function (Blueprint $table) {
            $table->dropUnique('tx_slot_capacity_uq');
            $table->unique('slot_no', 'tx_slot_capacity_uq');
        });
        $this->expectExceptionMessage('index mismatch: tx_subscription_slot.tx_slot_capacity_uq');
        $this->migration()->up();
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_10_12_000001_create_subscription_shadow_tables.php');
    }

    private function user(): User
    {
        return User::withoutEvents(fn () => User::create([
            'email' => uniqid('shadow-', true).'@example.test', 'password' => 'test-only-hash',
        ]));
    }

    private function subscription(User $user, string $status = 'pending', ?int $expiresAt = null): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id, 'plan_id' => null, 'status' => $status,
            'starts_at' => 100, 'expires_at' => $expiresAt,
        ]);
    }

    private function terms(Subscription $subscription): SubscriptionTerms
    {
        return SubscriptionTerms::create([
            'subscription_id' => $subscription->id, 'version_no' => 1,
            'effective_from' => 100, 'effective_until' => null, 'quota_bytes' => '9007199254740993',
            'reset_policy' => ['kind' => 'legacy_snapshot_unknown'], 'content_hash' => str_repeat('a', 64),
        ]);
    }
}
