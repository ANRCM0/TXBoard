<?php

namespace Tests\Feature\Installation;

use App\Domains\CredentialProvider\Contracts\ProviderClient;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Domains\Subscription\Services\NativeCredentialProviderService;
use App\Domains\Network\NativeProviderNodeAuthorization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class NativeProviderPreparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'provider_preparation',
            'database.connections.provider_preparation' => array_merge(config('database.connections.sqlite'), [
                'database' => ':memory:', 'foreign_key_constraints' => true,
            ]),
            'core_installation.mode' => 'legacy', 'core_installation.worker_epoch' => '1',
            'cache.stores.redis' => ['driver' => 'array'],
        ]);
        DB::purge('provider_preparation');
        Artisan::call('migrate', ['--force' => true]);
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
    }

    protected function tearDown(): void
    {
        DB::purge('provider_preparation');
        parent::tearDown();
    }

    public function test_real_preparation_registration_remains_idempotent_and_cannot_issue_or_open_http(): void
    {
        app(InstallationAuthority::class)->initialize('550e8400-e29b-41d4-a716-446655440000', 'native', 'provider-setup');
        config(['core_installation.mode' => 'native']);
        $transport = Mockery::mock(ProviderClient::class);
        $transport->shouldReceive('configuration')->with('primary-provider')->andReturn([
            'provider_id' => 'primary-provider', 'provider_instance_epoch' => '1',
            'config_digest' => str_repeat('a', 64), 'profiles' => ['vless-uuid/v1'],
        ]);
        $transport->shouldNotReceive('call');
        $transport->shouldNotReceive('metadata');
        $this->app->instance(ProviderClient::class, $transport);
        $arguments = ['provider' => 'primary-provider', '--key' => 'provider-registration'];
        $this->assertSame(0, Artisan::call('core:provider-register', $arguments));
        $first = Artisan::output();
        $this->assertSame(0, Artisan::call('core:provider-register', $arguments));
        $this->assertSame($first, Artisan::output());
        $this->assertSame(1, DB::table('tx_credential_provider_registration')->count());
        $this->assertSame('initializing', DB::table('tx_authority_writer_fence')->value('phase'));
        $this->postJson('/txapi/me/access-subjects', [])->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_NATIVE_NOT_READY');
        $this->getJson('/txapi/readiness')->assertStatus(503);
        $this->getJson('/txapi/node/v1/users')->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_NATIVE_NOT_READY');
        $this->postJson('/txapi/node/v1/handshake')->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_NATIVE_NOT_READY');
        try {
            app(NativeProviderNodeAuthorization::class)->publicTrust();
            $this->fail('Preparing installation cannot advertise active Provider authorization');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_NATIVE_NOT_READY', $error->getMessage());
        }
        try {
            app(NativeCredentialProviderService::class)->issue(1, 1, 'primary-provider', 'vless-uuid/v1', 'blocked', '1');
            $this->fail('Preparing installation cannot issue');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_NATIVE_NOT_READY', $error->getMessage());
        }
        $this->assertSame(0, DB::table('tx_credential_provider_operation')->count());
        $this->assertSame(0, DB::table('tx_access_subject')->count());
    }
}
