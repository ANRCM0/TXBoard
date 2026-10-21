<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeAccountAdjustmentBrowserTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors;

    public function test_real_admin_gateway_retains_exact_financial_receipts_and_separate_authorization(): void
    {
        $this->activate(); $user = $this->workflowActor(); $admin = $this->workflowActor(true, ['billing.account-adjustments.manage', 'billing.reconciliation.read']);
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'account_adjustments']);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('browser', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'account_adjustments', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson(
            'https://core.example.test/gateway/v1/core/admin/operations/'.$operation, ['input' => $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
        $read = $invoke('billing.admin-account-balances.get', ['user_id' => (string) $user->id, 'currency' => 'CNY']);
        $this->assertSame(200, $read->status(), $read->getContent());
        $read->assertJsonPath('data.wallet_available_minor', '0')->assertJsonPath('data.editable', true);
        $input = ['user_id' => (string) $user->id, 'currency' => 'CNY', 'bucket' => 'wallet_available', 'direction' => 'credit',
            'amount_minor' => '9007199254740993', 'expected_ledger_revision' => $read->json('data.ledger_revision'), 'reason' => 'browser_support', 'note' => 'Adjustment evidence'];
        $key = $this->workflowKey(); $first = $invoke('billing.admin-account-adjustments.create', $input, $key)->assertOk()
            ->assertJsonPath('data.after_minor', '9007199254740993')->json('data');
        DB::table('tx_internal_request')->where('operation', 'billing.admin-account-adjustments.create')->delete();
        $invoke('billing.admin-account-adjustments.create', $input, $key)->assertOk()->assertJsonPath('data', $first);
        $invoke('billing.admin-account-adjustments.create', $input, $this->workflowKey())->assertStatus(409);
        $invoke('billing.admin-account-balances.get', ['user_id' => (string) $user->id, 'currency' => 'CNY'])->assertOk()
            ->assertJsonPath('data.wallet_available_minor', '9007199254740993')->assertJsonPath('data.wallet_reserved_minor', '0');
        $invoke('billing.admin-account-adjustments.list', ['user_id' => (string) $user->id, 'currency' => 'CNY'])->assertOk()->assertJsonPath('data.items.0', $first);
        $invoke('billing.admin-account-adjustments.create', $input + ['balance' => '0'], $this->workflowKey())->assertStatus(422);
        $this->assertSame(1, DB::table('tx_native_account_adjustment')->count());
        $this->assertSame(0, (int) $user->fresh()->balance);
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $invoke('billing.admin-account-adjustments.create', $input, $key)->assertForbidden();
        $invoke('billing.admin-account-balances.get', ['user_id' => (string) $user->id, 'currency' => 'CNY'])->assertForbidden();
        $invoke('billing.admin-account-adjustments.list', ['user_id' => (string) $user->id, 'currency' => 'CNY'])->assertForbidden();
    }
}
