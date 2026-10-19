<?php

namespace Tests\Support;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeMoneyLedger;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

trait NativeFinancialWorkflows
{
    use NativeWorkflowActors;

    private function fundWorkflowWallet(User $user, string $amount): void
    {
        app(NativeMoneyLedger::class)->post($user->id, ['business_type' => 'wallet_credit',
            'business_ref' => 'financial-workflow-fixture', 'idempotency_key' => $this->workflowKey(),
            'currency' => 'CNY', 'occurred_at' => (string) time(), 'postings' => [
                ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $amount],
                ['owner_id' => (string) $user->id, 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => $amount],
            ]]);
    }

    private function workflowBalance(User $user, string $purpose = 'wallet_available'): string
    {
        return (string) (DB::table('tx_money_account')->where('owner_id', $user->id)
            ->where('currency', 'CNY')->where('purpose', $purpose)->value('balance_minor') ?? '0');
    }

    private function workflowBillingFailure(string $code, Closure $operation): void
    {
        try { $operation(); $this->fail('Expected '.$code); }
        catch (BillingError $error) { $this->assertSame($code, $error->errorCode); }
    }
}
