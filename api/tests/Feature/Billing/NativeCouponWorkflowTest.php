<?php

namespace Tests\Feature\Billing;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Billing\MoneyValue;
use App\Domains\Billing\NativeCouponWorkflow;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\PurchasedOrderGrant;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\InternalApi\OperationSchemas;
use App\Domains\Notifications\Internal\EventJournal;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeCouponWorkflowTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;

    protected array $planPrices = ['monthly' => '12.34', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'CNY';
    private NativeCouponWorkflow $coupons;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp(); $this->activate();
        $this->coupons = app(NativeCouponWorkflow::class);
        $this->admin = $this->workflowActor(true, ['billing.coupons.manage', 'billing.coupons.read']);
        $this->authenticateWorkflowActor($this->admin);
    }

    public function test_discount_is_frozen_and_disabling_does_not_rewrite_or_refund_usage_on_cancel(): void
    {
        $coupon = $this->createCoupon(); $buyer = $this->workflowActor(); $this->authenticateWorkflowActor($buyer);
        $key = $this->workflowKey(); $order = $this->purchase($buyer, $key);
        $terms = OrderPurchaseTerms::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(1000, (int) $order->total_amount);
        $this->assertSame('234', $terms->source_snapshot['coupon_discount']['discount_minor']);
        $this->assertSame('retained-on-order-create/v1', $terms->source_snapshot['coupon_discount']['usage_policy']);
        $this->assertSame(1, DB::table('tx_native_coupon')->value('used_total'));
        $this->authenticateWorkflowActor($this->admin);
        $this->invokeWorkflow(fn () => $this->coupons->execute($this->admin->id, 'disable',
            ['coupon_id' => $coupon['id'], 'expected_version' => '1'], $this->workflowKey()));
        $this->authenticateWorkflowActor($buyer);
        $this->assertSame($order->id, $this->purchase($buyer, $key)->id);
        $this->assertTrue((new NativeOrderLifecycle($order))->cancel());
        $this->assertSame(1, DB::table('tx_native_coupon')->value('used_total'));
        $this->assertSame(1, DB::table('tx_native_coupon_application')->count());
        $this->assertNotNull(app(PurchasedOrderGrant::class)->resolve($order->fresh(), $terms));
        $this->workflowBillingFailure('COUPON_UNAVAILABLE', fn () => $this->purchase($buyer, $this->workflowKey()));
        $this->assertSame(1, DB::table('tx_order')->where('user_id', $buyer->id)->count());
    }

    public function test_original_intent_conflict_current_authorization_and_event_failure_are_atomic(): void
    {
        $key = $this->workflowKey(); $policy = $this->policy();
        $created = $this->invokeWorkflow(fn () => $this->coupons->execute($this->admin->id, 'create', $policy, $key));
        $this->assertSame($created, $this->invokeWorkflow(fn () => $this->coupons->execute($this->admin->id,
            'create', array_reverse($policy, true), $key)));
        $this->workflowBillingFailure('COUPON_IDEMPOTENCY_CONFLICT', fn () => $this->invokeWorkflow(fn () =>
            $this->coupons->execute($this->admin->id, 'create', array_replace($policy, ['name' => 'Changed']), $key)));
        DB::table('tx_internal_actor_grant')->where('user_id', $this->admin->id)->where('permission', 'billing.coupons.manage')->delete();
        $this->workflowHttpFailure(403, fn () => $this->invokeWorkflow(fn () => $this->coupons->execute($this->admin->id, 'create', $policy, $key)));
        $token = $this->authenticateWorkflowActor($this->admin); $token->delete();
        try { $this->coupons->find($this->admin->id, $created['id']); $this->fail('Revoked session accepted'); }
        catch (AuthenticationException) { $this->assertSame(1, DB::table('tx_native_coupon_request')->count()); }
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $this->admin->id, 'permission' => 'billing.coupons.manage', 'created_at' => time()]);
        $this->authenticateWorkflowActor($this->admin);
        $events = Mockery::mock(EventJournal::class); $events->shouldReceive('appendLocked')->once()->andThrow(new \RuntimeException('INJECTED_COUPON_EVENT'));
        $failing = new NativeCouponWorkflow(app(CurrentSessionAuthorization::class), $events, app(CurrentInvocation::class));
        try { $this->invokeWorkflow(fn () => $failing->execute($this->admin->id, 'create', $this->policy(['code' => 'SECOND-01']), $this->workflowKey())); $this->fail('Expected event failure'); }
        catch (\RuntimeException $error) { $this->assertSame('INJECTED_COUPON_EVENT', $error->getMessage()); }
        $this->assertSame(1, DB::table('tx_native_coupon')->count());
        $this->assertSame(1, DB::table('tx_native_coupon_request')->count());
    }

    public function test_usage_limits_and_counter_corruption_cannot_grant_an_additional_discount(): void
    {
        $this->createCoupon(['total_limit' => 2, 'user_limit' => 1]);
        $buyer = $this->workflowActor(); $this->authenticateWorkflowActor($buyer);
        $order = $this->purchase($buyer, $this->workflowKey()); (new NativeOrderLifecycle($order))->cancel();
        $this->workflowBillingFailure('COUPON_USAGE_EXHAUSTED', fn () => $this->purchase($buyer, $this->workflowKey()));
        DB::table('tx_native_coupon_user')->where('user_id', $buyer->id)->update(['used_count' => 0]);
        try { $this->purchase($buyer, $this->workflowKey()); $this->fail('Decreased usage accepted'); }
        catch (\LogicException $error) { $this->assertSame('COUPON_USAGE_COUNTER_INVALID', $error->getMessage()); }
        $this->assertSame(1, DB::table('tx_native_coupon_application')->count());
        $this->assertSame(1, DB::table('tx_order')->where('user_id', $buyer->id)->count());
    }

    public function test_percentage_rounds_down_and_scopes_expiry_and_evidence_are_checked(): void
    {
        $this->createCoupon(['discount_kind' => 'basis_points', 'value' => '1250', 'user_limit' => 2]);
        $buyer = $this->workflowActor(); $this->authenticateWorkflowActor($buyer);
        $order = $this->purchase($buyer, $this->workflowKey());
        $this->assertSame(1080, (int) $order->total_amount);
        $row = DB::table('tx_native_coupon_application')->where('order_id', $order->id)->first();
        $quote = json_decode($row->quote_json, true); $quote['discount_minor'] = '155'; $quote['net_minor'] = '1079';
        DB::table('tx_native_coupon_application')->where('order_id', $order->id)->update([
            'quote_json' => json_encode($quote), 'content_hash' => MoneyValue::hash($quote)]);
        try { app(PurchasedOrderGrant::class)->resolve($order, OrderPurchaseTerms::query()->where('order_id', $order->id)->first()); $this->fail('Resealed discount accepted'); }
        catch (\LogicException|\RuntimeException) { $this->assertSame(1, DB::table('tx_order')->where('user_id', $buyer->id)->count()); }
        $this->authenticateWorkflowActor($this->admin);
        $this->createCoupon(['code' => 'EXPIRED-01', 'expires_at' => time() - 1]);
        $other = $this->workflowActor(); $this->authenticateWorkflowActor($other);
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $this->workflowBillingFailure('COUPON_UNAVAILABLE', fn () => app(PublishedOrderService::class)->create($other->id,
            (int) $offer['offer_id'], $this->workflowKey(), ['coupon_code' => 'EXPIRED-01']));
        $this->assertFalse(OperationSchemas::input('commerce.orders.create', ['offer_id' => '1', 'discount_minor' => '234']));
    }

    private function createCoupon(array $changes = []): array
    {
        return $this->invokeWorkflow(fn () => $this->coupons->execute($this->admin->id, 'create', $this->policy($changes), $this->workflowKey()));
    }

    private function policy(array $changes = []): array
    {
        return array_replace(['code' => 'SAVE-1234', 'name' => 'Frozen discount', 'currency' => 'CNY',
            'discount_kind' => 'fixed', 'value' => '234', 'minimum_minor' => '1000', 'total_limit' => 10,
            'user_limit' => 1, 'starts_at' => null, 'expires_at' => null, 'offer_ids' => []], $changes);
    }

    private function purchase(User $buyer, string $key): Order
    {
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        return app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $key, ['coupon_code' => 'SAVE-1234']);
    }
}
