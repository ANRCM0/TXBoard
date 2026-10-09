<?php

namespace Tests\Feature\Txapi;

use App\Jobs\SendEmailJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminStrictCloseoutTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/strict_native_path';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'strict_native_path']);
    }

    public function test_remaining_administrator_routes_enforce_role_and_dynamic_path(): void
    {
        foreach (['/modules', '/orders/assign', '/users/mail'] as $suffix) {
            $this->postJson(self::ROOT . $suffix, [])->assertStatus(403);
        }
        Sanctum::actingAs($this->user('nonadmin-strict@example.test'));
        $this->getJson(self::ROOT . '/modules')->assertStatus(403);
        $this->postJson(self::ROOT . '/orders/assign', [])->assertStatus(403);
        Sanctum::actingAs($this->user('admin-strict@example.test', true));
        $this->getJson('/txapi/admin/wrong/modules')->assertStatus(404);
        $this->getJson(self::ROOT . '/modules')->assertOk()
            ->assertJsonStructure(['data' => ['modules', 'summary'], 'request_id']);
        $this->getJson(self::ROOT . '/modules/not_found')->assertStatus(404)
            ->assertJsonPath('error.code', 'MODULE_NOT_FOUND');
        $this->postJson(self::ROOT . '/modules/theme.txboard/operations/restart')
            ->assertStatus(422)->assertJsonPath('error.code', 'MODULE_OPERATION_INVALID');
    }

    public function test_manual_order_allocation_rejects_bad_amount_and_serializes_pending_orders(): void
    {
        Sanctum::actingAs($this->user('order-migration-admin@example.test', true));
        $user = $this->user('order-migration-owner@example.test');
        $plan = Plan::create([
            'name' => 'Strict native plan', 'group_id' => 1,
            'transfer_enable' => 4, 'sell' => true, 'renew' => true,
            'prices' => ['monthly' => 12],
        ]);
        $params = [
            'email' => $user->email,
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'total_amount' => 1900,
        ];
        $this->postJson(self::ROOT . '/orders/assign',
            array_replace($params, ['total_amount' => -1]))->assertStatus(422);
        $this->postJson(self::ROOT . '/orders/assign',
            array_replace($params, ['period' => 'untrusted']))->assertStatus(422);
        $created = $this->postJson(self::ROOT . '/orders/assign', $params)
            ->assertStatus(201)->assertJsonStructure(['data' => ['trade_no'], 'request_id']);
        $order = Order::query()->where('trade_no', $created->json('data.trade_no'))->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
        $this->assertSame(1900, (int) $order->total_amount);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->postJson(self::ROOT . '/orders/assign', $params)->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_ALREADY_PENDING');
        $this->assertSame(1, Order::query()->where('user_id', $user->id)->count());

        $this->postJson(self::ROOT . '/orders/'.$order->trade_no.'/commission-review',
            ['commission_status' => 1])->assertStatus(409)
            ->assertJsonPath('error.code', 'COMMISSION_REVIEW_CONFLICT');
        $this->postJson(self::ROOT . '/orders/absent/commission-review',
            ['commission_status' => 1])->assertStatus(404);
    }

    public function test_bulk_mail_is_bounded_and_only_enqueues_selected_recipients(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('mail-migration-admin@example.test', true));
        $recipient = $this->user('native-mail-recipient@example.test');
        $body = [
            'scope' => 'selected',
            'user_ids' => [$recipient->id],
            'subject' => 'Your notice',
            'content' => 'Your subscription details',
        ];
        $this->postJson(self::ROOT . '/users/mail',
            array_replace($body, ['user_ids' => [999999]]))->assertStatus(422)
            ->assertJsonPath('error.code', 'MAIL_RECIPIENT_MISSING');
        $this->postJson(self::ROOT . '/users/mail',
            array_replace($body, ['user_ids' => array_fill(0, 501, $recipient->id)]))
            ->assertStatus(422);
        $this->postJson(self::ROOT . '/users/mail', $body)
            ->assertStatus(202)->assertJsonPath('data.queued', 1);
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_four_obsolete_v2_paths_are_absent(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())->all();
        foreach (['order/assign', 'order/update', 'user/sendMail', 'module',
            'module/{id}', 'module/{id}/lifecycle',
            'module/{id}/lifecycle/{operation}'] as $tail) {
            $this->assertNotContains('api/v2/{admin_path}/'.$tail, $routes);
        }
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email, 'password' => 'not-public',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'banned' => 0,
            'balance' => 0, 'commission_balance' => 0,
        ]);
    }
}
