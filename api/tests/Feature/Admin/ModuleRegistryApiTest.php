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

        $response = $this->getJson("/api/v2/{$this->securePath}/module");

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $modules = collect($response->json('data.modules'))->keyBy('id');

        $this->assertTrue($modules->has('agent_ops'));
        $this->assertTrue($modules->has('theme.txboard'));
        $this->assertTrue($modules->has('epay'));

        $this->assertSame('agent', $modules['agent_ops']['type']);
        $this->assertSame('system', $modules['agent_ops']['source']);
        $this->assertContains('agent.api', $modules['agent_ops']['capabilities']);

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

    public function test_legacy_plugin_navigation_is_projected_safely_into_module_descriptor(): void
    {
        $path = base_path('plugins/NavFixture');
        File::ensureDirectoryExists($path);

        file_put_contents($path . '/config.json', json_encode([
            'name' => 'Navigation Fixture',
            'code' => 'nav_fixture',
            'version' => '1.0.0',
            'description' => 'Navigation projection fixture',
            'author' => 'TXBoard tests',
            'admin_menus' => [
                [
                    'id' => 'dashboard',
                    'title' => 'Dashboard',
                    'path' => '/dashboard/',
                    'icon' => 'layout-dashboard',
                    'order' => 20,
                ],
                [
                    'id' => 'unsafe',
                    'title' => 'Unsafe',
                    'path' => '../config',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        Plugin::create([
            'name' => 'Navigation Fixture',
            'code' => 'nav_fixture',
            'version' => '1.0.0',
            'is_enabled' => true,
            'config' => null,
            'installed_at' => now(),
        ]);

        try {
            $response = $this->getJson("/api/v2/{$this->securePath}/module/nav_fixture");

            $response->assertOk()
                ->assertJsonPath('data.id', 'nav_fixture')
                ->assertJsonPath('data.enabled', true)
                ->assertJsonPath('data.admin.navigation.0.id', 'dashboard')
                ->assertJsonPath('data.admin.navigation.0.title', 'Dashboard')
                ->assertJsonPath('data.admin.navigation.0.path', 'dashboard')
                ->assertJsonPath('data.admin.navigation.0.icon', 'layout-dashboard')
                ->assertJsonPath('data.admin.navigation.0.order', 20);

            $this->assertCount(1, $response->json('data.admin.navigation'));
            $this->assertContains('admin.menu', $response->json('data.capabilities'));
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_invalid_legacy_navigation_is_omitted_without_hiding_plugin(): void
    {
        $path = base_path('plugins/InvalidNavFixture');
        File::ensureDirectoryExists($path);

        file_put_contents($path . '/config.json', json_encode([
            'name' => 'Invalid Navigation Fixture',
            'code' => 'invalid_nav_fixture',
            'version' => '1.0.0',
            'description' => 'Invalid navigation projection fixture',
            'author' => 'TXBoard tests',
            'admin_menus' => [
                [
                    'id' => 'escape',
                    'title' => 'Escape',
                    'path' => '../config',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        Plugin::create([
            'name' => 'Invalid Navigation Fixture',
            'code' => 'invalid_nav_fixture',
            'version' => '1.0.0',
            'is_enabled' => true,
            'config' => null,
            'installed_at' => now(),
        ]);

        try {
            $response = $this->getJson("/api/v2/{$this->securePath}/module/invalid_nav_fixture");

            $response->assertOk()
                ->assertJsonPath('data.id', 'invalid_nav_fixture')
                ->assertJsonMissingPath('data.admin');

            $this->assertNotContains('admin.menu', $response->json('data.capabilities'));
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_system_module_identity_cannot_be_shadowed_by_user_plugin(): void
    {
        $path = base_path('plugins/AgentOps');
        File::ensureDirectoryExists($path);

        file_put_contents($path . '/config.json', json_encode([
            'name' => 'Shadow Agent Ops',
            'code' => 'agent_ops',
            'version' => '9.9.9',
            'description' => 'Should not replace the system module identity',
            'author' => 'test',
        ], JSON_THROW_ON_ERROR));

        try {
            $response = $this->getJson("/api/v2/{$this->securePath}/module");
            $response->assertOk();

            $modules = collect($response->json('data.modules'))->keyBy('id');
            $this->assertSame('agent', $modules['agent_ops']['type']);
            $this->assertSame('system', $modules['agent_ops']['source']);

            $collision = collect($response->json('data.errors'))
                ->first(fn (array $error) => ($error['module_id'] ?? null) === 'agent_ops');

            $this->assertNotNull($collision);
            $this->assertSame('plugin', $collision['adapter']);
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_module_registry_detail_is_read_only_and_returns_404_for_unknown_id(): void
    {
        $this->getJson("/api/v2/{$this->securePath}/module/theme.txboard")
            ->assertOk()
            ->assertJsonPath('data.id', 'theme.txboard')
            ->assertJsonPath('data.type', 'theme');

        $this->getJson("/api/v2/{$this->securePath}/module/not_found")
            ->assertNotFound()
            ->assertJsonPath('message', 'Module not found');

        $this->postJson("/api/v2/{$this->securePath}/module", [])
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
