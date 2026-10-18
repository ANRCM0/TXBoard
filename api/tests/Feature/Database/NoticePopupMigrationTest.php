<?php

namespace Tests\Feature\Database;

use App\Models\Notice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NativeRuntimeTestCase;

final class NoticePopupMigrationTest extends NativeRuntimeTestCase
{
    public function test_existing_notices_receive_a_real_default_and_reapplying_preserves_explicit_state(): void
    {
        $notice = Notice::create(['title' => 'Retained notice', 'content' => 'Retained body', 'show' => true]);
        Schema::table('tx_notice', static fn (Blueprint $table) => $table->dropColumn('popup'));
        $migration = require database_path('migrations/2026_10_18_040000_add_notice_popup.php');
        $migration->up();
        $this->assertTrue(Schema::hasColumn('tx_notice', 'popup'));
        $this->assertFalse($notice->fresh()->popup);
        $this->assertSame('Retained body', $notice->fresh()->content);
        DB::table('tx_notice')->where('id', $notice->id)->update(['popup' => true]);
        $migration->up();
        $this->assertTrue($notice->fresh()->popup);
        $refused = false;
        try { $migration->down(); }
        catch (\RuntimeException $error) {
            $this->assertSame('NOTICE_POPUP_POPULATED_ROLLBACK_REFUSED', $error->getMessage());
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->assertTrue($notice->fresh()->popup);
        DB::table('tx_notice')->where('id', $notice->id)->update(['popup' => false]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('tx_notice', 'popup'));
        $migration->up();
        $this->assertFalse($notice->fresh()->popup);
        $this->assertSame(1, Notice::query()->count());
    }
}
