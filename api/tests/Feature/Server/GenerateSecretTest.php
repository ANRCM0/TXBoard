<?php

namespace Tests\Feature\Server;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateSecretTest extends TestCase
{
    use RefreshDatabase;

    private string $securePath;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create([
            'email' => 'generate-secret-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000201',
            'token' => str_repeat('b', 32),
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
        ]));

        $this->securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    public function test_x25519_generator_returns_a_matching_key_pair(): void
    {
        $response = $this->postJson("/txapi/admin/{$this->securePath}/network-nodes/secrets", ['kind' => 'x25519']);

        $response->assertOk();

        $privateKey = base64_decode((string) $response->json('data.private_key'), true);
        $publicKey = base64_decode((string) $response->json('data.public_key'), true);

        $this->assertNotFalse($privateKey);
        $this->assertSame(32, strlen($privateKey));
        $this->assertSame(sodium_crypto_scalarmult_base($privateKey), $publicKey);
    }

    public function test_hex_generator_respects_the_maximum_allowed_length(): void
    {
        $response = $this->postJson("/txapi/admin/{$this->securePath}/network-nodes/secrets", ['kind' => 'hex', 'bytes' => 64]);

        $response->assertOk();
        $this->assertSame(128, strlen((string) $response->json('data.value')));
    }

    public function test_ech_generator_returns_key_and_config_pem(): void
    {
        $response = $this->postJson("/txapi/admin/{$this->securePath}/network-nodes/secrets", ['kind' => 'ech', 'public_name' => 'node.example.com']);

        $response->assertOk();
        $this->assertStringContainsString('-----BEGIN ECH KEYS-----', (string) $response->json('data.key'));
        $this->assertStringContainsString('-----END ECH KEYS-----', (string) $response->json('data.key'));
        $this->assertStringContainsString('-----BEGIN ECH CONFIGS-----', (string) $response->json('data.config'));
        $this->assertStringContainsString('node.example.com', $this->decodeEchConfigPem((string) $response->json('data.config')));
    }

    public function test_ech_generator_falls_back_to_a_default_public_name(): void
    {
        $response = $this->postJson("/txapi/admin/{$this->securePath}/network-nodes/secrets", ['kind' => 'ech']);

        $response->assertOk();
        $this->assertStringContainsString('ech.example.com', $this->decodeEchConfigPem((string) $response->json('data.config')));
    }

    private function decodeEchConfigPem(string $pem): string
    {
        $payload = preg_replace('/-----BEGIN ECH CONFIGS-----|-----END ECH CONFIGS-----|\s+/', '', $pem);
        $decoded = base64_decode((string) $payload, true);
        $this->assertNotFalse($decoded);

        return $decoded;
    }

    public function test_unknown_generator_kind_is_rejected(): void
    {
        $this->postJson("/txapi/admin/{$this->securePath}/network-nodes/secrets", ['kind' => 'rsa'])
            ->assertStatus(422);
    }
}