<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_retired_settings_are_absent_and_cannot_be_saved(): void
    {
        Sanctum::actingAs($this->account('retired-keys@example.test', true));
        $server = $this->getJson(self::ROOT . '/server')->assertOk()->json('data.server');
        $this->assertArrayNotHasKey('server_ws_enable', $server);
        $this->assertArrayNotHasKey('server_ws_url', $server);
        $safe = $this->getJson(self::ROOT . '/safe')->assertOk()->json('data.safe');
        $this->assertArrayNotHasKey('recaptcha_enable', $safe);

        $this->postJson(self::ROOT, ['server_ws_enable' => true])->assertStatus(422);
        $this->postJson(self::ROOT, ['server_ws_url' => 'wss://example.test/ws'])->assertStatus(422);
        $this->postJson(self::ROOT, ['recaptcha_enable' => true])->assertStatus(422);
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


    public function test_telegram_webhook_requires_admin_and_saved_valid_settings(): void
    {
        // Check auth and validation independently from the explicit 3/min
        // side-effect throttle; keep the middleware enabled in production.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $uri = self::ROOT . '/telegram/webhook';
        $payload = ['telegram_bot_token' => '123456:test-bot-token'];
        Http::fake();

        $this->postJson($uri, $payload)->assertStatus(403);
        Sanctum::actingAs($this->account('telegram-user@example.test'));
        $this->postJson($uri, $payload)->assertStatus(403);

        Sanctum::actingAs($this->account('telegram-admin@example.test', true));
        $this->postJson('/txapi/admin/wrong/settings/telegram/webhook', $payload)
            ->assertStatus(404);
        $this->postJson($uri, [])->assertStatus(422);
        $this->postJson($uri, $payload)->assertStatus(409)
            ->assertJsonPath('error.code', 'TELEGRAM_SETTINGS_NOT_SAVED');

        admin_setting(['telegram_bot_token' => $payload['telegram_bot_token'],
            'telegram_webhook_url' => '', 'app_url' => '']);
        $this->postJson($uri, $payload)->assertStatus(422)
            ->assertJsonPath('error.code', 'TELEGRAM_WEBHOOK_URL_INVALID');
        Http::assertNothingSent();
    }

    public function test_telegram_webhook_uses_native_callback_without_exposing_credentials(): void
    {
        $token = '123456:test-bot-token';
        admin_setting(['telegram_bot_token' => $token,
            'telegram_webhook_url' => 'https://panel.example.test']);
        Sanctum::actingAs($this->account('telegram-success@example.test', true));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $response = $this->postJson(self::ROOT . '/telegram/webhook',
            ['telegram_bot_token' => $token]);
        $response->assertOk()->assertJsonPath('data.ok', true)
            ->assertDontSee(md5($token));
        $this->assertStringContainsString('no-store',
            (string) $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('webhook_url', $response->json('data'));
        Http::assertSent(static function ($request) use ($token): bool {
            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/setWebhook')
                && $request['url'] === 'https://panel.example.test/txapi/integrations/telegram/webhook?access_token=' . md5($token);
        });
    }

    public function test_telegram_upstream_failure_is_redacted_from_native_response(): void
    {
        $token = '123456:test-bot-token';
        admin_setting(['telegram_bot_token' => $token,
            'telegram_webhook_url' => 'https://panel.example.test']);
        Sanctum::actingAs($this->account('telegram-fail@example.test', true));
        Http::fake(['*' => Http::response(['ok' => false,
            'description' => 'SECRET_UPSTREAM_DETAILS'])]);

        $this->postJson(self::ROOT . '/telegram/webhook',
            ['telegram_bot_token' => $token])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'TELEGRAM_WEBHOOK_FAILED')
            ->assertDontSee('SECRET_UPSTREAM_DETAILS')
            ->assertDontSee(md5($token));
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
