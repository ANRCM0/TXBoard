<?php

namespace Tests\Feature\Txapi;

use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\InviteCode;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Internal native-only Batch 5 financial, ownership and repeat-request gate.
 * This is deterministic HTTP/SQL coverage, not an external payment/Node test.
 */
class TxapiLegacyBatch5Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_all_batch_five_writes_require_user_authentication(): void
    {
        foreach ([
            ['/txapi/invites', []],
            ['/txapi/billing/commission-transfer', ['transfer_amount' => 1]],
            ['/txapi/billing/withdrawals', ['withdraw_method' => '支付宝', 'withdraw_account' => 'test']],
            ['/txapi/gift-cards/check', ['code' => 'GCTEST']],
            ['/txapi/gift-cards/redeem', ['code' => 'GCTEST']],
            ['/txapi/billing/stripe-public-key', ['id' => 1]],
        ] as [$url, $payload]) {
            $this->postJson($url, $payload)->assertStatus(401);
        }
        $this->getJson('/txapi/invites')->assertStatus(401);
        $this->getJson('/txapi/gift-cards/history')->assertStatus(401);
    }

    public function test_invitation_generation_limit_and_owner_scope(): void
    {
        admin_setting(['invite_gen_limit' => 1]);
        $owner = $this->user('batch5-invite-owner@example.test');
        $other = $this->user('batch5-invite-other@example.test');
        Sanctum::actingAs($owner);
        $this->postJson('/txapi/invites')->assertStatus(201);
        $this->postJson('/txapi/invites')->assertStatus(409)
            ->assertJsonPath('error.code', 'INVITE_LIMIT_REACHED');
        $this->assertSame(1, InviteCode::where('user_id', $owner->id)->count());
        $ownerCode = InviteCode::where('user_id', $owner->id)->value('code');
        $this->getJson('/txapi/invites')->assertOk()->assertJsonPath('data.codes.0.code', $ownerCode);

        Sanctum::actingAs($other);
        $this->getJson('/txapi/invites')->assertOk()->assertJsonCount(0, 'data.codes');
        $this->postJson('/txapi/invites')->assertStatus(201);
        $this->assertSame(1, InviteCode::where('user_id', $other->id)->count());
    }

    public function test_commission_transfer_keeps_wallet_sum_and_rejects_insufficient_or_invalid_amount(): void
    {
        admin_setting(['commission_transfer_limit' => 0, 'commission_withdraw_limit' => 0]);
        $owner = $this->user('batch5-transfer@example.test', [
            'balance' => 120, 'commission_balance' => 400,
        ]);
        Sanctum::actingAs($owner);
        $this->postJson('/txapi/billing/commission-transfer', ['transfer_amount' => -1])
            ->assertStatus(422);
        $this->postJson('/txapi/billing/commission-transfer', ['transfer_amount' => 150])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertSame(270, (int) $owner->fresh()->balance);
        $this->assertSame(250, (int) $owner->fresh()->commission_balance);
        $this->postJson('/txapi/billing/commission-transfer', ['transfer_amount' => 251])
            ->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_COMMISSION');
        $this->assertSame(520, (int) $owner->fresh()->balance + (int) $owner->fresh()->commission_balance);
    }

    public function test_gift_card_redemption_is_single_use_and_owner_history_is_isolated(): void
    {
        $owner = $this->user('batch5-gift-owner@example.test');
        $other = $this->user('batch5-gift-other@example.test');
        $template = GiftCardTemplate::create([
            'name' => 'Internal regression card',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => true, 'rewards' => ['balance' => 175],
            'limits' => [], 'admin_id' => 1,
        ]);
        $code = GiftCardCode::create([
            'template_id' => $template->id, 'code' => 'BATCH5CARD2026',
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0, 'max_usage' => 1,
        ]);
        Sanctum::actingAs($owner);
        $this->postJson('/txapi/gift-cards/check', ['code' => $code->code])
            ->assertOk()->assertJsonPath('data.can_redeem', true);
        $this->postJson('/txapi/gift-cards/redeem', ['code' => $code->code])
            ->assertOk()->assertJsonPath('data.rewards.balance', 175);
        $this->assertSame(175, (int) $owner->fresh()->balance);
        $this->assertSame(1, (int) $code->fresh()->usage_count);
        $this->assertSame(1, GiftCardUsage::where('code_id', $code->id)->count());
        $this->postJson('/txapi/gift-cards/redeem', ['code' => $code->code])
            ->assertStatus(409)->assertJsonPath('error.code', 'GIFT_CARD_REDEEM_REJECTED');
        $this->assertSame(175, (int) $owner->fresh()->balance);
        $this->assertSame(1, GiftCardUsage::where('code_id', $code->id)->count());
        $this->getJson('/txapi/gift-cards/history')->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
        $id = GiftCardUsage::where('code_id', $code->id)->value('id');

        Sanctum::actingAs($other);
        $this->getJson('/txapi/gift-cards/history')->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
        $this->getJson("/txapi/gift-cards/history/{$id}")->assertStatus(404);
    }

    public function test_withdrawal_is_limited_to_configured_methods_and_existing_ticket_blocks_repeats(): void
    {
        admin_setting([
            'withdraw_close_enable' => 0,
            'commission_withdraw_limit' => 1,
            'commission_withdraw_method' => ['支付宝'],
        ]);
        $owner = $this->user('batch5-withdraw@example.test', ['commission_balance' => 500]);
        Sanctum::actingAs($owner);
        $this->postJson('/txapi/billing/withdrawals', [
            'withdraw_method' => 'unknown', 'withdraw_account' => 'acct',
        ])->assertStatus(422)->assertJsonPath('error.code', 'WITHDRAW_METHOD_INVALID');
        $this->postJson('/txapi/billing/withdrawals', [
            'withdraw_method' => '支付宝', 'withdraw_account' => 'acct',
        ])->assertStatus(201)->assertJsonPath('data.ok', true);
        $this->assertSame(1, Ticket::where('user_id', $owner->id)->count());
        // TicketService prohibits multiple open tickets; the native endpoint
        // must preserve that rule instead of silently creating duplicate claims.
        $this->postJson('/txapi/billing/withdrawals', [
            'withdraw_method' => '支付宝', 'withdraw_account' => 'acct',
        ])->assertStatus(409)->assertJsonPath('error.code', 'WITHDRAWAL_PENDING');
        $this->assertSame(1, Ticket::where('user_id', $owner->id)->count());
    }

    public function test_stripe_public_key_never_exposes_private_provider_config(): void
    {
        $owner = $this->user('batch5-stripe@example.test');
        $payment = Payment::create([
            'uuid' => 'batch5stripekey', 'payment' => 'StripeCredit', 'name' => 'Card',
            'enable' => true, 'config' => ['stripe_pk_live' => 'pk_test_public_only', 'stripe_sk_live' => 'sk_private'],
        ]);
        Sanctum::actingAs($owner);
        $response = $this->postJson('/txapi/billing/stripe-public-key', ['id' => $payment->id]);
        $response->assertOk()->assertJsonPath('data', 'pk_test_public_only');
        $this->assertStringNotContainsString('sk_private', $response->getContent());
        $payment->update(['enable' => false]);
        $this->postJson('/txapi/billing/stripe-public-key', ['id' => $payment->id])
            ->assertStatus(404);
    }

    private function user(string $email, array $props = []): User
    {
        return User::create(array_merge([
            'email' => $email, 'password' => 'test-only-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0, 'commission_balance' => 0, 'banned' => 0,
        ], $props));
    }
}
