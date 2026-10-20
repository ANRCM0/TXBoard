<?php

namespace Tests\Unit\Core;

use App\Services\NativeTelegramConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

final class NativeTelegramConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::clearResolvedInstance('http');
        parent::tearDown();
    }

    public function test_callback_is_https_only_and_carries_the_native_saved_token_digest(): void
    {
        $token = '123:private-value';
        self::assertSame('https://example.test/txapi/integrations/telegram/webhook?access_token='.md5($token),
            NativeTelegramConfiguration::callback('https://example.test/', $token));
        self::assertSame('https://example.test/txapi/integrations/telegram/webhook?access_token='.md5($token),
            NativeTelegramConfiguration::callback('https://example.test/txapi/integrations/telegram/webhook', $token));
        foreach (['http://example.test', 'https://user:pass@example.test', 'https://example.test?secret=1', 'https://example.test/#x'] as $base) {
            try { NativeTelegramConfiguration::callback($base, $token); self::fail('Unsafe callback accepted'); }
            catch (\DomainException $error) { self::assertSame('TELEGRAM_WEBHOOK_URL_INVALID', $error->getMessage()); }
        }
    }

    public function test_inspection_projects_status_without_callback_secret_or_upstream_error_text(): void
    {
        $http = new Factory;
        Http::swap($http);
        $callback = NativeTelegramConfiguration::callback('https://example.test', '123:private-value');
        $http->fake([
            '*getMe' => $http->response(['ok' => true, 'result' => ['id' => 123]]),
            '*getWebhookInfo' => $http->response(['ok' => true, 'result' => ['url' => $callback,
                'pending_update_count' => 4, 'last_error_message' => 'private upstream detail']]),
        ]);
        $status = (new NativeTelegramConfiguration)->inspect('123:private-value', $callback);
        self::assertSame(['configured' => true, 'matches_saved' => true, 'pending_updates' => 4, 'delivery_error' => true], $status);
        self::assertStringNotContainsString('private', json_encode($status));
        $http->assertSentCount(2);
    }

    public function test_registration_uses_only_the_fixed_authority_and_requires_positive_upstream_result(): void
    {
        $http = new Factory;
        Http::swap($http);
        $http->fake(['*getMe' => $http->response(['ok' => true, 'result' => ['id' => 123]]),
            '*setWebhook' => $http->response(['ok' => true, 'result' => false])]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('TELEGRAM_RESPONSE_INVALID');
        try { (new NativeTelegramConfiguration)->register('123:private-value', 'https://example.test/callback'); }
        finally { $http->assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot123:private-value/setWebhook'
            && $request['url'] === 'https://example.test/callback'); }
    }

    public function test_failure_does_not_reveal_the_upstream_body_or_token(): void
    {
        $http = new Factory;
        Http::swap($http);
        $http->fake(['*' => $http->response(['ok' => false, 'description' => '123:private-value'], 401)]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('TELEGRAM_DEPENDENCY_UNAVAILABLE');
        (new NativeTelegramConfiguration)->inspect('123:private-value', 'https://example.test/callback');
    }
}
