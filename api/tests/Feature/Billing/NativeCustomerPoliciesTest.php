<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeCommissions;
use App\Domains\Billing\NativeCouponWorkflow;
use App\Domains\Billing\NativeCustomerPolicies;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Billing\PurchasedOrderGrant;
use App\Domains\Identity\NativeReferrals;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeCustomerPoliciesTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;
    protected array $planPrices = ['monthly' => '12.34', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'CNY';

    public function test_future_price_consumes_policy_and_coupon_without_rewriting_old_purchase_or_legacy_fields(): void
    {
        [$admin, $buyer, $policies] = $this->opened(); $legacy = $buyer->fresh()->getAttributes();
        $this->authenticateWorkflowActor($buyer); $original = $this->purchase($buyer->id); $originalTerms = OrderPurchaseTerms::query()->where('order_id', $original->id)->firstOrFail();
        $frozen = $originalTerms->getAttributes(); (new NativeOrderLifecycle($original))->cancel();
        $this->authenticateWorkflowActor($admin); $base = $policies->read($admin->id, $buyer->id);
        $input = ['expected_policy_revision' => $base['policy_revision'], 'changes' => ['discount_bps' => 1250], 'reason' => 'customer_case']; $key = $this->workflowKey();
        $updated = $policies->update($admin->id, $buyer->id, $input, $key);
        $this->assertSame($updated, $policies->update($admin->id, $buyer->id, $input, $key));
        $this->workflowBillingFailure('CUSTOMER_POLICY_VERSION_CONFLICT', fn () => $policies->update($admin->id, $buyer->id, $input, $this->workflowKey()));
        $coupon = ['code' => 'PERSONAL-01', 'name' => 'Combined source', 'currency' => 'CNY', 'discount_kind' => 'fixed', 'value' => '234',
            'minimum_minor' => '1000', 'total_limit' => 10, 'user_limit' => 2, 'starts_at' => null, 'expires_at' => null, 'offer_ids' => []];
        $this->invokeWorkflow(fn () => app(NativeCouponWorkflow::class)->execute($admin->id, 'create', $coupon, $this->workflowKey()));
        $this->authenticateWorkflowActor($buyer); $order = $this->purchase($buyer->id, ['coupon_code' => 'PERSONAL-01']);
        $terms = OrderPurchaseTerms::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(846, (int) $order->total_amount); // floor(1234*12.5%) =154, plus234 coupon.
        $grant = app(PurchasedOrderGrant::class)->resolve($order, $terms);
        $this->assertSame('154', $grant['customer_discount']['discount_minor']); $this->assertSame('234', $grant['coupon_discount']['discount_minor']);
        $this->assertSame('capped-list-discount-sum/v1', $grant['customer_discount']['composition']);
        $this->authenticateWorkflowActor($admin); $now = $policies->read($admin->id, $buyer->id);
        $policies->update($admin->id, $buyer->id, ['expected_policy_revision' => $now['policy_revision'], 'changes' => ['discount_bps' => 10000], 'reason' => 'future_only'], $this->workflowKey());
        $this->assertSame($grant, app(PurchasedOrderGrant::class)->resolve($order, $terms));
        $this->assertSame($frozen, $originalTerms->fresh()->getAttributes()); $this->assertSame($legacy, $buyer->fresh()->getAttributes());
        $this->assertSame(2, count($policies->history($admin->id, $buyer->id)['items']));
        DB::table('tx_native_customer_price')->where('order_id', $order->id)->update(['content_hash' => str_repeat('0', 64)]);
        $this->expectException(\RuntimeException::class); app(PurchasedOrderGrant::class)->resolve($order, $terms);
    }

    public function test_sponsor_first_paid_override_is_frozen_and_unpaid_cancellation_does_not_spend_the_claim(): void
    {
        [$admin, $buyer, $policies] = $this->opened(); $sponsor = $this->workflowActor();
        $policies->update($admin->id, $sponsor->id, ['expected_policy_revision' => $policies->read($admin->id, $sponsor->id)['policy_revision'],
            'changes' => ['commission_mode' => 'first_paid', 'commission_rate_bps' => 2500], 'reason' => 'sponsor_case'], $this->workflowKey());
        $this->invokeWorkflow(fn () => app(NativeCommissions::class)->execute($admin->id, true, 'policy', ['enabled' => true, 'rate_bps' => 1000, 'hold_seconds' => 0,
            'refund_policy' => NativeCommissions::REFUND_POLICY, 'expected_version' => '0'], $this->workflowKey()));
        $this->authenticateWorkflowActor($sponsor); $code = app(NativeReferrals::class)->execute($sponsor->id, 'code', [], $this->workflowKey());
        $this->authenticateWorkflowActor($buyer); app(NativeReferrals::class)->execute($buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey());
        $cancelled = $this->purchase($buyer->id); (new NativeOrderLifecycle($cancelled))->cancel();
        $this->assertSame(0, DB::table('tx_native_first_paid_claim')->count()); app(NativeCommissions::class)->recover(10);
        $first = $this->purchase($buyer->id); $this->fundWorkflowWallet($buyer, '5000');
        app(NativeWalletPayments::class)->pay($buyer->id, $first->id, $this->workflowKey()); app(NativeBillingRecovery::class)->recover();
        app(NativeCommissions::class)->recover(10); $firstReward = DB::table('tx_native_commission_order')->where('order_id', $first->id)->first();
        $this->assertSame('accrued', $firstReward->state); $this->assertSame('308', $this->workflowBalance($sponsor, 'commission_available'));
        $this->assertSame((string) $first->id, (string) DB::table('tx_native_first_paid_claim')->value('order_id'));
        $second = $this->purchase($buyer->id); app(NativeWalletPayments::class)->pay($buyer->id, $second->id, $this->workflowKey()); app(NativeBillingRecovery::class)->recover();
        app(NativeCommissions::class)->recover(10);
        $this->assertSame('ineligible', DB::table('tx_native_commission_order')->where('order_id', $second->id)->value('state'));
        $this->assertSame('308', $this->workflowBalance($sponsor, 'commission_available')); $this->assertSame(1, DB::table('tx_native_first_paid_claim')->count());
    }

    public function test_receipt_replay_rechecks_current_permission_and_protected_targets_and_changed_keys_conflict(): void
    {
        [$admin, $buyer, $policies] = $this->opened(); $base = $policies->read($admin->id, $buyer->id);
        $input = ['expected_policy_revision' => $base['policy_revision'], 'changes' => ['discount_bps' => 1], 'reason' => 'review']; $key = $this->workflowKey();
        $policies->update($admin->id, $buyer->id, $input, $key);
        $this->workflowBillingFailure('CUSTOMER_POLICY_IDEMPOTENCY_CONFLICT', fn () => $policies->update($admin->id, $buyer->id, array_replace($input, ['reason' => 'other']), $key));
        $this->assertFalse($policies->read($admin->id, $admin->id)['editable']);
        $this->workflowBillingFailure('CUSTOMER_POLICY_TARGET_PROTECTED', fn () => $policies->update($admin->id, $admin->id, $input, $this->workflowKey()));
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->where('permission', NativeCustomerPolicies::PERMISSION)->delete();
        $this->workflowHttpFailure(403, fn () => $policies->update($admin->id, $buyer->id, $input, $key));
    }

    public function test_real_core_gateway_replays_durable_policy_evidence_and_refuses_closed_fields_and_revoked_grant(): void
    {
        [$admin, $buyer] = $this->opened();
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'customer_policy']);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('browser', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'customer_policy', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson('https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
        $base = $invoke('billing.admin-customer-policy.get', ['user_id' => (string) $buyer->id])->assertOk()->json('data');
        $input = ['user_id' => (string) $buyer->id, 'expected_policy_revision' => $base['policy_revision'], 'changes' => ['discount_bps' => 1234, 'commission_rate_bps' => null], 'reason' => 'gateway_case'];
        $key = $this->workflowKey(); $result = $invoke('billing.admin-customer-policy.update', $input, $key)->assertOk()->json('data');
        DB::table('tx_internal_request')->where('operation', 'billing.admin-customer-policy.update')->delete();
        $invoke('billing.admin-customer-policy.update', $input, $key)->assertOk()->assertJsonPath('data', $result);
        $invoke('billing.admin-customer-policy.update', $input, $this->workflowKey())->assertStatus(409);
        $invoke('billing.admin-customer-policy.update', array_replace($input, ['changes' => ['discount' => 12.34]]), $this->workflowKey())->assertStatus(422);
        $invoke('billing.admin-customer-policy.history', ['user_id' => (string) $buyer->id])->assertOk()->assertJsonPath('data.items.0.policy.discount_bps', 1234);
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->where('permission', NativeCustomerPolicies::PERMISSION)->delete();
        $invoke('billing.admin-customer-policy.update', $input, $key)->assertForbidden();
    }

    private function opened(): array
    {
        $this->activate(); $admin = $this->workflowActor(true, [NativeCustomerPolicies::PERMISSION, 'billing.coupons.manage', 'billing.coupons.read', 'billing.commissions.manage', 'billing.commissions.read']);
        $this->authenticateWorkflowActor($admin); return [$admin, $this->workflowActor(), app(NativeCustomerPolicies::class)];
    }
    private function purchase(int $userId, array $adjustments = []): Order
    {
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly'); return app(PublishedOrderService::class)->create($userId, (int) $offer['offer_id'], $this->workflowKey(), $adjustments);
    }
}
