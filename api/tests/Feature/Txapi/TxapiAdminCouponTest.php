<?php

namespace Tests\Feature\Txapi;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminCouponTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/coupon_admin_secret/coupons';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'coupon_admin_secret']);
    }

    public function test_native_coupon_list_and_mutations_require_admin_and_valid_secure_path(): void
    {
        $this->getJson(self::ROOT)->assertStatus(403);
        Sanctum::actingAs($this->account('coupon-user@example.test'));
        $this->getJson(self::ROOT)->assertStatus(403);
        $this->postJson(self::ROOT, $this->payload())->assertStatus(403);
        Sanctum::actingAs($this->account('coupon-admin@example.test', true));
        $this->getJson('/txapi/admin/wrong/coupons')->assertStatus(404);
        $this->getJson(self::ROOT)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::ROOT . '?per_page=101')->assertStatus(422);
        $this->getJson(self::ROOT . '?type=9')->assertStatus(422);
    }

    public function test_create_filter_toggle_update_and_delete_coupon(): void
    {
        Sanctum::actingAs($this->account('coupon-editor@example.test', true));
        $id = $this->postJson(self::ROOT, $this->payload())
            ->assertStatus(201)->json('data.id');
        $this->assertIsInt($id);
        $this->assertSame(0, (int) Coupon::findOrFail($id)->started_at);
        $this->getJson(self::ROOT . '?code=SAVE&type=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'SAVE50');
        $this->getJson(self::ROOT . '?code=missing')->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->patchJson(self::ROOT . '/' . $id . '/toggle')->assertOk()
            ->assertJsonPath('data.show', true);
        $this->putJson(self::ROOT . '/' . $id,
            array_replace($this->payload(), ['name' => 'Updated']))
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame('Updated', Coupon::findOrFail($id)->name);

        $this->putJson(self::ROOT . '/' . $id,
            array_replace($this->payload(), ['code' => 'ANOTHER']))
            ->assertStatus(422);
        $this->postJson(self::ROOT, array_replace(
            $this->payload(), ['type' => 2, 'value' => 150]
        ))->assertStatus(422);
        $this->postJson(self::ROOT, array_replace(
            $this->payload(), ['started_at' => 200, 'ended_at' => 100]
        ))->assertStatus(422);
        $this->postJson(self::ROOT, $this->payload())->assertStatus(422);
        $this->deleteJson(self::ROOT . '/' . $id)->assertOk()
            ->assertJsonPath('data.ok', true);
        $this->assertNull(Coupon::find($id));
    }

    public function test_coupon_used_by_order_cannot_be_deleted_via_native_or_v2(): void
    {
        $owner = $this->account('coupon-order-owner@example.test');
        $admin = $this->account('coupon-order-admin@example.test', true);
        $coupon = $this->coupon('ORDERHISTORY');
        Order::create([
            'user_id' => $owner->id,
            'plan_id' => 1,
            'coupon_id' => $coupon->id,
            'trade_no' => 'COUPONORDER123',
            'period' => 'monthly',
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_CANCELLED,
            'total_amount' => 150,
        ]);
        Sanctum::actingAs($admin);
        $this->deleteJson(self::ROOT . '/' . $coupon->id)
            ->assertStatus(409)->assertJsonPath('error.code', 'COUPON_IN_USE');
        $this->postJson('/api/v2/coupon_admin_secret/coupon/drop', [
            'id' => $coupon->id,
        ])->assertStatus(409);
        $this->assertNotNull(Coupon::find($coupon->id));
    }

    public function test_native_batch_csv_is_bounded_downloaded_and_formula_escaped(): void
    {
        Sanctum::actingAs($this->account('coupon-batch-admin@example.test', true));
        $this->postJson(self::ROOT . '/export',
            array_replace($this->payload(), ['generate_count' => 501]))
            ->assertStatus(422);
        $result = $this->post(self::ROOT . '/export',
            array_replace($this->payload(), [
                'name' => '=SUM(1,1)', 'generate_count' => 2,
            ]));
        $result->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment', (string) $result->headers->get('Content-Disposition'));
        $this->assertSame(2, Coupon::query()->count());
        $body = $result->streamedContent();
        $this->assertStringContainsString("'=SUM(1,1)", $body);
        $this->assertStringNotContainsString('coupon-batch-admin@example.test', $body);
        $this->assertStringNotContainsString('password', $body);
    }

    private function payload(): array
    {
        return ['name' => 'Save 50', 'code' => 'SAVE50', 'type' => 1, 'value' => 5000];
    }

    private function coupon(string $code): Coupon
    {
        return Coupon::create([
            'name' => 'Existing', 'code' => $code, 'type' => 1,
            'value' => 200, 'started_at' => 0, 'ended_at' => 0,
        ]);
    }

    private function account(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'banned' => 0,
        ]);
    }
}
