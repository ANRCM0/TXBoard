<?php

namespace Tests\Feature\Subscription;

use App\Models\Order;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeSubscriptionTestCase;
use Tests\Support\ReadsEntitlements;

final class NativeSubscriptionEntitlementTest extends NativeSubscriptionTestCase
{
    use ReadsEntitlements;

    public function test_entitlement_uses_native_counters_and_reads_do_not_write_or_serialize_secrets(): void
    {
        [$user, $opened, $at] = $this->opened();
        DB::table('tx_usage_period')->where('id', $opened['usage_period_id'])->update([
            'upload_bytes' => 11, 'download_bytes' => 22, 'billable_bytes' => 50,
        ]);
        $before = $user->fresh()->getAttributes();
        $operations = DB::table('tx_subscription_operation')->count();
        $view = $this->readEntitlement($user->id, $at + 1);
        $this->assertTrue($view['authorized']);
        $this->assertSame('2147483648', $view['quota_bytes']);
        $this->assertSame('50', $view['consumed_bytes']);
        $this->assertSame('2147483598', $view['remaining_bytes']);
        $this->assertSame('11', $view['upload_bytes']);
        $this->assertSame('22', $view['download_bytes']);
        $this->assertSame($operations, DB::table('tx_subscription_operation')->count());
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertStringNotContainsString($user->uuid, json_encode($view));
        $this->assertStringNotContainsString($user->token, json_encode($view));
    }

    #[DataProvider('damagedProjections')]
    public function test_projection_damage_denies_without_falling_back_to_user(string $damage): void
    {
        [$user, $opened, $at, $order] = $this->opened();
        match ($damage) {
            'plan' => DB::table('tx_subscription')->where('id', $opened['subscription_id'])->update(['plan_id' => 999]),
            'installation' => DB::table('tx_subscription')->where('id', $opened['subscription_id'])->update(['installation_id' => 'other']),
            'period_number' => DB::table('tx_usage_period')->where('id', $opened['usage_period_id'])->update(['period_no' => 3]),
            'period_end' => DB::table('tx_usage_period')->where('id', $opened['usage_period_id'])->update(['ends_at' => $at + 500]),
            'terms_hash' => DB::table('tx_subscription_terms')->where('id', $opened['terms_id'])->update(['content_hash' => str_repeat('0', 64)]),
            'source_order' => DB::table('tx_order')->where('id', $order->id)->update(['status' => Order::STATUS_PROCESSING]),
        };
        $view = $this->readEntitlement($user->id, $at + 1);
        $this->assertFalse($view['authorized']);
        $this->assertSame('ENTITLEMENT_EVIDENCE_INVALID', $view['reason']);
        $this->assertArrayNotHasKey('quota_bytes', $view);
    }

    public static function damagedProjections(): array
    {
        return array_map(static fn (string $damage): array => [$damage], [
            'plan', 'installation', 'period_number', 'period_end', 'terms_hash', 'source_order',
        ]);
    }

    public function test_reset_history_gap_denies_even_when_current_period_itself_is_valid(): void
    {
        [$user, $opened, $at] = $this->opened();
        app(SubscriptionLifecycleService::class)->reset($user->id, (int) $opened['subscription_id'], 'reset', '1', $at + 100);
        DB::table('tx_usage_period')->where('id', $opened['usage_period_id'])->update(['ends_at' => $at + 99]);
        $view = $this->readEntitlement($user->id, $at + 101);
        $this->assertSame('ENTITLEMENT_EVIDENCE_INVALID', $view['reason']);
    }

    public function test_quota_and_expiry_deny_immediately_before_scheduler_runs(): void
    {
        [$user, $opened, $at] = $this->opened();
        DB::table('tx_usage_period')->where('id', $opened['usage_period_id'])->update(['billable_bytes' => 2147483648]);
        $this->assertSame('ENTITLEMENT_QUOTA_EXHAUSTED', $this->readEntitlement($user->id, $at + 1)['reason']);
        $this->assertSame('ENTITLEMENT_EXPIRED', $this->readEntitlement($user->id, $opened['expires_at'])['reason']);
        $this->assertSame('active', DB::table('tx_subscription')->value('status'));
    }

    private function opened(): array
    {
        $at = time() - 1000;
        $user = $this->user(['u' => 999, 'd' => 888, 'transfer_enable' => 9999999999, 'expired_at' => time() + 86400]);
        $order = app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($this->plan()), 'open');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $opened = app(SubscriptionFulfillmentService::class)->fulfill($order->id, $at);
        return [$user, $opened, $at, $order];
    }
}
