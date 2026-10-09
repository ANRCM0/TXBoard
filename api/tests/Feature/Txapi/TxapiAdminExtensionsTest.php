<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class TxapiAdminExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/extensions_secret';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'extensions_secret']);
    }

    public function test_admin_boundary_and_rotating_path_for_extension_management(): void
    {
        $this->getJson(self::ROOT . '/themes')->assertStatus(403);
        $this->getJson(self::ROOT . '/plugins')->assertStatus(403);
        $this->postJson(self::ROOT . '/plugins/demo/actions/install')->assertStatus(403);
        Sanctum::actingAs($this->user('extensions-user@example.test'));
        $this->getJson(self::ROOT . '/plugins')->assertStatus(403);
        $this->postJson(self::ROOT . '/themes/upload')->assertStatus(403);

        Sanctum::actingAs($this->user('extensions-admin@example.test', true));
        $this->getJson('/txapi/admin/wrong/themes')->assertStatus(404);
        $this->getJson(self::ROOT . '/themes')->assertOk()
            ->assertJsonStructure(['data' => ['themes', 'active'], 'request_id']);
        $this->getJson(self::ROOT . '/plugins/types')->assertOk()
            ->assertJsonPath('data.0.value', Plugin::TYPE_FEATURE);
        $this->getJson(self::ROOT . '/plugins?type=unknown')->assertStatus(422);
        $this->getJson(self::ROOT . '/plugins')->assertOk()
            ->assertJsonStructure(['data', 'request_id']);
    }

    public function test_theme_missing_invalid_and_protected_actions_are_bounded(): void
    {
        Sanctum::actingAs($this->user('theme-admin@example.test', true));
        $this->getJson(self::ROOT . '/themes/nonexistent/config')
            ->assertStatus(404)->assertJsonPath('error.code', 'THEME_NOT_FOUND');
        $this->putJson(self::ROOT . '/themes/nonexistent/config',
            ['config' => ['title' => 'Example']])->assertStatus(404);
        $this->deleteJson(self::ROOT . '/themes/nonexistent')->assertStatus(404);
        $this->getJson(self::ROOT . '/themes/invalid.name/config')->assertStatus(422);
        $this->postJson(self::ROOT . '/themes/upload', [])->assertStatus(422);
        $this->postJson(self::ROOT . '/themes/upload', [
            'file' => UploadedFile::fake()->create('invalid.txt', 10, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_plugin_actions_require_identifiers_and_protect_lifecycle(): void
    {
        Sanctum::actingAs($this->user('plugin-admin@example.test', true));
        $this->getJson(self::ROOT . '/plugins/INVALID.CODE/config')->assertStatus(422);
        $this->getJson(self::ROOT . '/plugins/missing/config')->assertStatus(404);
        $this->postJson(self::ROOT . '/plugins/missing/actions/enable')->assertStatus(404);
        $this->postJson(self::ROOT . '/plugins/upload', [])->assertStatus(422);
        $this->putJson(self::ROOT . '/plugins/missing/config',
            ['config' => ['password' => 'secret']])->assertStatus(404);
        $this->deleteJson(self::ROOT . '/plugins/missing')->assertStatus(404);
    }

    public function test_plugin_manager_called_from_native_action_with_audited_write(): void
    {
        Sanctum::actingAs($this->user('plugin-ops@example.test', true));
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('initializeEnabledPlugins')->andReturnNull();
        $manager->shouldReceive('resolvePluginPath')
            ->once()->with('test_plugin')->andReturn('/fake/test_plugin');
        $manager->shouldReceive('enable')->once()->with('test_plugin')->andReturn(true);
        app()->instance(PluginManager::class, $manager);

        $this->postJson(self::ROOT . '/plugins/test_plugin/actions/enable')
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertDatabaseHas('v2_admin_audit_log', ['method' => 'POST']);
    }

    public function test_retired_v2_theme_and_plugin_admin_endpoints_are_unregistered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route) => $route->uri())->all();
        foreach (['theme/getThemes', 'theme/getThemeConfig', 'theme/saveThemeConfig',
            'theme/upload', 'theme/delete', 'plugin/getPlugins', 'plugin/types',
            'plugin/install', 'plugin/uninstall', 'plugin/enable', 'plugin/disable',
            'plugin/upload', 'plugin/config', 'plugin/delete', 'plugin/upgrade'] as $tail) {
            $this->assertNotContains('api/v2/{admin_path}/' . $tail, $uris);
        }
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email, 'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'banned' => 0,
        ]);
    }
}
