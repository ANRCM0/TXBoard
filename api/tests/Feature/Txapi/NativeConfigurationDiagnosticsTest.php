<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Models\Setting;
use App\Services\Auth\NativeIdentityService;
use App\Services\NativeTelegramConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\Support\NativeRuntimeTestCase;

final class NativeConfigurationDiagnosticsTest extends NativeRuntimeTestCase
{
    public function test_saved_token_registration_uses_cas_receipts_and_current_grants_without_returning_callback_credentials(): void
    {
        [$invoke, $actor] = $this->client();
        $this->settings();
        Http::fake(['*getMe' => Http::response(['ok' => true, 'result' => ['id' => 123]]),
            '*setWebhook' => Http::response(['ok' => true, 'result' => true])]);
        $version = $invoke('settings.configuration.get', [])->assertOk()->json('data.version');
        $input = ['expected_version' => $version];
        $key = (string) Str::uuid();
        $response = $invoke('settings.telegram.webhook.register', $input, $key)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertStringNotContainsString('private-token', $response->getContent());
        $this->assertStringNotContainsString(md5('123:private-token'), $response->getContent());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === NativeTelegramConfiguration::callback('https://example.test', '123:private-token'));
        Setting::createOrUpdate('app_name', 'Later configuration');
        $invoke('settings.telegram.webhook.register', $input, $key)->assertOk();
        Http::assertSentCount(2);
        $invoke('settings.telegram.webhook.register', $input)->assertStatus(409);
        Http::assertSentCount(2);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'settings.write')->delete();
        $invoke('settings.telegram.webhook.register', $input, $key)->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_diagnostic_projects_only_status_from_telegram_and_rejects_stale_saved_revision(): void
    {
        [$invoke] = $this->client(); $this->settings();
        $callback = NativeTelegramConfiguration::callback('https://example.test', '123:private-token');
        Http::fake(['*getMe' => Http::response(['ok' => true, 'result' => ['id' => 123]]),
            '*getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => $callback,
                'pending_update_count' => 3, 'last_error_message' => 'upstream-private-text']])]);
        $version = $invoke('settings.configuration.get', [])->assertOk()->json('data.version');
        $response = $invoke('settings.telegram.webhook.inspect', ['expected_version' => $version])->assertOk()
            ->assertJsonPath('data.matches_saved', true)->assertJsonPath('data.pending_updates', 3)
            ->assertJsonPath('data.delivery_error', true)->assertJsonMissingPath('data.url');
        $this->assertStringNotContainsString('private', $response->getContent());
        Http::assertSentCount(2);
        Setting::createOrUpdate('telegram_webhook_url', 'https://new.example.test');
        $invoke('settings.telegram.webhook.inspect', ['expected_version' => $version])->assertStatus(409);
        Http::assertSentCount(2);
    }

    public function test_does_not_claim_registration_of_current_configuration_when_it_changes_during_the_external_call(): void
    {
        [$invoke] = $this->client(); $this->settings();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/setWebhook')) {
                Setting::createOrUpdate('telegram_bot_token', '456:new-private-token');
                return Http::response(['ok' => true, 'result' => true]);
            }
            return Http::response(['ok' => true, 'result' => ['id' => 123]]);
        });
        $version = $invoke('settings.configuration.get', [])->assertOk()->json('data.version');
        $invoke('settings.telegram.webhook.register', ['expected_version' => $version])->assertStatus(409)->assertJsonMissingPath('data.ok');
        Http::assertSentCount(2);
    }

    public function test_smtp_diagnostic_uses_saved_settings_and_current_write_permission_without_sending_mail(): void
    {
        [$invoke, $actor] = $this->client();
        foreach (['email_host' => 'smtp.example.test', 'email_port' => '587', 'email_username' => 'private-user',
            'email_password' => 'private-password', 'email_encryption' => 'tls'] as $name => $value) Setting::createOrUpdate($name, $value);
        $manager = $this->createMock(MailManager::class);
        $transport = $this->getMockBuilder(EsmtpTransport::class)->setConstructorArgs(['smtp.example.test', 587, false])
            ->onlyMethods(['start', 'stop', 'send', 'setAuthenticators', 'executeCommand'])->getMock();
        $authenticators = [];
        $transport->method('setAuthenticators')->willReturnCallback(function ($next) use (&$authenticators) { $authenticators = $next; });
        $transport->method('executeCommand')->willReturn('235 accepted');
        $transport->expects(self::once())->method('start')->willReturnCallback(function () use (&$authenticators, $transport) { $authenticators[1]->authenticate($transport); });
        $transport->expects(self::once())->method('stop');
        $transport->expects(self::never())->method('send');
        $manager->expects(self::once())->method('createSymfonyTransport')->with(self::callback(fn ($config) =>
            $config['host'] === 'smtp.example.test' && $config['password'] === 'private-password' && $config['require_tls'] === true))->willReturn($transport);
        app()->instance(MailManager::class, $manager);
        $version = $invoke('settings.configuration.get', [])->assertOk()->json('data.version');
        $response = $invoke('settings.smtp.inspect', ['expected_version' => $version])->assertOk()
            ->assertJsonPath('data.connected', true)->assertJsonPath('data.authentication_used', true);
        $this->assertStringNotContainsString('private', $response->getContent());
        Setting::createOrUpdate('email_host', 'later.example.test');
        $invoke('settings.smtp.inspect', ['expected_version' => $version])->assertStatus(409);
        $current = $invoke('settings.configuration.get', [])->assertOk()->json('data.version');
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'settings.write')->delete();
        $invoke('settings.smtp.inspect', ['expected_version' => $current])->assertForbidden();
    }

    private function settings(): void
    {
        Setting::createOrUpdate('app_url', 'https://example.test');
        Setting::createOrUpdate('telegram_bot_token', '123:private-token');
    }

    private function client(): array
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'configuration_admin']);
        [, $actor] = app(NativeIdentityService::class)->create('diagnostics-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        $bearer = 'Bearer '.$actor->createToken('configuration', ['*'])->plainTextToken;
        foreach (['settings.read', 'settings.write'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $fingerprint = str_repeat('e', 64);
        $operations = ['settings.configuration.get', 'settings.telegram.webhook.inspect', 'settings.telegram.webhook.register', 'settings.smtp.inspect'];
        $service = app(ServiceCredentials::class)->issue('configuration-diagnostics', 'admin', $fingerprint, $operations, time() + 3600);
        $invoke = function (string $operation, array $input, ?string $key = null) use ($bearer, $service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$operation, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 30,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['Authorization' => $bearer, 'X-Core-Admin-Path' => 'configuration_admin',
                    'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
        };
        return [$invoke, $actor];
    }
}
