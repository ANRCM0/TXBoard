<?php

namespace Tests\Feature\Txapi;

use App\Models\Order;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminAccountMutationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'safe_account_admin']);
    }

    public function test_native_account_actions_require_admin_and_rotated_secure_path(): void
    {
        $user = $this->user('normal-mutator@example.test');
        $admin = $this->user('admin-mutator@example.test', true);
        $root = '/txapi/admin/safe_account_admin/users';
        $this->postJson($root . '/' . $user->id . '/delete')->assertStatus(403);
        Sanctum::actingAs($user);
        $this->postJson($root . '/' . $user->id . '/subscription-credentials/rotate')->assertStatus(403);
        $this->postJson($root . '/ban', ['scope' => 'selected', 'user_ids' => [$admin->id]])->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/guessed/users/' . $user->id . '/delete')->assertStatus(404);
        $this->postJson($root . '/' . $admin->id . '/delete')->assertStatus(409);
    }

    public function test_legacy_and_native_delete_refuse_to_erase_orders_or_balances(): void
    {
        $admin = $this->user('delete-admin@example.test', true);
        $debtor = $this->user('delete-owed@example.test');
        $debtor->balance = 340;
        $debtor->saveOrFail();
        $buyer = $this->user('delete-buyer@example.test');
        $order = Order::create([
            'user_id' => $buyer->id, 'plan_id' => 0,
            'trade_no' => 'SAFE-HISTORY-ORDER', 'period' => 'monthly',
            'total_amount' => 1300, 'status' => Order::STATUS_COMPLETED,
            'type' => Order::TYPE_NEW_PURCHASE,
        ]);
        Sanctum::actingAs($admin);
        $root = '/txapi/admin/safe_account_admin/users';
        $this->postJson($root . '/' . $buyer->id . '/delete')
            ->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_IN_USE');
        $this->postJson($root . '/' . $debtor->id . '/delete')->assertStatus(409);
        $this->postJson('/api/v2/safe_account_admin/user/destroy', ['id' => $buyer->id])
            ->assertStatus(400);
        $this->postJson('/api/v2/safe_account_admin/user/destroy', ['id' => $debtor->id])
            ->assertStatus(400);
        $this->assertNotNull($buyer->fresh());
        $this->assertSame($order->id, Order::where('trade_no', 'SAFE-HISTORY-ORDER')->value('id'));
        $this->assertSame(340, (int) $debtor->fresh()->balance);
    }

    public function test_only_never_used_account_can_be_deleted_and_sessions_are_revoked(): void
    {
        $admin = $this->user('fresh-admin@example.test', true);
        $fresh = $this->user('unused-delete@example.test');
        $fresh->createToken('old-session');
        $id = $fresh->id;
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/safe_account_admin/users/' . $id . '/delete')
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull(User::find($id));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class, 'tokenable_id' => $id,
        ]);
        $this->postJson('/txapi/admin/safe_account_admin/users/' . $id . '/delete')->assertStatus(404);
    }

    public function test_secret_rotation_preserves_hook_and_never_reveals_new_secret(): void
    {
        $admin = $this->user('secret-admin@example.test', true);
        $target = $this->user('secret-target@example.test');
        $oldToken = $target->token;
        $called = [];
        HookManager::addAction('admin.user.secret.reset', function (array $payload) use (&$called): void {
            $called[] = $payload['user']->id;
        });
        Sanctum::actingAs($admin);
        $url = '/txapi/admin/safe_account_admin/users/' . $target->id . '/subscription-credentials/rotate';
        $response = $this->postJson($url)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNotSame($oldToken, $target->fresh()->token);
        $this->assertSame([$target->id], $called);
        $this->assertStringNotContainsString($target->fresh()->token, $response->getContent());
    }

    public function test_native_ban_invalidates_sessions_and_does_not_allow_staff_or_admin(): void
    {
        $admin = $this->user('ban-admin@example.test', true);
        $first = $this->user('ban-first@example.test');
        $second = $this->user('ban-second@example.test');
        $staff = $this->user('ban-staff@example.test');
        $staff->is_staff = 1;
        $staff->saveOrFail();
        $first->createToken('old-session');
        $second->createToken('old-session');
        Sanctum::actingAs($admin);
        $url = '/txapi/admin/safe_account_admin/users/ban';
        $this->postJson($url, ['scope' => 'selected', 'user_ids' => [$first->id, $staff->id]])
            ->assertStatus(422);
        $this->assertFalse((bool) $first->fresh()->banned);
        $this->postJson($url, ['scope' => 'selected', 'user_ids' => [$first->id, $second->id]])
            ->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertTrue((bool) $first->fresh()->banned);
        $this->assertTrue((bool) $second->fresh()->banned);
        $this->assertSame(0, $first->tokens()->count());
        $this->assertSame(0, $second->tokens()->count());
        $this->postJson($url, [
            'scope' => 'filtered', 'filter' => [['id' => 'email', 'value' => 'ban-first']],
        ])->assertOk()->assertJsonPath('data.updated', 1);
        $this->postJson($url, [
            'scope' => 'filtered', 'filter' => [['id' => 'balance', 'value' => 'gt:0']],
        ])->assertStatus(422);
        $this->postJson($url, ['scope' => 'selected', 'user_ids' => [$admin->id]])
            ->assertStatus(422);
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'not-a-real-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'is_staff' => 0, 'banned' => 0,
            'balance' => 0, 'commission_balance' => 0,
        ]);
    }
}
