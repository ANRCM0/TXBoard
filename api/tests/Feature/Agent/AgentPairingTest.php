<?php

namespace Tests\Feature\Agent;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentPairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('agent_ops.pairing_cache_store', 'array');
        config()->set('agent_ops.pairing_ttl_seconds', 60);
        Cache::store('array')->flush();
    }

    public function test_token_creation_issues_encrypted_short_lived_pairing_state(): void
    {
        [$response] = $this->createPairedToken('pairing-encrypted');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.pairing.expires_in_seconds', 60);

        $plain = (string) $response->json('data.plain_text_token');
        $code = (string) $response->json('data.pairing.code');

        $this->assertMatchesRegularExpression('/^txbp_[A-Za-z0-9_-]{24}$/', $code);
        $this->assertNotSame('', $plain);

        $cacheKey = 'agent_pairing:v2:' . hash('sha256', $code);
        $cached = Cache::store('array')->get($cacheKey);

        $this->assertIsString($cached);
        $this->assertStringNotContainsString($plain, $cached);
        $this->assertStringNotContainsString($code, $cacheKey);
    }

    public function test_token_creation_falls_back_when_transient_pairing_store_is_unavailable(): void
    {
        config()->set('agent_ops.pairing_cache_store', 'missing-pairing-store');

        [$response] = $this->createPairedToken('pairing-fallback');

        $response->assertOk()
            ->assertJsonPath('data.pairing', null);

        $this->assertNotSame('', (string) $response->json('data.plain_text_token'));
    }

    public function test_pairing_redeems_once_and_returns_the_existing_agent_token(): void
    {
        [$created, $admin] = $this->createPairedToken('pairing-once');

        $plain = (string) $created->json('data.plain_text_token');
        $code = (string) $created->json('data.pairing.code');

        $redeemed = $this->postJson('/api/v2/agent/pairings/redeem', [
            'pairing_code' => $code,
        ]);

        $redeemed->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.plain_text_token', $plain)
            ->assertJsonPath('data.client_name', 'pairing-once');

        // Clear Sanctum::actingAs() so the next request authenticates the issued bearer token.
        $this->app['auth']->forgetGuards();

        $this->withToken((string) $redeemed->json('data.plain_text_token'))
            ->getJson('/api/v2/agent/whoami')
            ->assertOk()
            ->assertJsonPath('data.admin_id', $admin->id)
            ->assertJsonPath('data.client_name', 'pairing-once');

        $this->postJson('/api/v2/agent/pairings/redeem', [
            'pairing_code' => $code,
        ])->assertStatus(410)
            ->assertJsonPath('message', 'Pairing code is invalid, expired, or already redeemed');
    }

    public function test_expired_pairing_cannot_be_redeemed(): void
    {
        [$created] = $this->createPairedToken('pairing-expired');
        $code = (string) $created->json('data.pairing.code');

        $this->travel(61)->seconds();

        $this->postJson('/api/v2/agent/pairings/redeem', [
            'pairing_code' => $code,
        ])->assertStatus(410);
    }

    public function test_revoked_long_lived_token_invalidates_and_consumes_pairing(): void
    {
        [$created] = $this->createPairedToken('pairing-revoked');
        $tokenId = (int) $created->json('data.id');
        $code = (string) $created->json('data.pairing.code');

        $securePath = $this->securePath();
        $this->deleteJson("/txapi/admin/{$securePath}/agents/tokens/{$tokenId}")
            ->assertOk();

        $this->postJson('/api/v2/agent/pairings/redeem', [
            'pairing_code' => $code,
        ])->assertStatus(410);

        $this->postJson('/api/v2/agent/pairings/redeem', [
            'pairing_code' => $code,
        ])->assertStatus(410);
    }

    private function createPairedToken(string $clientName): array
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson("/txapi/admin/{$this->securePath()}/agents/tokens", [
            'client_name' => $clientName,
            'expires_in_days' => 7,
        ]);

        return [$response, $admin];
    }

    private function securePath(): string
    {
        return (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    private function makeAdmin(): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'email' => "pairing-admin-{$sequence}@example.com",
            'password' => 'password',
            'uuid' => sprintf('00000000-0000-0000-0000-%012d', 1200 + $sequence),
            'token' => str_pad((string) (1200 + $sequence), 32, 'p', STR_PAD_LEFT),
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
