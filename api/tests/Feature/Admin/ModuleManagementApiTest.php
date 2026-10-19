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

        $this->getJson("/txapi/admin/{$this->securePath}/modules/theme.txboard/operations")
            ->assertOk()
            ->assertJsonPath('data.module_id', 'theme.txboard')
            ->assertJsonPath('data.operations', []);

        $this->createTheme('custom-theme');

        try {
            $this->getJson("/txapi/admin/{$this->securePath}/modules/theme.custom-theme/operations")
                ->assertOk()
                ->assertJsonPath('data.module_id', 'theme.custom-theme')
                ->assertJsonPath('data.operations', ['enable', 'uninstall']);

            $this->getJson("/txapi/admin/{$this->securePath}/modules/agent_ops/operations")->assertNotFound();

            $this->getJson("/txapi/admin/{$this->securePath}/modules/epay/operations")
                ->assertOk()
                ->assertJsonPath('data.operations', [
                    'disable',
                    'upgrade',
                    'uninstall',
                ]);
        } finally {
            File::deleteDirectory(base_path('storage/theme/custom-theme'));
        }
    }

    public function test_theme_enable_executes_through_module_lifecycle_and_returns_refreshed_state(): void
    {
        $this->createTheme('custom-theme');
        admin_setting(['frontend_theme' => 'TXBoard']);

        try {
            $response = $this->postJson(
                "/txapi/admin/{$this->securePath}/modules/theme.custom-theme/operations/enable"
            );

            $response->assertOk()
                                ->assertJsonPath('data.operation', 'enable')
                ->assertJsonPath('data.success', true)
                ->assertJsonPath('data.module.id', 'theme.custom-theme')
                ->assertJsonPath('data.module.active', true)
                ->assertJsonPath('data.error', null);

            $this->assertSame('custom-theme', admin_setting('frontend_theme'));

            $this->getJson("/txapi/admin/{$this->securePath}/modules/theme.custom-theme/operations")
                ->assertOk()
                ->assertJsonPath('data.operations', []);
        } finally {
            File::deleteDirectory(base_path('storage/theme/custom-theme'));
        }
    }

    public function test_unsupported_theme_operation_returns_conflict_with_stable_lifecycle_error(): void
    {
        $response = $this->postJson(
            "/txapi/admin/{$this->securePath}/modules/theme.txboard/operations/disable"
        );

        $response->assertStatus(409)
                        ->assertJsonPath('error.code', 'MODULE_OPERATION_CONFLICT');
    }

    public function test_retired_agent_module_cannot_be_enabled(): void
    {
        $response = $this->postJson(
            "/txapi/admin/{$this->securePath}/modules/agent_ops/operations/enable"
        );

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'MODULE_NOT_FOUND');
    }

    public function test_unknown_module_and_invalid_operation_have_stable_http_failures(): void
    {
        $this->getJson("/txapi/admin/{$this->securePath}/modules/not_found/operations")
            ->assertNotFound()
            ->assertJsonPath('error.message', 'Module not found');

        $this->postJson(
            "/txapi/admin/{$this->securePath}/modules/not_found/operations/enable"
        )
            ->assertNotFound()
            ->assertJsonPath('error.code', 'MODULE_NOT_FOUND');

        $this->postJson(
            "/txapi/admin/{$this->securePath}/modules/theme.txboard/operations/restart"
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MODULE_OPERATION_INVALID');
    }

    private function createTheme(string $name): void
    {
        $path = base_path('storage/theme/' . $name);
        File::ensureDirectoryExists($path);

        File::ensureDirectoryExists($path.'/dist');
        $js = 'console.log("theme")';
        File::put($path.'/dist/index.js', $js);
        File::put($path.'/txboard-theme.json', json_encode([
            'format' => 2, 'name' => $name, 'version' => '1.0.0',
            'entry' => ['type' => 'spa', 'module' => 'dist/index.js'],
            'engines' => ['txboard' => '*', 'browser' => '*', 'contract' => 'core-browser/v1'],
            'browser' => ['contract' => 'core-browser/v1', 'sdk' => ['package' => '@txboard/theme-sdk', 'version' => '*'], 'capabilities' => []],
            'dependencies' => ['runtime' => []],
            'security' => ['csp' => 'strict', 'remoteCode' => false],
            'assets' => [['path' => 'dist/index.js', 'integrity' => 'sha256-'.base64_encode(hash('sha256', $js, true))]],
        ], JSON_THROW_ON_ERROR));
        config()->set(['theme_v2.txboard_version' => '3.6.0',
            'theme_v2.browser_version' => '1.0.0', 'theme_v2.sdk_version' => '0.2.0']);
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
