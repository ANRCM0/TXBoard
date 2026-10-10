<?php

namespace Tests\Feature\Txapi;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiPlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_has_stable_order_and_uses_batched_capacity_counts(): void
    {
        $plans = [];
        for ($i = 0; $i < 12; $i++) {
            $plans[] = $this->makePlan('Available '.$i, [
                'capacity_limit' => 2,
                'sort' => $i,
            ]);
        }
        $soldOut = $this->makePlan('Sold out', ['capacity_limit' => 1]);
        $hidden = $this->makePlan('Private', ['show' => false]);
        $paused = $this->makePlan('Paused', ['sell' => false]);

        $this->makeUser('sold-out@example.test', [
            'plan_id' => $soldOut->id, 'expired_at' => time() + 3600,
        ]);
        // Expired users must not count against capacity.
        $this->makeUser('expired@example.test', [
            'plan_id' => $plans[0]->id, 'expired_at' => time() - 3600,
        ]);

        $capacityQueries = [];
        DB::listen(function ($query) use (&$capacityQueries): void {
            if (str_contains(strtolower($query->sql), 'tx_user')
                && str_contains(strtolower($query->sql), 'group by')) {
                $capacityQueries[] = $query->sql;
            }
        });

        $response = $this->getJson('/txapi/plans');
        $response->assertOk()->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.name', 'Available 0')
            ->assertJsonPath('data.11.name', 'Available 11')
            ->assertJsonPath('data.0.prices.0.period', Plan::PERIOD_MONTHLY)
            ->assertJsonPath('data.0.prices.0.amount_minor', 1250)
            ->assertJsonPath('data.0.traffic_limit_bytes', 2 * 1073741824)
            ->assertJsonPath('data.0.capacity_limit', 2);

        $this->assertCount(1, $capacityQueries,
            'Catalog must count subscribed users in one grouped SQL query, not per plan');
        $serialized = $response->getContent();
        $this->assertStringNotContainsString('Private', $serialized);
        $this->assertStringNotContainsString('Paused', $serialized);
        $this->assertStringNotContainsString('Sold out', $serialized);
        $this->assertArrayNotHasKey('month_price', $response->json('data.0'));
        $this->assertArrayNotHasKey('group_id', $response->json('data.0'));
    }

    public function test_renewal_visibility_matches_legacy_availability_boundary(): void
    {
        $hidden = $this->makePlan('Renewable hidden', [
            'show' => false, 'sell' => false,
            'renew' => true, 'capacity_limit' => 0,
            'content' => 'Private plan', 'tags' => ['annual', 'premium'],
            'speed_limit' => 30, 'device_limit' => 2,
        ]);
        $buyer = $this->makeUser('buyer@example.test');
        Sanctum::actingAs($buyer);
        $this->getJson('/txapi/plans/'.$hidden->id)
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $buyer->update(['plan_id' => $hidden->id, 'expired_at' => time() + 3600]);
        $detail = $this->getJson('/txapi/plans/'.$hidden->id);
        $detail->assertOk()->assertJsonPath('data.id', $hidden->id)
            ->assertJsonPath('data.content', 'Private plan')
            ->assertJsonPath('data.tags.0', 'annual')
            ->assertJsonPath('data.speed_limit_mbps', 30)
            ->assertJsonPath('data.device_limit', 2)
            ->assertJsonPath('data.capacity_limit', 0)
            ->assertJsonPath('data.renewable', true);

        $hidden->update(['renew' => false]);
        $this->getJson('/txapi/plans/'.$hidden->id)->assertStatus(404);
    }

    public function test_detail_requires_user_and_does_not_leak_another_hidden_plan(): void
    {
        $public = $this->makePlan('Public plan');
        $hidden = $this->makePlan('Hidden plan', ['show' => false]);
        $this->getJson('/txapi/plans/'.$public->id)
            ->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        Sanctum::actingAs($this->makeUser('visitor@example.test'));
        $this->getJson('/txapi/plans/'.$public->id)
            ->assertOk()->assertJsonPath('data.name', 'Public plan');
        $this->getJson('/txapi/plans/'.$hidden->id)->assertStatus(404);
        $this->getJson('/txapi/plans/999999')->assertStatus(404);
        $this->getJson('/txapi/plans/not-an-id')->assertStatus(404);
    }

    private function makePlan(string $name, array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => $name,
            'group_id' => 1,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 50,
            'capacity_limit' => null,
            'transfer_enable' => 2,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [
                Plan::PERIOD_MONTHLY => 12.5,
                Plan::PERIOD_YEARLY => 100,
            ],
        ], $attributes));
    }

    private function makeUser(string $email, array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'password' => 'test-only-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'banned' => 0, 'u' => 0, 'd' => 0,
        ], $attributes));
    }
}
