<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class NativeBusinessRollbackGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'business_rollback_testing',
            'database.connections.business_rollback_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
        DB::purge('business_rollback_testing');
    }

    protected function tearDown(): void
    {
        DB::purge('business_rollback_testing');
        parent::tearDown();
    }

    /** @dataProvider ownerEvidence */
    public function test_populated_owner_refuses_before_any_schema_is_removed(string $file, string $error, array $tables, string $evidence, ?string $column): void
    {
        foreach ($tables as $table) Schema::create($table, function (Blueprint $t) use ($column): void {
            $t->integer('id')->primary();
            if ($column !== null) $t->integer($column)->nullable();
        });
        DB::table($evidence)->insert($column === null ? ['id' => 1] : ['id' => 1, $column => 7]);
        $migration = require database_path('migrations/'.$file.'.php');
        try {
            $migration->down();
            $this->fail('Populated owner state must refuse rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame($error, $exception->getMessage());
        }
        foreach ($tables as $table) $this->assertTrue(Schema::hasTable($table), $table.' was removed before the guard');
        $this->assertSame(1, DB::table($evidence)->count());
        if ($column !== null) $this->assertSame(7, DB::table($evidence)->value($column));
    }

    public static function ownerEvidence(): array
    {
        return [
            'content' => ['2026_10_19_000003_native_content_workflows', 'NATIVE_CONTENT_FORWARD_RECOVERY_REQUIRED',
                ['tx_content_audit', 'tx_content_request', 'tx_notice', 'tx_knowledge'], 'tx_knowledge', null],
            'support-assignment' => ['2026_10_19_000007_native_support_assignment_audit', 'NATIVE_SUPPORT_ASSIGNMENT_FORWARD_RECOVERY_REQUIRED',
                ['tx_support_audit', 'tx_ticket'], 'tx_ticket', 'assigned_admin_id'],
            'support-audit' => ['2026_10_19_000007_native_support_assignment_audit', 'NATIVE_SUPPORT_ASSIGNMENT_FORWARD_RECOVERY_REQUIRED',
                ['tx_support_audit', 'tx_ticket'], 'tx_support_audit', null],
            'attachment' => ['2026_10_19_000008_native_support_attachments', 'NATIVE_SUPPORT_ATTACHMENTS_FORWARD_RECOVERY_REQUIRED',
                ['tx_support_attachment_request', 'tx_support_attachment_chunk', 'tx_support_attachment'], 'tx_support_attachment', null],
        ];
    }

    public function test_unrecognized_content_collection_state_is_preserved(): void
    {
        Schema::create('tx_content_audit', fn (Blueprint $t) => $t->integer('id'));
        Schema::create('tx_content_collection', fn (Blueprint $t) => $t->string('kind')->primary());
        DB::table('tx_content_collection')->insert(['kind' => 'future-owner']);
        $migration = require database_path('migrations/2026_10_19_000003_native_content_workflows.php');
        try {
            $migration->down();
            $this->fail('Unrecognized lock rows must survive rollback');
        } catch (RuntimeException $error) {
            $this->assertSame('NATIVE_CONTENT_FORWARD_RECOVERY_REQUIRED', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('tx_content_audit'));
        $this->assertSame('future-owner', DB::table('tx_content_collection')->value('kind'));
    }
}
