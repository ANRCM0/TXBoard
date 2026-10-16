<?php

namespace Tests\Feature\Installation;

use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Models\Server;
use App\Models\User;
use App\Listeners\EnforceInstallationAuthority;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Domains\Subscription\Services\PlanPublicationService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class InstallationRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['core_installation.mode' => 'legacy', 'core_installation.worker_epoch' => '1',
            'authority_writer_fence.enabled' => false, 'cache.stores.redis' => ['driver' => 'array']]);
        // Laravel disables command events in PHPUnit; exercise the real CLI admission bridge.
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
    }

    public function test_readiness_distinguishes_legacy_missing_native_missing_and_native_initializing(): void
    {
        $this->getJson('/txapi/readiness')->assertOk()->assertJsonPath('data.ready', true);
        config(['core_installation.mode' => 'native']);
        $this->getJson('/txapi/readiness')->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_RECORD_REQUIRED');
        $this->native();
        $response = $this->getJson('/txapi/readiness')->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_NATIVE_NOT_READY');
        $this->assertNotEmpty($response->json('request_id'));
        $this->assertStringNotContainsString(self::ID, $response->getContent());
        $this->assertStringNotContainsString('runtime-fixture', $response->getContent());
    }

    public function test_liveness_stays_database_independent_while_native_business_http_refuses(): void
    {
        $this->native();
        $this->getJson('/txapi/user/me')->assertStatus(503);
        $this->get('/')->assertStatus(503);
        $connection = config('database.default');
        config(['database.default' => 'not-a-configured-connection']);
        try {
            $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
            $this->getJson('/txapi/health')->assertOk()->assertJsonPath('data.status', 'ok');
            $this->getJson('/txapi/readiness')->assertStatus(503)
                ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_STORAGE_FAILED');
        } finally {
            config(['database.default' => $connection]);
        }
    }

    public function test_environment_downgrade_and_stale_epoch_do_not_reopen_persistent_native_state(): void
    {
        $this->native();
        $before = DB::table('tx_authority_writer_fence')->first();
        config(['core_installation.mode' => 'legacy']);
        $this->getJson('/txapi/readiness')->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_WORKER_MISMATCH');
        config(['core_installation.mode' => 'native', 'core_installation.worker_epoch' => '2']);
        $this->getJson('/txapi/readiness')->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_WORKER_MISMATCH');
        $this->assertEquals($before, DB::table('tx_authority_writer_fence')->first());
    }

    public function test_queue_and_business_commands_refuse_before_executing_legacy_work(): void
    {
        $this->native();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldNotReceive('fire');
        $this->refuses('NATIVE_NOT_READY', fn () => Event::dispatch(new JobProcessing('sync', $job)));
        $this->refuses('NATIVE_NOT_READY', fn () => Artisan::call('reset:traffic'));
        $this->refuses('NATIVE_NOT_READY', fn () => Artisan::call('migrate:rollback'));
        $this->assertSame(0, Artisan::call('core:installation', ['operation' => 'status']));
        $this->assertSame('native', json_decode(Artisan::output(), true)['mode']);
        $this->assertSame(0, Artisan::call('core:installation', ['operation' => 'native-preparing']));
        $this->assertSame('', Artisan::output());
        $this->assertSame(1, Artisan::call('core:installation', ['operation' => 'activate']));
        $this->assertStringContainsString('INSTALLATION_AUTHORITY_COMMAND_INVALID', Artisan::output());
        $this->assertSame('initializing', DB::table('tx_authority_writer_fence')->value('phase'));
    }

    public function test_image_bootstrap_and_http_process_start_do_not_depend_on_database_readiness(): void
    {
        $connection = config('database.default');
        config(['database.default' => 'not-a-configured-connection', 'core_installation.mode' => 'native']);
        $listener = new EnforceInstallationAuthority();
        try {
            foreach (['storage:link', 'txboard:assert-native-schema', 'txboard:install-status',
                'package:discover', 'key:generate', 'config:cache', 'octane:start', 'octane:status', 'octane:swoole',
                'octane:roadrunner', 'octane:frankenphp'] as $command) {
                $listener->commandStarting(new CommandStarting($command, new ArrayInput([]), new NullOutput()));
                $this->addToAssertionCount(1);
            }
            $this->refuses('STORAGE_FAILED',
                fn () => $listener->commandStarting(new CommandStarting('reset:traffic', new ArrayInput([]), new NullOutput())));
        } finally {
            config(['database.default' => $connection]);
        }
    }

    public function test_fence_disabled_does_not_allow_old_writer_or_node_snapshot_and_delta(): void
    {
        $this->native();
        $this->refuses('NATIVE_WRITER_REJECTED',
            fn () => DB::transaction(fn () => app(AuthorityWriterFence::class)->admit('order.fulfill')));
        $this->refuses('NATIVE_NOT_READY', fn () => ServerService::getNativeAvailableUsers(new Server()));
        $this->refuses('NATIVE_NOT_READY', fn () => NodeSyncService::notifyUserChanged(new User()));
    }

    public function test_operator_initialization_command_is_idempotent_and_has_no_native_activation(): void
    {
        $arguments = ['operation' => 'init', '--installation-id' => self::ID,
            '--mode' => 'native', '--key' => 'runtime-fixture'];
        $this->assertSame(0, Artisan::call('core:installation', $arguments));
        $result = Artisan::output();
        $this->assertSame(0, Artisan::call('core:installation', $arguments));
        $this->assertSame($result, Artisan::output());
        $this->assertSame(1, Artisan::call('core:installation', array_replace($arguments, ['--mode' => 'legacy'])));
        $this->assertStringContainsString('INSTALLATION_AUTHORITY_BOOTSTRAP_CONFLICT', Artisan::output());
    }

    public function test_real_catalog_preparation_uses_persistent_authority_and_cannot_enable_runtime(): void
    {
        $this->native();
        $now = time();
        $plan = DB::table('tx_plan')->insertGetId(['name' => 'Prepared native plan', 'transfer_enable' => 2,
            'prices' => '{"monthly":"12.50"}', 'show' => 1, 'sell' => 1, 'renew' => 1,
            'created_at' => $now, 'updated_at' => $now]);
        $service = app(PlanPublicationService::class);
        $published = $service->publish($plan, 'runtime-publication', 'USD');
        $this->assertSame($published, $service->publish($plan, 'runtime-publication', 'USD'));
        $this->assertSame('plan-publication/v1', $published['schema']);
        $this->assertSame('1250', $published['offers'][0]['price_minor']);
        $this->assertSame('published', DB::table('tx_plan_offer_availability')->value('status'));
        $this->getJson('/txapi/readiness')->assertStatus(503);
        config(['core_installation.mode' => 'legacy']);
        $this->refuses('WORKER_MISMATCH', fn () => $service->publish($plan, 'another-key', 'USD'));
        $this->assertSame(1, DB::table('tx_plan_revision')->count());
    }

    public function test_legacy_record_keeps_readiness_and_writer_compatibility_without_relabeling_data(): void
    {
        $authority = app(InstallationAuthority::class);
        $record = $authority->initialize(self::ID, 'legacy', 'legacy-runtime');
        $this->assertSame('open', $record['phase']);
        $this->getJson('/txapi/readiness')->assertOk();
        DB::transaction(fn () => app(AuthorityWriterFence::class)->admit('order.create'));
        $this->refuses('CATALOG_REJECTED', fn () => DB::transaction(fn () => $authority->assertCatalogMutation()));
        $this->assertSame(0, DB::table('tx_subscription')->count());
    }

    public function test_old_schema_replay_preserves_versioned_native_preparation_and_catalog_constraints(): void
    {
        $this->native();
        $record = (array) DB::table('tx_authority_writer_fence')->first();
        (require database_path('migrations/2026_10_14_000001_create_authority_writer_fence.php'))->up();
        (require database_path('migrations/2026_10_12_000002_create_plan_catalog_shadow_tables.php'))->up();
        $this->assertSame($record, (array) DB::table('tx_authority_writer_fence')->first());
        $this->assertSame('initializing', app(InstallationAuthority::class)->inspect()['phase']);
    }

    private function native(): void
    {
        app(InstallationAuthority::class)->initialize(self::ID, 'native', 'runtime-fixture');
        config(['core_installation.mode' => 'native']);
    }

    private function refuses(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected installation refusal');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_'.$code, $error->getMessage());
        }
    }
}
