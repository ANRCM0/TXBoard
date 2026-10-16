<?php

namespace Tests\Support;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\OrderPurchaseTermsSnapshot;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;

abstract class NativePaymentTestCase extends NativeRuntimeTestCase
{
    protected array $planPrices = ['monthly' => '12.34', 'onetime' => '0.00'];
    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        $this->payment = Payment::query()->create([
            'uuid' => bin2hex(random_bytes(16)), 'payment' => 'native-test', 'name' => 'Verified test adapter',
            'config' => [], 'enable' => true,
        ]);
    }

    /** Trusted internal purchase builder; the public paid-offer admission gate remains closed. */
    protected function purchase(?User $user = null, string $period = 'monthly'): array
    {
        if ($user === null) {
            [, $user] = app(NativeIdentityService::class)->create(bin2hex(random_bytes(8)).'@example.test', 'runtime-password');
        }
        $offer = collect($this->offers)->firstWhere('offer_code', $period);
        $order = DB::transaction(function () use ($user, $offer): Order {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            app(NativeSubscriptionStore::class)->lockAccount($user->id, time(), $installation['installation_id']);
            $published = app(PlanPublicationService::class)->verifiedOffer((int) $offer['offer_id'], true, true);
            $order = Order::query()->create([
                'user_id' => $user->id, 'plan_id' => (int) $published['offer']->plan_id,
                'period' => $published['offer']->offer_code, 'type' => Order::TYPE_NEW_PURCHASE,
                'status' => Order::STATUS_PENDING, 'trade_no' => bin2hex(random_bytes(18)),
                'total_amount' => (int) $published['offer']->price_minor,
                'purchase_terms_version' => 'tx-core.purchase-terms/v2',
                'balance_amount' => 0, 'discount_amount' => 0, 'surplus_amount' => 0,
                'surplus_credit' => 0, 'commission_balance' => 0,
            ]);
            app(OrderPurchaseTermsSnapshot::class)->capturePublished($order, $published);
            return $order;
        });
        return [$user, $order];
    }

    protected function fact(array $attempt, array $overrides = []): array
    {
        return array_replace([
            'attempt_id' => $attempt['attempt_id'], 'provider' => $attempt['provider'],
            'provider_account_ref' => $attempt['provider_account_ref'], 'transaction_ref' => 'settled-transaction-1',
            'amount_minor' => $attempt['amount_minor'], 'currency' => $attempt['currency'], 'occurred_at' => '1792108800',
        ], $overrides);
    }

    protected function rejects(string $code, \Closure $action): void
    {
        try {
            $action();
            $this->fail('Expected '.$code);
        } catch (BillingError $error) {
            $this->assertSame($code, $error->errorCode);
        }
    }
}
