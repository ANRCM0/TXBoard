<?php

namespace Tests\Feature\System;

use App\Http\Middleware\InitializePlugins;
use App\Models\User;
use App\Services\InstallState;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\Request;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuntimeBootRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_proves_the_application_booted(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_health_endpoint_skips_plugin_initialization(): void
    {
        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldNotReceive('initializeEnabledPlugins');

        $middleware = new InitializePlugins($pluginManager);
        $response = $middleware->handle(
            Request::create('/api/health', 'GET'),
            fn () => response()->json(['status' => 'ok'])
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_application_provider_imports_resolve_to_real_classes(): void
    {
        foreach (glob(app_path('Providers') . '/*.php') as $providerFile) {
            $source = (string) file_get_contents($providerFile);
            preg_match_all('/^use\s+(App\\\\[^;]+);/m', $source, $matches);

            foreach ($matches[1] as $import) {
                $class = preg_replace('/\s+as\s+.+$/i', '', trim($import));
                $this->assertTrue(
                    class_exists($class) || interface_exists($class) || trait_exists($class),
                    basename($providerFile) . " imports missing application class {$class}"
                );
            }
        }
    }

    public function test_install_state_is_based_on_a_real_administrator(): void
    {
        $state = app(InstallState::class);
        $this->assertFalse($state->isInstalled());

        User::create([
            'email' => 'install-state-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000999',
            'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 1,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertTrue($state->isInstalled());
    }
}
