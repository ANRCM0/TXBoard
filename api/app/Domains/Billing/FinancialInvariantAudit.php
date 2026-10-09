<?php

namespace App\Domains\Billing;

use App\Models\Order;
use App\Models\CommissionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Read-only accounting invariant scanner. This is NOT provider bank statement
 * reconciliation and does not prove all historical payments are complete.
 * No email, token, user id, trade number or provider transaction is exported.
 */
final class FinancialInvariantAudit
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $duplicateProviderTransactions = DB::table('v2_order')
            ->select('payment_id', 'callback_no')
            ->whereNotNull('payment_id')->whereNotNull('callback_no')
            ->whereIn('status', [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED])
            ->groupBy('payment_id', 'callback_no')
            ->havingRaw('COUNT(*) > 1');

        $violations = [
            'orders_negative_total' => Order::query()->where('total_amount', '<', 0)->count(),
            'orders_negative_balance_applied' => Order::query()->where('balance_amount', '<', 0)->count(),
            'orders_negative_discount' => Order::query()->where('discount_amount', '<', 0)->count(),
            'orders_negative_handling_fee' => Order::query()->where('handling_amount', '<', 0)->count(),
            'orders_paid_without_paid_at' => Order::query()
                ->whereIn('status', [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED])
                ->whereNull('paid_at')->count(),
            'duplicate_provider_transactions' => DB::query()
                ->fromSub($duplicateProviderTransactions, 'duplicate_payments')->count(),
            'negative_wallet_balances' => User::query()->where('balance', '<', 0)->count(),
            'negative_commission_wallet_balances' => User::query()
                ->where('commission_balance', '<', 0)->count(),
            'negative_commission_entries' => CommissionLog::query()
                ->where('get_amount', '<', 0)->count(),
        ];
        return [
            'schema_version' => 1,
            'scope' => 'stored_money_and_order_invariants_only',
            'tables_scanned' => ['v2_order', 'v2_user', 'v2_commission_log'],
            'orders_scanned' => Order::query()->count(),
            'violations' => $violations,
            'passed' => array_sum($violations) === 0,
            'requires_external_provider_reconciliation' => true,
        ];
    }
}
