<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\FinancialInvariantAudit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialInvariantAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_rows_produce_safe_pass_report_without_order_or_user_details(): void
    {
        $result = app(FinancialInvariantAudit::class)->snapshot();
        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['orders_scanned']);
        $this->assertSame(0, array_sum($result['violations']));
        $this->assertTrue($result['requires_external_provider_reconciliation']);
        $this->assertArrayNotHasKey('users', $result);
    }

    public function test_negative_wallet_amounts_and_duplicate_provider_reference_are_flagged(): void
    {
        $owner = User::create([
            'email' => 'p3-audit@example.test', 'password' => 'test',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => -10, 'commission_balance' => -2, 'banned' => 0,
        ]);
        $plan = Plan::create([
            'name' => 'Audit plan', 'group_id' => 1,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 2, 'capacity_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ]);
        $payment = Payment::create([
            'uuid' => 'audit-payment', 'payment' => 'EPay',
            'name' => 'P3 audit', 'enable' => true,
            'config' => ['key' => 'not-reported'],
        ]);
        for ($i = 0; $i < 2; $i++) {
            Order::create([
                'user_id' => $owner->id, 'plan_id' => $plan->id,
                'payment_id' => $payment->id,
                'trade_no' => 'p3aud' . $i . bin2hex(random_bytes(6)),
                'type' => Order::TYPE_NEW_PURCHASE,
                'period' => Plan::PERIOD_MONTHLY,
                'status' => Order::STATUS_PROCESSING,
                'total_amount' => $i === 0 ? -1 : 100,
                'paid_at' => time(),
                'callback_no' => 'shared-provider-transaction',
            ]);
        }
        $result = app(FinancialInvariantAudit::class)->snapshot();
        $this->assertFalse($result['passed']);
        $this->assertSame(2, $result['orders_scanned']);
        $this->assertSame(1, $result['violations']['duplicate_provider_transactions']);
        $this->assertSame(1, $result['violations']['orders_negative_total']);
        $this->assertSame(1, $result['violations']['negative_wallet_balances']);
        $this->assertSame(1, $result['violations']['negative_commission_wallet_balances']);
        $serialized = json_encode($result);
        $this->assertStringNotContainsString($owner->email, $serialized);
        $this->assertStringNotContainsString('shared-provider-transaction', $serialized);
        $this->assertStringNotContainsString('not-reported', $serialized);
    }
}
