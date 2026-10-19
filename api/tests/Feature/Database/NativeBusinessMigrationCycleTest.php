<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NativeRuntimeTestCase;

final class NativeBusinessMigrationCycleTest extends NativeRuntimeTestCase
{
    public function test_empty_workflow_rollback_and_reapply_preserve_unrelated_production_state(): void
    {
        $files = ['2026_10_19_000003_native_content_workflows',
            '2026_10_19_000004_native_coupon_workflows', '2026_10_19_000005_native_gift_cards',
            '2026_10_19_000006_native_commissions', '2026_10_19_000007_native_support_assignment_audit', '2026_10_19_000008_native_support_attachments', '2026_10_19_000009_native_gift_batches'];
        $before = (array) DB::table('tx_server')->where('id', $this->node->id)->first();
        foreach (array_reverse($files) as $file) (require database_path('migrations/'.$file.'.php'))->down();
        foreach (['tx_content_collection', 'tx_content_request', 'tx_content_audit', 'tx_support_audit', 'tx_support_attachment', 'tx_native_coupon', 'tx_native_gift_card', 'tx_native_gift_batch', 'tx_native_commission_order', 'tx_native_referral'] as $name) {
            $this->assertFalse(Schema::hasTable($name), $name);
        }
        $this->assertFalse(Schema::hasColumn('tx_ticket', 'assigned_admin_id'));
        $this->assertFalse(Schema::hasColumn('tx_notice', 'row_version'));
        $this->assertTrue(Schema::hasTable('tx_notice'));
        $this->assertTrue(Schema::hasTable('tx_ticket'));
        foreach ($files as $file) (require database_path('migrations/'.$file.'.php'))->up();
        $this->assertSame(0, DB::table('tx_content_collection')->count());
        $this->assertTrue(Schema::hasColumn('tx_ticket', 'assigned_admin_id'));
        $this->assertTrue(Schema::hasIndex('tx_ticket', 'tx_ticket_assignment_idx'));
        $this->assertSame($before, (array) DB::table('tx_server')->where('id', $this->node->id)->first());
    }
}
