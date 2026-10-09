<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiUserPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_are_user_scoped_and_allowlist_only(): void
    {
        $this->getJson('/txapi/me/preferences')->assertStatus(401);
        $this->patchJson('/txapi/me/preferences', [])->assertStatus(401);
        $user = User::create([
            'email' => 'settings-owner@example.test', 'password' => 'test-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'balance' => 321,
            'remind_expire' => false, 'remind_traffic' => true, 'banned' => false,
        ]);
        Sanctum::actingAs($user);
        $this->getJson('/txapi/me/preferences')->assertOk()
            ->assertJsonPath('data.remind_expire', false)
            ->assertJsonPath('data.remind_traffic', true);
        $updated = $this->patchJson('/txapi/me/preferences', [
            'remind_expire' => true, 'balance' => 999999,
            'is_admin' => true, 'token' => 'malicious',
        ]);
        $updated->assertOk()->assertJsonPath('data.remind_expire', true)
            ->assertJsonPath('data.remind_traffic', true);
        $this->assertSame(321, (int) $user->fresh()->balance);
        $this->assertFalse((bool) $user->fresh()->is_admin);
        $this->assertNotSame('malicious', $user->fresh()->token);
        $this->patchJson('/txapi/me/preferences', [])->assertStatus(422)
            ->assertJsonPath('error.code', 'PREFERENCES_REQUIRED');
        $this->patchJson('/txapi/me/preferences', ['remind_expire' => 'maybe'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
