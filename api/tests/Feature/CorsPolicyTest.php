<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The API shipped `'allowed_origins' => ['*']`, which lets any website read API
 * responses from a visitor's browser. These tests pin the restricted default.
 */
class CorsPolicyTest extends TestCase
{
    /**
     * The config file is loaded directly rather than through config(), so the
     * assertions hold even when a cached config is present.
     */
    public function test_wildcard_is_not_the_default(): void
    {
        $config = require base_path('config/cors.php');

        $this->assertSame([], $config['allowed_origins']);
        $this->assertNotContains('*', $config['allowed_origins']);
        $this->assertSame([], $config['allowed_origins_patterns']);
    }

    public function test_origins_are_read_from_a_comma_separated_environment_list(): void
    {
        $previous = getenv('CORS_ALLOWED_ORIGINS');

        try {
            putenv('CORS_ALLOWED_ORIGINS= https://panel.example.com , https://app.example.com ,, ');
            $config = require base_path('config/cors.php');

            $this->assertSame(
                ['https://panel.example.com', 'https://app.example.com'],
                $config['allowed_origins'],
                'entries should be trimmed and blanks dropped'
            );
        } finally {
            $previous === false
                ? putenv('CORS_ALLOWED_ORIGINS')
                : putenv("CORS_ALLOWED_ORIGINS={$previous}");
        }
    }

    public function test_a_foreign_origin_receives_no_allow_origin_header_by_default(): void
    {
        config(['cors.allowed_origins' => []]);

        $response = $this->getJson('/api/v1/guest/comm/config', [
            'Origin' => 'https://evil.example',
        ]);

        $this->assertNotSame(
            '*',
            $response->headers->get('Access-Control-Allow-Origin'),
            'a wildcard must never be echoed back'
        );
        $this->assertNotSame(
            'https://evil.example',
            $response->headers->get('Access-Control-Allow-Origin'),
            'an unconfigured origin must not be allowed'
        );
    }

    public function test_only_configured_origins_are_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://panel.example.com']]);

        $allowed = $this->getJson('/api/v1/guest/comm/config', [
            'Origin' => 'https://panel.example.com',
        ]);
        $this->assertSame(
            'https://panel.example.com',
            $allowed->headers->get('Access-Control-Allow-Origin')
        );

        $other = $this->getJson('/api/v1/guest/comm/config', [
            'Origin' => 'https://other.example',
        ]);
        $this->assertNotSame(
            'https://other.example',
            $other->headers->get('Access-Control-Allow-Origin')
        );
        $this->assertNotSame('*', $other->headers->get('Access-Control-Allow-Origin'));
    }
}
