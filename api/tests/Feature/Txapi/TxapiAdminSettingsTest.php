<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/settings_admin_secret/settings';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'settings_admin_secret', 'app_name' => 'TXBoard Testing']);
    }

    public function test_administrator_settings_are_protected_and_scoped(): void
    {
        $this->getJson(self::ROOT)->assertStatus(403);
        Sanctum::actingAs($this->account('settings-regular@example.test'));
        $this->getJson(self::ROOT . '/site')->assertStatus(403);
        $this->postJson(self::ROOT, ['app_name' => 'Cannot save'])->assertStatus(403);

        Sanctum::actingAs($this->account('settings-admin@example.test', true));
        $this->getJson('/txapi/admin/wrong/settings')->assertStatus(404);
        $this->getJson(self::ROOT . '/site')->assertOk()
            ->assertJsonPath('data.site.app_name', 'TXBoard Testing');
        $this->getJson(self::ROOT . '/unknown')->assertStatus(404)
            ->assertJsonPath('error.code', 'SETTINGS_GROUP_NOT_FOUND');
        $this->getJson(self::ROOT)->assertOk()
            ->assertJsonPath('data.site.app_name', 'TXBoard Testing');
    }

    public function test_save_uses_existing_configuration_validation_and_path_rotation(): void
    {
        Sanctum::actingAs($this->account('settings-editor@example.test', true));
        $this->postJson(self::ROOT, ['secure_path' => 'short'])
            ->assertStatus(422);
        $this->postJson(self::ROOT, ['app_name' => 'New TXBoard Name'])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->getJson(self::ROOT . '/site')->assertOk()
            ->assertJsonPath('data.site.app_name', 'New TXBoard Name');

        $this->postJson(self::ROOT, ['secure_path' => 'settings_rotated_secret'])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->getJson(self::ROOT)->assertStatus(404);
        $this->getJson('/txapi/admin/settings_rotated_secret/settings/site')
            ->assertOk()->assertJsonPath('data.site.app_name', 'New TXBoard Name');
    }

    private function account(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin,
            'banned' => 0,
        ]);
    }
}
