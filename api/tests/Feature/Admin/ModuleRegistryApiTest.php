<?php

namespace Tests\Feature\Admin;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    private string $securePath;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->makeAdmin());
        $this->securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    public function test_module_registry_exposes_current_subsystems_through_one_read_only_inventory(): void
    {
        Plugin::create([
            'name' => 'EPay',
            'code' => 'epay',
            'version' => '1.0.0',
            'is_enabled' => true,
            'config' => null,
            'installed_at' => now(),
        ]);

        $response = $this->getJson("/txapi/admin/{$this->securePath}/modules");

        $response->assertOk()
            ->assertJsonStructure(['data','request_id']);

        $modules = collect($response->json('data.modules'))->keyBy('id');

        $this->assertFalse($modules->has('agent_ops'));
        $this->assertTrue($modules->has('theme.txboard'));
        $this->assertTrue($modules->has('epay'));
        foreach (['identity', 'subscription', 'billing', 'usage', 'network', 'installation'] as $owner) {
            $this->assertTrue($modules->has('core.'.$owner));
            $this->assertSame('core', $modules['core.'.$owner]['type']);
        }
        $this->assertContains('subscription.entitlements', array_column($modules['core.subscription']['services'], 'id'));
        $this->assertNotContains('subscription.locked-entitlements', array_column($modules['core.subscription']['services'], 'id'));

        $this->assertSame('theme', $modules['theme.txboard']['type']);
        $this->assertTrue($modules['theme.txboard']['active']);
        $this->assertContains('theme', $modules['theme.txboard']['capabilities']);

        $this->assertSame('plugin', $modules['epay']['type']);
        $this->assertSame('bundled', $modules['epay']['source']);
        $this->assertTrue($modules['epay']['installed']);
        $this->assertTrue($modules['epay']['enabled']);
        $this->assertContains('payment.provider', $modules['epay']['capabilities']);

        $this->assertSame(count($modules), $response->json('data.summary.total'));
    }

    public function test_current_plugin_navigation_uses_explicit_identity_without_aliases(): void
    {
        $path = base_path('plugins/NavFixture');
        File::ensureDirectoryExists($path.'/admin/dist');
        File::put($path.'/admin/dist/index.html', '<!doctype html>');
        File::put($path.'/config.json', json_encode([
            'name' => 'Navigation Fixture', 'code' => 'nav_fixture', 'version' => '1.0.0',
            'description' => 'Current navigation fixture', 'author' => 'TXBoard tests',
            'package' => ['schema' => 1],
            'admin_menus' => [[
                'id' => 'dashboard', 'title' => 'Dashboard', 'path' => 'dashboard',
                'icon' => 'layout-dashboard', 'order' => 20, 'app' => 'admin/index.html',
            ]],
        ], JSON_THROW_ON_ERROR));
        try {
            $this->getJson("/txapi/admin/{$this->securePath}/modules/nav_fixture")
                ->assertOk()->assertJsonPath('data.admin.navigation.0.id', 'dashboard')
                ->assertJsonPath('data.admin.navigation.0.path', 'dashboard')
                ->assertJsonPath('data.admin.navigation.0.order', 20);
            $config = json_decode(File::get($path.'/config.json'), true);
            $config['admin_menus'][0]['path'] = '/dashboard/';
            File::put($path.'/config.json', json_encode($config, JSON_THROW_ON_ERROR));
            $response = $this->getJson("/txapi/admin/{$this->securePath}/modules")->assertOk();
            $this->assertNotContains('nav_fixture', array_column($response->json('data.modules'), 'id'));
            $this->assertContains('Plugin metadata is invalid', array_column($response->json('data.errors'), 'message'));
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_payment_type_without_current_contract_cannot_claim_a_provider_capability(): void
    {
        $path = base_path('plugins/OldPaymentDeclarationFixture');
        File::ensureDirectoryExists($path);
        $manifest = [
            'name' => 'Old Payment Declaration', 'code' => 'old_payment_declaration_fixture', 'version' => '1.0.0',
            'description' => 'No current protocol declaration', 'author' => 'TXBoard tests',
            'type' => 'payment', 'package' => ['schema' => 1],
        ];
        File::put($path.'/config.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        try {
            $response = $this->getJson("/txapi/admin/{$this->securePath}/modules")->assertOk();
            $this->assertNotContains($manifest['code'], array_column($response->json('data.modules'), 'id'));
            $this->assertContains('Plugin metadata is invalid', array_column($response->json('data.errors'), 'message'));
            $manifest['payment'] = ['contract' => 'native-payment-provider/1',
                'provider' => 'FixturePay', 'snapshot_schema' => 'fixturepay-dispatch/1'];
            File::put($path.'/config.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->getJson("/txapi/admin/{$this->securePath}/modules/{$manifest['code']}")
                ->assertOk()->assertJsonPath('data.capabilities', ['payment.provider'])
                ->assertJsonPath('data.installed', false)->assertJsonPath('data.enabled', false);
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_discovery_errors_do_not_expose_plugin_admin_references_or_invalid_ids(): void
    {
        $secret = 'token-for-regression-test';
        $appPath = base_path('plugins/UnsafeAdminAppFixture');
        $invalidIdPath = base_path('plugins/InvalidModuleIdFixture');
        File::ensureDirectoryExists($appPath);
        File::ensureDirectoryExists($invalidIdPath);

        file_put_contents($appPath . '/config.json', json_encode([
            'name' => 'Unsafe Admin App Fixture',
            'code' => 'unsafe_admin_fixture',
            'version' => '1.0.0',
            'description' => 'Missing admin app reference',
            'author' => 'TXBoard tests',
            'package' => ['schema' => 1],
            'admin_menus' => [['id' => 'page', 'title' => 'Page', 'path' => 'page', 'app' => "admin/{$secret}.html"]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($invalidIdPath . '/config.json', json_encode([
            'name' => 'Invalid Module ID Fixture',
            'code' => "Bearer {$secret}",
            'version' => '1.0.0',
            'description' => 'Invalid module identity',
            'author' => 'TXBoard tests',
            'package' => ['schema' => 1],
        ], JSON_THROW_ON_ERROR));

        try {
            $response = $this->getJson("/txapi/admin/{$this->securePath}/modules");
            $response->assertOk();

            $errors = collect($response->json('data.errors'));
            $appError = $errors->first(fn (array $error) => $error['module_id'] === 'unsafe_admin_fixture');
            $this->assertNotNull($appError);
            $this->assertSame('Plugin admin app validation failed', $appError['message']);
            $this->assertContains('unsafe_admin_fixture', array_column($response->json('data.modules'), 'id'));

            $invalidIdError = $errors->first(fn (array $error) =>
                $error['adapter'] === 'plugin' && $error['message'] === 'Plugin metadata is invalid');
            $this->assertNotNull($invalidIdError);
            $this->assertNull($invalidIdError['module_id']);
            $this->assertStringNotContainsString($secret, $response->getContent());
        } finally {
            File::deleteDirectory($appPath);
            File::deleteDirectory($invalidIdPath);
        }
    }
    public function test_system_module_identity_cannot_be_shadowed_by_user_plugin(): void
    {
        $path = base_path('plugins/ShadowTheme');
        File::ensureDirectoryExists($path);

        file_put_contents($path . '/config.json', json_encode([
            'name' => 'Shadow Theme',
            'code' => 'theme.txboard',
            'version' => '9.9.9',
            'description' => 'Should not replace the system module identity',
            'author' => 'test',
            'package' => ['schema' => 1],
        ], JSON_THROW_ON_ERROR));

        try {
            $response = $this->getJson("/txapi/admin/{$this->securePath}/modules");
            $response->assertOk();

            $modules = collect($response->json('data.modules'))->keyBy('id');
            $this->assertSame('theme', $modules['theme.txboard']['type']);
            $this->assertSame('system', $modules['theme.txboard']['source']);

            $collision = collect($response->json('data.errors'))
                ->first(fn (array $error) => ($error['module_id'] ?? null) === 'theme.txboard'
                    && ($error['adapter'] ?? null) === 'plugin');

            $this->assertNotNull($collision);
            $this->assertSame('plugin', $collision['adapter']);
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_module_registry_detail_is_read_only_and_returns_404_for_unknown_id(): void
    {
        $this->getJson("/txapi/admin/{$this->securePath}/modules/theme.txboard")
            ->assertOk()
            ->assertJsonPath('data.id', 'theme.txboard')
            ->assertJsonPath('data.type', 'theme');

        $this->getJson("/txapi/admin/{$this->securePath}/modules/not_found")
            ->assertNotFound()
            ->assertJsonPath('error.message', 'Module not found');

        $this->postJson("/txapi/admin/{$this->securePath}/modules", [])
            ->assertStatus(405);
    }

    private function makeAdmin(): User
    {
        return User::create([
            'email' => 'module-registry-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000009001',
            'token' => 'mmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmm',
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
    }
}
