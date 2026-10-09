<?php

namespace Tests\Feature\Txapi;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminPlanMutationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'plan_mutation_admin']);
    }

    public function test_plan_mutations_reject_nonadmins_and_wrong_path(): void
    {
        $root = '/txapi/admin/plan_mutation_admin/plans';
        $this->postJson($root, [])->assertStatus(403);
        Sanctum::actingAs($this->user('plan-nonadmin@example.test', false));
        $this->postJson($root, [])->assertStatus(403);
        Sanctum::actingAs($this->user('plan-admin@example.test', true));
        $this->postJson('/txapi/admin/invalid/plans', [])->assertStatus(404);
    }

    public function test_plan_create_edit_flags_and_sort_keep_display_contract(): void
    {
        Sanctum::actingAs($this->user('plan-editor@example.test', true));
        $url = '/txapi/admin/plan_mutation_admin/plans';
        $created = $this->postJson($url, [
            'name' => 'Native editable plan',
            'transfer_enable' => 4,
            'group_id' => 1,
            'prices' => ['monthly' => 12.34, 'yearly' => 0],
            'tags' => ['fast'],
        ]);
        $created->assertOk()->assertJsonStructure(['data' => ['id'], 'request_id']);
        $id = $created->json('data.id');
        $plan = Plan::findOrFail($id);
        $this->assertSame(4, (int) $plan->transfer_enable);
        $this->assertSame(['monthly' => 12.34], $plan->prices);

        $this->postJson($url . '/' . $id . '/flags', ['show' => false, 'sell' => true])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertFalse((bool) $plan->fresh()->show);
        $this->postJson($url . '/' . $id . '/flags', [])->assertStatus(422);
        $second = Plan::create([
            'name' => 'Second', 'group_id' => 1, 'transfer_enable' => 1,
            'sell' => true, 'renew' => true, 'show' => true,
            'prices' => ['monthly' => 5],
        ]);
        $this->postJson($url . '/sort', ['ids' => [$second->id, $id]])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(1, (int) $second->fresh()->sort);
        $this->assertSame(2, (int) $plan->fresh()->sort);

        $this->postJson($url, [
            'id' => $id, 'name' => 'Updated name',
            'transfer_enable' => 8, 'prices' => ['monthly' => 22.5],
        ])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame('Updated name', $plan->fresh()->name);
        $this->assertSame(8, (int) $plan->fresh()->transfer_enable);
    }

    public function test_invalid_prices_duplicate_sort_and_in_use_delete_are_rejected(): void
    {
        Sanctum::actingAs($this->user('plan-validator@example.test', true));
        $url = '/txapi/admin/plan_mutation_admin/plans';
        $this->postJson($url, ['name' => 'Bad', 'transfer_enable' => 2, 'prices' => ['fabricated' => 10]])
            ->assertStatus(422);
        $this->postJson($url, ['name' => 'Bad', 'transfer_enable' => 0])->assertStatus(422);
        $plan = Plan::create([
            'name' => 'Used', 'group_id' => 1, 'transfer_enable' => 3,
            'show' => true, 'sell' => true, 'renew' => true,
            'prices' => ['monthly' => 8],
        ]);
        $this->postJson($url . '/sort', ['ids' => [$plan->id, $plan->id]])->assertStatus(422);
        $this->postJson($url . '/sort', ['ids' => [999999]])->assertStatus(422);
        $user = $this->user('plan-active@example.test', false);
        $user->plan_id = $plan->id;
        $user->saveOrFail();
        $this->postJson($url . '/' . $plan->id . '/delete')
            ->assertStatus(409)->assertJsonPath('error.code', 'PLAN_IN_USE');
        $this->assertNotNull($plan->fresh());
        $user->plan_id = null;
        $user->saveOrFail();
        $this->postJson($url . '/' . $plan->id . '/delete')
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull($plan->fresh());
    }

    public function test_explicit_force_update_changes_only_subscribers_and_preserves_units(): void
    {
        Sanctum::actingAs($this->user('plan-force-admin@example.test', true));
        $current = Plan::create([
            'name' => 'Force current', 'group_id' => 1, 'transfer_enable' => 2,
            'show' => true, 'sell' => true, 'renew' => true,
            'prices' => ['monthly' => 9],
        ]);
        $other = Plan::create([
            'name' => 'Force unrelated', 'group_id' => 1, 'transfer_enable' => 1,
            'show' => true, 'sell' => true, 'renew' => true,
            'prices' => ['monthly' => 9],
        ]);
        $subscriber = $this->user('force-current@example.test', false);
        $subscriber->plan_id = $current->id;
        $subscriber->transfer_enable = 2 * 1073741824;
        $subscriber->saveOrFail();
        $outsider = $this->user('force-other@example.test', false);
        $outsider->plan_id = $other->id;
        $outsider->transfer_enable = 1073741824;
        $outsider->saveOrFail();

        $this->postJson('/txapi/admin/plan_mutation_admin/plans', [
            'id' => $current->id, 'name' => 'Force current',
            'transfer_enable' => 5, 'force_update' => true,
            'speed_limit' => null, 'device_limit' => 1,
            'prices' => ['monthly' => 9],
        ])->assertOk()->assertJsonPath('data.id', $current->id);

        $this->assertSame(5, (int) $current->fresh()->transfer_enable);
        $this->assertSame(5 * 1073741824, (int) $subscriber->fresh()->transfer_enable);
        $this->assertSame(1, (int) $subscriber->fresh()->device_limit);
        $this->assertSame(1073741824, (int) $outsider->fresh()->transfer_enable);
    }

    private function user(string $email, bool $admin): User
    {
        return User::create([
            'email' => $email, 'password' => 'never-export',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin,
            'banned' => 0, 'balance' => 0,
        ]);
    }
}
