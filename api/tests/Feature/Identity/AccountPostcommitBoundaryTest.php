<?php

namespace Tests\Feature\Identity;

use App\Domains\AuthorityMigration\AuthorityNativeInventory;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Independent SQL commits exercise the committing listener before PDO commit. */
class AccountPostcommitBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'account_postcommit_boundary',
            'database.connections.account_postcommit_boundary' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'core_installation.mode' => 'legacy',
            'core_shadow.isolated_copy' => true,
            'authority_writer_fence' => [
                'enabled' => true, 'worker_epoch' => '1', 'worker_role' => 'legacy',
            ],
        ]);
        DB::purge('account_postcommit_boundary');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $fence = app(AuthorityWriterFence::class);
        $fence->initialize();
        $fence->expand('account-boundary-inventory', '1');
        $fence->extendFinance('account-boundary-finance', '2');
        $fence->extendNative('account-boundary-native', '3');
        config(['authority_writer_fence' => [
            'enabled' => true, 'worker_epoch' => '4', 'worker_role' => 'legacy',
            'worker_scope' => AuthorityNativeInventory::SCOPE,
            'worker_version' => AuthorityNativeInventory::VERSION,
        ]]);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        DB::purge('account_postcommit_boundary');
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_commit_time_schedule_failure_rolls_back_account_and_intents_without_node_dispatch(): void
    {
        $user = User::withoutEvents(fn () => User::create([
            'email' => 'schedule-boundary@example.test', 'password' => 'fixture-only',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)),
            'group_id' => 1, 'expired_at' => null, 'next_reset_at' => null,
        ]));
        $expiry = time() + 86400;
        $service = \Mockery::mock(TrafficResetService::class);
        $service->shouldReceive('calculateNextResetTime')->once()->andReturnUsing(function (User $current) use ($user, $expiry): never {
            $this->assertSame($user->id, $current->id);
            $this->assertSame($expiry, (int) $current->expired_at);
            $this->assertSame($expiry, (int) User::findOrFail($user->id)->expired_at);
            $this->assertSame(1, DB::table('tx_authority_postcommit_intent')->count());
            $this->assertSame(1, DB::table('tx_authority_postcommit_delivery')->count());
            throw new \RuntimeException('Synthetic schedule calculation failure');
        });
        $this->app->instance(TrafficResetService::class, $service);

        try {
            DB::transaction(function () use ($user, $expiry): void {
                app(AuthorityWriterFence::class)->admit('admin.user.update');
                $user->update(['expired_at' => $expiry]);
            });
            $this->fail('The committing listener must propagate its calculation failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic schedule calculation failure', $error->getMessage());
        }

        $this->assertNull($user->fresh()->expired_at);
        $this->assertNull($user->fresh()->next_reset_at);
        $this->assertSame(0, DB::table('tx_authority_postcommit_intent')->count());
        $this->assertSame(0, DB::table('tx_authority_postcommit_delivery')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        Bus::assertNothingDispatched();
    }
}
