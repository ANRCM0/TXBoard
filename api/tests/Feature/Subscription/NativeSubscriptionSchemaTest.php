<?php

namespace Tests\Feature\Subscription;

use App\Models\SubscriptionOperation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class NativeSubscriptionSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'native_schema_testing',
            'database.connections.native_schema_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        DB::purge('native_schema_testing');
        Schema::create('tx_order', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('purchase_terms_version', 64)->nullable();
        });
        $this->shadowMigration()->up();
        (require database_path('migrations/2026_10_12_000002_create_plan_catalog_shadow_tables.php'))->up();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        DB::purge('native_schema_testing');
        parent::tearDown();
    }

    public function test_upgrade_and_empty_native_rollback_preserve_shadow_rows_and_constraints(): void
    {
        $id = $this->subscription();
        $term = $this->terms($id);
        DB::table('tx_subscription')->where('id', $id)->update(['current_terms_id' => $term]);
        $before = (array) DB::table('tx_subscription')->where('id', $id)->first();
        unset($before['authority_version'], $before['installation_id']);
        $this->shadowMigration()->up();
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('tx_subscription_operation'));
        $this->assertSame($before, (array) DB::table('tx_subscription')->where('id', $id)->first());
        $this->rejectSql(fn () => DB::table('tx_subscription')->where('id', $id)->update(['status' => 'unknown']));
        $this->rejectSql(fn () => DB::table('tx_subscription_terms')->where('id', $term)->delete());
        $this->shadowMigration()->up();
        $this->migration()->up();
        $this->shadowMigration()->up();
        $this->assertNull(DB::table('tx_subscription')->where('id', $id)->value('authority_version'));
        $this->assertNull(DB::table('tx_subscription_terms')->where('id', $term)->value('source_order_id'));
        $this->assertCount(0, DB::table('tx_subscription_operation')->get());
    }

    public function test_native_order_and_aggregate_markers_independently_refuse_rollback(): void
    {
        $id = $this->subscription();
        foreach (['authority_version' => 'core-subscription/v1',
            'installation_id' => '550e8400-e29b-41d4-a716-446655440000'] as $field => $value) {
            DB::table('tx_subscription')->where('id', $id)->update([$field => $value]);
            $this->rejectRollback();
            DB::table('tx_subscription')->where('id', $id)->update([$field => null]);
        }
        DB::table('tx_order')->insert(['purchase_terms_version' => 'tx-core.purchase-terms/v2']);
        $this->rejectRollback();
        $this->assertTrue(Schema::hasTable('tx_subscription_operation'));
    }

    public function test_native_terms_markers_and_source_references_refuse_rollback(): void
    {
        $id = $this->subscription();
        $term = $this->terms($id);
        $order = DB::table('tx_order')->insertGetId([]);
        foreach (['schema_version' => 'core-subscription-terms/v1',
            'source_snapshot' => '{}', 'source_order_id' => $order] as $field => $value) {
            DB::table('tx_subscription_terms')->where('id', $term)->update([$field => $value]);
            $this->rejectRollback();
            DB::table('tx_subscription_terms')->where('id', $term)->update([$field => null]);
        }
        $this->rejectSql(fn () => DB::table('tx_subscription_terms')->where('id', $term)
            ->update(['plan_revision_id' => 999]));
        $this->rejectSql(fn () => DB::table('tx_subscription_terms')->where('id', $term)
            ->update(['source_order_id' => 999]));
        DB::table('tx_subscription_terms')->where('id', $term)->update(['source_order_id' => $order]);
        $this->rejectSql(fn () => DB::table('tx_order')->where('id', $order)->delete());
    }

    public function test_operation_owner_uniqueness_and_append_only_model_preserve_receipts(): void
    {
        $id = $this->subscription();
        $order = DB::table('tx_order')->insertGetId([]);
        $row = ['user_id' => 1, 'subscription_id' => $id, 'business_key' => 'fulfill:1',
            'kind' => 'fulfill', 'order_id' => $order, 'request_hash' => str_repeat('a', 64),
            'request_snapshot' => '{}', 'result_snapshot' => '{}', 'content_hash' => str_repeat('b', 64),
            'created_at' => 100];
        $this->rejectSql(fn () => DB::table('tx_subscription_operation')->insert(array_replace($row, ['user_id' => 2])));
        $operationId = DB::table('tx_subscription_operation')->insertGetId($row);
        $this->rejectSql(fn () => DB::table('tx_subscription_operation')->insert($row));
        $this->rejectSql(fn () => DB::table('tx_subscription_operation')
            ->insert(array_replace($row, ['business_key' => 'another-key'])));
        $this->rejectSql(fn () => DB::table('tx_order')->where('id', $order)->delete());
        $this->rejectSql(fn () => DB::table('tx_subscription')->where('id', $id)->delete());
        $this->rejectRollback();
        foreach (['update', 'delete'] as $operation) {
            try {
                $model = SubscriptionOperation::query()->findOrFail($operationId);
                $operation === 'update' ? $model->update(['kind' => 'reset']) : $model->delete();
                $this->fail('Expected immutable operation rejection');
            } catch (LogicException $error) {
                $this->assertStringContainsString('Subscription history', $error->getMessage());
            }
        }
        $this->assertSame('fulfill', DB::table('tx_subscription_operation')->value('kind'));
    }

    private function subscription(): int
    {
        return DB::table('tx_subscription')->insertGetId(['user_id' => 1, 'status' => 'active',
            'starts_at' => 100, 'created_at' => 100, 'updated_at' => 100]);
    }

    private function terms(int $id): int
    {
        return DB::table('tx_subscription_terms')->insertGetId(['subscription_id' => $id,
            'version_no' => 1, 'effective_from' => 100, 'reset_policy' => '{}',
            'content_hash' => str_repeat('a', 64), 'created_at' => 100]);
    }

    private function rejectSql(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected database constraint rejection');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function rejectRollback(): void
    {
        try {
            $this->migration()->down();
            $this->fail('Expected populated native rollback rejection');
        } catch (RuntimeException $error) {
            $this->assertSame('NATIVE_SUBSCRIPTION_POPULATED_ROLLBACK_REFUSED', $error->getMessage());
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_15_000004_enable_native_subscriptions.php');
    }

    private function shadowMigration(): object
    {
        return require database_path('migrations/2026_10_12_000001_create_subscription_shadow_tables.php');
    }
}
