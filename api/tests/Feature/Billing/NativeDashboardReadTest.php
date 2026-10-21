<?php

namespace Tests\Feature\Billing;

use App\Domains\Analytics\NativeDashboardRead;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeCommissions;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Identity\NativeReferrals;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeDashboardReadTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;
    protected array $planPrices = ['monthly' => '10.00', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'CNY';

    public function test_native_purchase_allocation_is_counted_once_and_unpaid_legacy_fields_are_ignored(): void
    {
        $this->activate();
        $buyer = $this->workflowActor();
        $this->authenticateWorkflowActor($buyer);
        $this->fundWorkflowWallet($buyer, '5000');
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $this->workflowKey());
        app(NativeWalletPayments::class)->pay($buyer->id, $order->id, $this->workflowKey());
        app(NativeBillingRecovery::class)->recover();
        $unpaid = app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $this->workflowKey());
        DB::table('tx_order')->where('id', $unpaid->id)->update(['paid_at' => time(), 'status' => 3, 'total_amount' => 999999]);
        $snapshot = app(NativeDashboardRead::class)->snapshot([], time());
        $this->assertSame([['currency' => 'CNY', 'amount_minor' => '1000', 'count' => '1']], $snapshot['cards']['today_income']);
        $this->assertSame($snapshot['cards']['today_income'], $snapshot['trends'][29]['income']);
        $this->assertSame(['nodes' => null, 'users' => null, 'devices' => null], $snapshot['online']);
        $this->assertSame([], $snapshot['rankings']['nodes']);
        $this->assertSame([], $snapshot['cards']['month_commission_transfer']);
    }

    public function test_rankings_use_native_settled_raw_usage_and_exclude_pending_batches_and_other_date_ranges(): void
    {
        // The free period supplies a real native usage grant through the production runtime.
        $this->activate();
        [$user] = $this->subscribe('onetime');
        $snapshot = $this->snapshot();
        $grant = $snapshot['usage_v2']['grants'][0];
        $entry = array_intersect_key($grant, array_flip(['access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id', 'usage_period_id']))
            + ['raw_up_bytes' => '3', 'raw_down_bytes' => '5'];
        $payload = ['protocol_version' => 2, 'node_id' => (string) $this->node->id, 'source_epoch' => $snapshot['usage_v2']['source_epoch'],
            'batch_id' => 'dashboard-batch-0001', 'started_at' => $snapshot['issued_at'], 'ended_at' => $snapshot['issued_at'] + 1, 'entries' => [$entry]];
        $canonical = \App\Domains\CredentialProvider\ProviderCodec::canonical($payload);
        $input = app(UsagePayload::class)->submission(json_encode(['payload' => $payload, 'payload_digest' => 'sha256:'.hash('sha256', $canonical)], JSON_THROW_ON_ERROR));
        app(UsageReceipts::class)->submit((int) $this->node->id, $input, $snapshot['issued_at'] + 2);
        $read = app(NativeDashboardRead::class);
        $this->assertSame([], $read->snapshot([], $snapshot['issued_at'] + 3)['rankings']['nodes']);
        app(UsageSettlement::class)->scan(10, $snapshot['issued_at'] + 3);
        $result = $read->snapshot([], $snapshot['issued_at'] + 3);
        $this->assertSame([['id' => (string) $this->node->id, 'name' => 'Runtime', 'total_bytes' => '8']], $result['rankings']['nodes']);
        $this->assertSame([['id' => (string) $user->id, 'name' => null, 'total_bytes' => '8']], $result['rankings']['users']);
        $yesterday = gmdate('Y-m-d', $snapshot['issued_at'] - 86400);
        $this->assertSame([], $read->snapshot(['start_date' => $yesterday, 'end_date' => $yesterday], $snapshot['issued_at'] + 3)['rankings']['nodes']);
    }

    public function test_oversized_scans_fail_instead_of_returning_truncated_global_totals(): void
    {
        Schema::create('dashboard_scan_fixture', static function ($table): void { $table->increments('id'); $table->integer('value'); });
        for ($chunk = 0; $chunk < 100; $chunk++) DB::table('dashboard_scan_fixture')->insert(array_fill(0, 1000, ['value' => 1]));
        DB::table('dashboard_scan_fixture')->insert(['value' => 1]);
        $method = new \ReflectionMethod(NativeDashboardRead::class, 'scan');
        $rows = $method->invoke(app(NativeDashboardRead::class), DB::table('dashboard_scan_fixture')->orderBy('id'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('DASHBOARD_SCAN_LIMIT');
        iterator_to_array($rows);
    }

    public function test_earned_commission_and_approved_wallet_transfer_are_separate_real_authority_series(): void
    {
        $this->activate();
        $admin = $this->workflowActor(true, ['billing.commissions.manage', 'billing.commissions.read']);
        $commissions = app(NativeCommissions::class);
        $this->authenticateWorkflowActor($admin);
        $this->invokeWorkflow(fn () => $commissions->execute($admin->id, true, 'policy', ['enabled' => true, 'rate_bps' => 1000,
            'hold_seconds' => 0, 'refund_policy' => NativeCommissions::REFUND_POLICY, 'expected_version' => '0'], $this->workflowKey()));
        $sponsor = $this->workflowActor(); $buyer = $this->workflowActor();
        $this->authenticateWorkflowActor($sponsor);
        $code = app(NativeReferrals::class)->execute($sponsor->id, 'code', [], $this->workflowKey());
        $this->authenticateWorkflowActor($buyer);
        app(NativeReferrals::class)->execute($buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey());
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $this->workflowKey());
        $this->fundWorkflowWallet($buyer, '1000');
        app(NativeWalletPayments::class)->pay($buyer->id, $order->id, $this->workflowKey());
        app(NativeBillingRecovery::class)->recover(); $commissions->recover(10);
        $read = app(NativeDashboardRead::class);
        $snapshot = $read->snapshot([], time());
        $earned = [['currency' => 'CNY', 'amount_minor' => '100', 'count' => '1']];
        $this->assertSame($earned, $snapshot['trends'][29]['earned_commission']);
        $this->assertSame([], $snapshot['trends'][29]['commission_transfer']);
        $this->authenticateWorkflowActor($sponsor);
        $payout = $this->invokeWorkflow(fn () => $commissions->execute($sponsor->id, false, 'payout', ['currency' => 'CNY', 'amount_minor' => '100'], $this->workflowKey()));
        $this->assertSame('1', $read->snapshot([], time())['cards']['pending_commission_payouts']);
        $this->authenticateWorkflowActor($admin);
        $this->invokeWorkflow(fn () => $commissions->execute($admin->id, true, 'approve',
            ['payout_id' => $payout['id'], 'expected_version' => '1', 'reason' => 'dashboard-test'], $this->workflowKey()));
        $snapshot = $read->snapshot([], time());
        $this->assertSame($earned, $snapshot['trends'][29]['earned_commission']);
        $this->assertSame($earned, $snapshot['trends'][29]['commission_transfer']);
        $this->assertSame($earned, $snapshot['cards']['month_commission_transfer']);
        $this->assertSame('0', $snapshot['cards']['pending_commission_payouts']);
    }
}
