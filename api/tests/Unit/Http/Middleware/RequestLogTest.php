<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RequestLog;
use PHPUnit\Framework\TestCase;

class RequestLogTest extends TestCase
{
    public function test_it_recursively_redacts_sensitive_admin_payload_fields(): void
    {
        $middleware = new class extends RequestLog {
            public function sanitize(array $payload): array
            {
                return $this->redactSensitiveData($payload);
            }
        };

        $sanitized = $middleware->sanitize([
            'email_password' => 'mail-password',
            'server_token' => 'server-token',
            'recaptcha_v3_secret_key' => 'captcha-secret',
            'remark' => 'safe value',
            'config' => [
                'merchant_id' => 'merchant-123',
                'api_key' => 'payment-key',
                'nested' => [
                    'client_secret' => 'client-secret',
                    'authorization' => 'Bearer abc',
                    'keyboard_layout' => 'qwerty',
                ],
            ],
        ]);

        $this->assertSame('[REDACTED]', $sanitized['email_password']);
        $this->assertSame('[REDACTED]', $sanitized['server_token']);
        $this->assertSame('[REDACTED]', $sanitized['recaptcha_v3_secret_key']);
        $this->assertSame('[REDACTED]', $sanitized['config']['api_key']);
        $this->assertSame('[REDACTED]', $sanitized['config']['nested']['client_secret']);
        $this->assertSame('[REDACTED]', $sanitized['config']['nested']['authorization']);
        $this->assertSame('merchant-123', $sanitized['config']['merchant_id']);
        $this->assertSame('qwerty', $sanitized['config']['nested']['keyboard_layout']);
        $this->assertSame('safe value', $sanitized['remark']);
    }
}
