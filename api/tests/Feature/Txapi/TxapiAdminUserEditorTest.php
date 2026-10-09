<?php

namespace Tests\Feature\Txapi;

use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminUserEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'editor_safe_path']);
    }

    public function test_create_requires_admin_explicit_password_and_valid_plan(): void
    {
        $admin = $this->makeUser('editor-admin@example.test', true);
        $user = $this->makeUser('editor-normal@example.test');
        $plan = $this->makePlan();
        $url = '/txapi/admin/editor_safe_path/users';
        $payload = [
            'email_prefix' => 'new.member', 'email_suffix' => 'example.test',
            'password' => 'CorrectHorseBattery77', 'plan_id' => $plan->id,
            'expired_at' => time() + 86400, 'return_credentials' => true,
        ];
        $this->postJson($url, $payload)->assertStatus(403);
        Sanctum::actingAs($user);
        $this->postJson($url, $payload)->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/wrong/users', $payload)->assertStatus(404);
        $this->postJson($url, array_diff_key($payload, ['password' => true]))->assertStatus(422);
        $this->postJson($url, array_merge($payload, ['generate_count' => 501]))->assertStatus(422);
        $this->postJson($url, array_merge($payload, ['plan_id' => 999999]))->assertStatus(422);
        $response = $this->postJson($url, $payload)->assertCreated();
        $id = (int) $response->json('data.id');
        $new = User::findOrFail($id);
        $this->assertSame('new.member@example.test', $new->email);
        $this->assertTrue(Hash::check('CorrectHorseBattery77', $new->password));
        $this->assertSame($plan->id, $new->plan_id);
        $this->assertSame(2 * 1073741824, (int) $new->transfer_enable);
        $this->assertStringNotContainsString($new->token, $response->getContent());
        $this->assertStringNotContainsString('CorrectHorseBattery77', $response->getContent());
        $this->postJson($url, $payload)->assertStatus(422);
    }

    public function test_user_editor_uses_expected_minor_balance_and_rejects_stale_financial_updates(): void
    {
        $admin = $this->makeUser('editor-finance-admin@example.test', true);
        $account = $this->makeUser('editor-account@example.test');
        $account->balance = 1234;
        $account->commission_balance = 567;
        $account->saveOrFail();
        Sanctum::actingAs($admin);
        $url = '/txapi/admin/editor_safe_path/users/' . $account->id . '/update';
        $base = [
            'balance' => 15.25, 'expected_balance_minor' => 1234,
            'commission_balance' => 6.01, 'expected_commission_balance_minor' => 567,
            'email' => 'EDITED@example.test', 'remarks' => 'reviewed',
        ];
        $this->postJson($url, array_diff_key($base, ['expected_balance_minor' => true]))
            ->assertStatus(422);
        $this->postJson($url, array_merge($base, ['balance' => 15.254]))
            ->assertStatus(422);
        $this->postJson($url, array_merge($base, ['expected_balance_minor' => 100]))
            ->assertStatus(422);
        $this->assertSame(1234, (int) $account->fresh()->balance);
        $this->postJson($url, $base)->assertOk()
            ->assertJsonPath('data.ok', true)->assertJsonPath('data.id', $account->id);
        $this->assertSame(1525, (int) $account->fresh()->balance);
        $this->assertSame(601, (int) $account->fresh()->commission_balance);
        $this->assertSame('edited@example.test', $account->fresh()->email);
        $this->postJson($url, $base)->assertStatus(422);
        $this->assertSame(1525, (int) $account->fresh()->balance);
        $this->postJson($url, ['balance' => 999.99, 'expected_balance_minor' => 1234])
            ->assertStatus(422);
        $this->assertSame(1525, (int) $account->fresh()->balance);
    }

    public function test_editor_does_not_promote_admin_and_preserves_password_hooks_and_sessions(): void
    {
        $admin = $this->makeUser('editor-hooks-admin@example.test', true);
        $account = $this->makeUser('editor-hooks-account@example.test');
        $account->createToken('pre-edit');
        $calls = [];
        HookManager::register('admin.user.update.after', function (array $event) use (&$calls): void {
            $calls[] = $event['params']['password'] ?? null;
        });
        Sanctum::actingAs($admin);
        $url = '/txapi/admin/editor_safe_path/users/' . $account->id . '/update';
        $this->postJson($url, ['is_admin' => true])->assertStatus(422);
        $this->postJson($url, ['is_staff' => true])->assertStatus(422);
        $this->postJson($url, ['transfer_enable' => -1])->assertStatus(422);
        $this->postJson($url, ['plan_id' => 999999])->assertStatus(404);
        $this->postJson($url, ['invite_user_email' => $account->email])->assertStatus(422);
        $this->postJson($url, ['password' => 'NewSecurePass789', 'banned' => true])
            ->assertOk();
        $this->assertFalse((bool) $account->fresh()->is_admin);
        $this->assertTrue((bool) $account->fresh()->banned);
        $this->assertTrue(Hash::check('NewSecurePass789', $account->fresh()->password));
        $this->assertSame(0, $account->tokens()->count());
        $this->assertCount(1, $calls);
        $this->assertNotSame('NewSecurePass789', $calls[0]);
        $this->assertTrue(Hash::check('NewSecurePass789', $calls[0]));
    }

    private function makeUser(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email, 'password' => 'base-test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'is_staff' => 0, 'banned' => 0,
            'balance' => 0, 'commission_balance' => 0,
        ]);
    }

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'User Editor Plan', 'group_id' => 1, 'transfer_enable' => 2,
            'show' => true, 'sell' => true, 'renew' => true,
            'prices' => ['monthly' => 10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        ]);
    }
}
