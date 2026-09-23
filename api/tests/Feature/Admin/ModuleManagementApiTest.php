<?php

namespace Tests\Feature\Admin;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleManagementApiTest extends TestCase
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

    public function test_supported_operations_are_derived_from_lifecycle_adapters(): void
    {
        Plugin::create([
            'name' => 'EPay',
            'code' => 'epay',
            'version' => '1.0.0',
            'is_enabled' => true,
            'config' => null,
            'installed_at' => now(),
        ]);

        $this->getJson("/api/v2/{$this->securePath}/module/theme.txboard/lifecycle")
            ->assertOk()
            ->assertJsonPath('data.module_id', 'theme.txboard')
            ->assertJsonPath('data.operations', []);

        $this->createTheme('CustomTheme');

        try {
            $this->getJson("/api/v2/{$this->securePath}/module/theme.customtheme/lifecycle")
                ->assertOk()
                ->assertJsonPath('data.module_id', 'theme.customtheme')
                ->assertJsonPath('data.operations', ['enable', 'uninstall']);

            $this->getJson("/api/v2/{$this->securePath}/module/agent_ops/lifecycle")
                ->assertOk()
                ->assertJsonPath('data.module_id', 'agent_ops')
                ->assertJsonPath('data.operations', []);

            $this->getJson("/api/v2/{$this->securePath}/module/epay/lifecycle")
                ->assertOk()
                ->assertJsonPath('data.operations', [
                    'disable',
                    'upgrade',
                    'uninstall',
                ]);
        } finally {
            File::deleteDirectory(base_path('theme/CustomTheme'));
        }
    }

    public function test_theme_enable_executes_through_module_lifecycle_and_returns_refreshed_state(): void
    {
        $this->createTheme('CustomTheme');
        admin_setting(['frontend_theme' => 'TXBoard']);

        try {
            $response = $this->postJson(
                "/api/v2/{$this->securePath}/module/theme.customtheme/lifecycle/enable"
            );

            $response->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('data.operation', 'enable')
                ->assertJsonPath('data.success', true)
                ->assertJsonPath('data.module.id', 'theme.customtheme')
                ->assertJsonPath('data.module.active', true)
                ->assertJsonPath('data.error', null);

            $this->assertSame('CustomTheme', admin_setting('frontend_theme'));

            $this->getJson("/api/v2/{$this->securePath}/module/theme.customtheme/lifecycle")
                ->assertOk()
                ->assertJsonPath('data.operations', []);
        } finally {
            File::deleteDirectory(base_path('theme/CustomTheme'));
        }
    }

    public function test_unsupported_theme_operation_returns_conflict_with_stable_lifecycle_error(): void
    {
        $response = $this->postJson(
            "/api/v2/{$this->securePath}/module/theme.txboard/lifecycle/disable"
        );

        $response->assertStatus(409)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('data.operation', 'disable')
            ->assertJsonPath('data.success', false)
            ->assertJsonPath('data.error.code', 'unsupported_operation')
            ->assertJsonPath('data.error.adapter', 'theme');
    }

    public function test_module_without_lifecycle_adapter_returns_conflict(): void
    {
        $response = $this->postJson(
            "/api/v2/{$this->securePath}/module/agent_ops/lifecycle/enable"
        );

        $response->assertStatus(409)
            ->assertJsonPath('data.error.code', 'unsupported_module_type')
            ->assertJsonPath('data.operation', 'enable');
    }

    public function test_unknown_module_and_invalid_operation_have_stable_http_failures(): void
    {
        $this->getJson("/api/v2/{$this->securePath}/module/not_found/lifecycle")
            ->assertNotFound()
            ->assertJsonPath('message', 'Module not found');

        $this->postJson(
            "/api/v2/{$this->securePath}/module/not_found/lifecycle/enable"
        )
            ->assertNotFound()
            ->assertJsonPath('data.error.code', 'module_not_found');

        $this->postJson(
            "/api/v2/{$this->securePath}/module/theme.txboard/lifecycle/restart"
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid module lifecycle operation')
            ->assertJsonPath('data.operation', 'restart')
            ->assertJsonPath('data.allowed', [
                'install',
                'enable',
                'disable',
                'upgrade',
                'uninstall',
            ]);
    }

    private function createTheme(string $name): void
    {
        $path = base_path('theme/' . $name);
        File::ensureDirectoryExists($path);

        file_put_contents($path . '/config.json', json_encode([
            'name' => $name,
            'description' => $name . ' test theme',
            'author' => 'TXBoard tests',
            'version' => '1.0.0',
            'compatibility' => ['txboard' => '*'],
            'configs' => [],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($path . '/dashboard.blade.php', '<div>test theme</div>');
    }

    private function makeAdmin(): User
    {
        return User::create([
            'email' => 'module-management-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000009002',
            'token' => 'nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn',
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
