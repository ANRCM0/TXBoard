<?php

namespace Tests\Feature\Txapi;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Http\Controllers\Txapi\BillingController;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\NativeSubscriptionTestCase;

/** Adapter/domain integration; production activation remains guarded separately. */
class TxapiPublishedOrderAdapterTest extends NativeSubscriptionTestCase
{
    public function test_native_offer_and_required_key_create_one_frozen_pending_order(): void
    {
        $user = $this->user(['balance' => 5000, 'discount' => 90, 'plan_id' => 999]);
        Sanctum::actingAs($user);
        $offer = $this->publishedOffer($this->plan());
        $before = $user->fresh()->getAttributes();

        $first = $this->create(['offer_id' => $offer], 'same-key');
        $second = $this->create(['offer_id' => $offer], 'same-key');

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame($first->getData(true)['data'], $second->getData(true)['data']);
        $this->assertNotEmpty($first->getData(true)['request_id']);
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderPurchaseTerms::query()->count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
        $order = Order::query()->firstOrFail();
        $this->assertSame('tx-core.purchase-terms/v2', $order->purchase_terms_version);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
        $this->assertSame(1234, (int) $order->total_amount);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(0, DB::table('tx_subscription')->count());
    }

    public function test_legacy_and_financial_inputs_are_explicitly_rejected_without_claims(): void
    {
        Sanctum::actingAs($this->user());
        $offer = $this->publishedOffer($this->plan());
        foreach (['plan_id', 'period', 'coupon_code', 'balance_amount', 'surplus_credit', 'commission_balance', 'total_amount'] as $field) {
            $response = $this->create(['offer_id' => $offer, $field => 1], 'input-'.$field);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame('NATIVE_PURCHASE_INPUT_INVALID', $response->getData(true)['error']['code']);
            $this->assertSame([$field], $response->getData(true)['error']['fields']);
        }
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
    }

    public function test_missing_or_malformed_idempotency_key_refuses_before_domain_mutation(): void
    {
        Sanctum::actingAs($this->user());
        $offer = $this->publishedOffer($this->plan());
        $missing = $this->create(['offer_id' => $offer]);
        $this->assertSame(400, $missing->getStatusCode());
        $this->assertSame('IDEMPOTENCY_KEY_REQUIRED', $missing->getData(true)['error']['code']);
        $invalid = $this->create(['offer_id' => $offer], 'contains spaces');
        $this->assertSame(400, $invalid->getStatusCode());
        $this->assertSame('VALIDATION_ERROR', $invalid->getData(true)['error']['code']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $this->nativeAdmissions);
    }

    public function test_offer_identity_is_required_and_validation_never_uses_legacy_plan_defaults(): void
    {
        Sanctum::actingAs($this->user());
        foreach ([[], ['offer_id' => 0], ['offer_id' => 'not-an-id']] as $body) {
            try {
                $this->create($body, 'invalid');
                $this->fail('Missing or invalid offer was accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('offer_id', $error->errors());
            }
        }
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $this->nativeAdmissions);
    }

    public function test_changed_offer_conflicts_and_unavailable_offer_does_not_fall_back_to_plan_prices(): void
    {
        Sanctum::actingAs($this->user());
        $plan = $this->plan();
        $offer = $this->publishedOffer($plan);
        $other = (int) DB::table('tx_plan_offer')->where('plan_id', $plan->id)->where('offer_code', 'yearly')->value('id');
        $this->assertSame(201, $this->create(['offer_id' => $offer], 'one')->getStatusCode());
        $this->assertSame(409, $this->create(['offer_id' => $other], 'one')->getStatusCode());
        Sanctum::actingAs($this->user());
        DB::table('tx_plan_offer_availability')->where('offer_id', $offer)->update(['sell' => false]);
        $unavailable = $this->create(['offer_id' => $offer], 'unavailable');
        $this->assertSame(409, $unavailable->getStatusCode());
        $this->assertSame('ORDER_REJECTED', $unavailable->getData(true)['error']['code']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_installation_denial_returns_bounded_error_and_rolls_back_the_native_transaction(): void
    {
        Sanctum::actingAs($this->user());
        $offer = $this->publishedOffer($this->plan());
        $authority = Mockery::mock(InstallationAuthority::class);
        $authority->shouldReceive('inspect')->once()->andReturn(['mode' => 'native']);
        $authority->shouldReceive('assertNativeMutation')->once()
            ->andThrow(new InstallationAuthorityException('INSTALLATION_AUTHORITY_NATIVE_NOT_READY'));
        $this->app->instance(InstallationAuthority::class, $authority);

        $response = $this->create(['offer_id' => $offer], 'not-ready');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('INSTALLATION_NATIVE_NOT_READY', $response->getData(true)['error']['code']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
    }

    private function create(array $body, ?string $key = null): JsonResponse
    {
        $request = Request::create('/txapi/orders', 'POST', $body);
        if ($key !== null) $request->headers->set('Idempotency-Key', $key);
        return app(BillingController::class)->createOrder($request);
    }
}
