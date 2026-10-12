<?php

namespace Tests\Feature\Subscription;

use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\PlanRevision;
use App\Models\Subscription;
use App\Models\SubscriptionTerms;
use App\Services\OrderService;
use App\Services\Subscription\PlanCatalogShadowService;
use App\Services\Subscription\PlanCatalogShadowReconciler;
use App\Support\Setting as RuntimeSetting;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PlanCatalogShadowTest extends TestCase
{
    private array $context = ['billing_timezone' => 'Asia/Shanghai', 'currency' => 'USD'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['core_shadow.isolated_copy' => true]);
        if (getenv('CORE_CATALOG_TEST_MYSQL') === '1') {
            config(['database.default' => 'plan_catalog_mysql_testing',
                'database.connections.plan_catalog_mysql_testing' => config('database.connections.mysql')]);
            DB::purge('plan_catalog_mysql_testing');
            if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') {
                throw new RuntimeException('CATALOG_TEST_DEDICATED_DATABASE_REQUIRED');
            }
            $this->clearMysqlCatalogFixture();
        } else {
            config([
                'database.default' => 'plan_catalog_testing',
                'database.connections.plan_catalog_testing' => array_merge(config('database.connections.sqlite'), [
                    'database' => ':memory:', 'foreign_key_constraints' => true,
                ]),
            ]);
            DB::purge('plan_catalog_testing');
        }
        Schema::create('tx_plan', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name');
            $table->integer('group_id')->nullable();
            $table->unsignedBigInteger('transfer_enable')->nullable();
            $table->unsignedInteger('speed_limit')->nullable();
            $table->unsignedInteger('device_limit')->nullable();
            $table->integer('reset_traffic_method')->nullable();
            $table->json('prices')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('sell')->default(true);
            $table->boolean('renew')->default(true);
            $table->unsignedInteger('capacity_limit')->nullable();
            $table->text('content')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if (getenv('CORE_CATALOG_TEST_MYSQL') === '1' && DB::getDriverName() === 'mysql'
            && DB::connection()->getDatabaseName() === 'txboard_concurrency_test') {
            $this->clearMysqlCatalogFixture();
            DB::purge('plan_catalog_mysql_testing');
        }
        DB::purge('plan_catalog_testing');
        parent::tearDown();
    }

    public function test_current_catalog_units_and_calendar_quotes_are_frozen_without_changing_legacy_rows(): void
    {
        $plan = $this->plan();
        $this->prices($plan, '{"monthly":0.29,"yearly":"120.10","onetime":99,"reset_traffic":1.2}');
        $before = DB::table('tx_plan')->where('id', $plan->id)->first();
        $result = $this->captureCatalog();
        $this->assertSame('mapped', $result['results'][0]['status']);
        $this->assertEquals($before, DB::table('tx_plan')->where('id', $plan->id)->first());
        $revision = PlanRevision::query()->firstOrFail();
        $this->assertSame('2147483648', $revision->quota_bytes);
        $this->assertSame('Asia/Shanghai', $revision->billing_timezone);
        $this->assertSame(0, $revision->reset_policy['method']);
        $monthly = PlanOffer::query()->where('offer_code', 'monthly')->firstOrFail();
        $this->assertSame('29', $monthly->price_minor);
        $this->assertSame(1, $monthly->calendar_months);
        $this->assertNull($monthly->duration_seconds);
        $this->assertSame('USD', $monthly->currency);
        $this->assertSame('UTC', $monthly->calendar_policy['expiry_timezone']);
        $this->assertSame(12, PlanOffer::query()->where('offer_code', 'yearly')->firstOrFail()->calendar_months);
        $this->assertSame('perpetual', PlanOffer::query()->where('offer_code', 'onetime')->firstOrFail()->purchase_kind);
        $this->assertSame('traffic_reset', PlanOffer::query()->where('offer_code', 'reset_traffic')->firstOrFail()->purchase_kind);
        $this->assertSame(4, DB::table('tx_plan_offer_availability')->where('status', 'shadow')->count());
        $this->assertSame((string) $monthly->id, $monthly->toArray()['id']);
        $this->assertSame('29', $monthly->toArray()['price_minor']);
    }

    public function test_identical_content_is_idempotent_across_retry_and_new_runs(): void
    {
        $plan = $this->plan();
        $this->captureCatalog();
        $revision = PlanRevision::query()->firstOrFail()->getAttributes();
        $availability = DB::table('tx_plan_offer_availability')->first();
        $this->assertSame([], $this->captureCatalog()['results']);
        $retry = $this->captureCatalog(retry: $plan->id);
        $this->assertSame('unchanged', $retry['results'][0]['status']);
        $this->captureCatalog('second-run');
        $this->assertSame(1, PlanRevision::query()->count());
        $this->assertSame($revision, PlanRevision::query()->firstOrFail()->getAttributes());
        $this->assertEquals($availability, DB::table('tx_plan_offer_availability')->first());
        $this->assertSame('catalog-test', DB::table('tx_plan_catalog_shadow')->value('run_key'));
    }

    public function test_source_change_appends_new_quotes_and_preserves_prior_history(): void
    {
        $plan = $this->plan();
        $this->captureCatalog();
        $first = PlanOffer::query()->firstOrFail();
        $firstHash = PlanRevision::query()->firstOrFail()->content_hash;
        $this->prices($plan, '{"monthly":12.34}');
        $result = $this->captureCatalog(retry: $plan->id);
        $this->assertSame('revised', $result['results'][0]['status']);
        $this->assertSame(2, PlanRevision::query()->count());
        $this->assertSame('10', $first->fresh()->price_minor);
        $this->assertSame($firstHash, PlanRevision::query()->orderBy('id')->firstOrFail()->content_hash);
        $this->assertSame('1234', PlanOffer::query()->orderByDesc('id')->firstOrFail()->price_minor);
        $this->assertSame('superseded', DB::table('tx_plan_offer_availability')->where('offer_id', $first->id)->value('status'));
    }

    public function test_sale_flags_change_the_separate_availability_projection_only(): void
    {
        $plan = $this->plan();
        $this->captureCatalog();
        $offer = PlanOffer::query()->firstOrFail();
        $hash = PlanRevision::query()->firstOrFail()->content_hash;
        $plan->update(['show' => false, 'sell' => false, 'renew' => true, 'capacity_limit' => 4]);
        $this->captureCatalog(retry: $plan->id);
        $this->assertSame(1, PlanRevision::query()->count());
        $this->assertSame($hash, PlanRevision::query()->firstOrFail()->content_hash);
        $availability = DB::table('tx_plan_offer_availability')->where('offer_id', $offer->id)->first();
        $this->assertSame(0, (int) $availability->sell);
        $this->assertSame(4, (int) $availability->capacity_limit);
        $this->assertSame('shadow', $availability->status);
        $this->assertSame(2, (int) $availability->row_version);
    }

    public function test_bounded_resume_excludes_plans_created_after_the_frozen_high_water(): void
    {
        $this->plan();
        $this->plan();
        $third = $this->plan();
        $first = $this->captureCatalog(limit: 1);
        $this->assertSame('processing', $first['phase']);
        $this->assertSame((string) $third->id, $first['high_water_plan_id']);
        $fourth = $this->plan();
        $this->captureCatalog(limit: 1);
        $last = $this->captureCatalog(limit: 1);
        $this->assertSame('complete', $last['phase']);
        $this->assertSame('3', $last['processed_count']);
        $this->assertSame(3, PlanRevision::query()->count());
        $this->assertFalse(DB::table('tx_plan_catalog_shadow')->where('legacy_plan_id', $fourth->id)->exists());
        $this->captureCatalog('later-plans');
        $this->assertSame(4, PlanRevision::query()->count());
    }

    public function test_invalid_exact_price_is_retryable_and_does_not_leave_partial_quotes(): void
    {
        $invalid = $this->plan();
        $this->prices($invalid, '{"monthly":0.10000000000000001}');
        $valid = $this->plan();
        $result = $this->captureCatalog();
        $this->assertSame('CATALOG_PRICE_PRECISION_UNSUPPORTED', $result['results'][0]['error_code']);
        $this->assertSame('mapped', $result['results'][1]['status']);
        $this->assertSame('1', $result['unresolved_count']);
        $this->assertSame(1, PlanRevision::query()->count());
        $this->assertSame($valid->id, PlanRevision::query()->firstOrFail()->plan_id);
        $this->prices($invalid, '{"monthly":0.1}');
        $retry = $this->captureCatalog(retry: $invalid->id);
        $this->assertSame('0', $retry['unresolved_count']);
        $this->assertSame('resolved', DB::table('tx_plan_catalog_exception')->value('state'));
        $this->assertSame(1, (int) DB::table('tx_plan_catalog_exception')->value('attempts'));
        $this->assertSame(2, PlanRevision::query()->count());
    }

    public function test_decimal_conversion_accepts_exact_values_and_checks_current_legacy_quote_parity(): void
    {
        $service = app(PlanCatalogShadowService::class);
        foreach ([[0.29, '29'], [0.1, '10'], ['1.2300', '123'], ['1e-2', '1'], ['1e2', '10000'], [0, '0']] as [$value, $expected]) {
            $this->assertSame($expected, $service->legacyPriceMinor($value));
        }
        foreach ([
            ['0.009', 'CATALOG_PRICE_PRECISION_UNSUPPORTED'], ['1e-3', 'CATALOG_PRICE_PRECISION_UNSUPPORTED'],
            ['-1', 'CATALOG_PRICE_INVALID'], [INF, 'CATALOG_PRICE_INVALID'],
            ['92233720368547758.08', 'CATALOG_PRICE_OUT_OF_RANGE'],
            ['90071992547409.93', 'CATALOG_LEGACY_QUOTE_PRECISION_MISMATCH'],
        ] as [$value, $code]) {
            try {
                $service->legacyPriceMinor($value);
                $this->fail('Invalid or legacy-incompatible price was accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame($code, $error->getMessage());
            }
        }
    }

    public function test_ambiguous_or_unsupported_json_price_shapes_are_rejected(): void
    {
        $plan = $this->plan();
        foreach ([
            '{"monthly":1,"monthly":2}' => 'CATALOG_DUPLICATE_PRICE_KEY',
            '{"monthly":true}' => 'CATALOG_PRICES_JSON_INVALID',
            '{"monthly":{"amount":1}}' => 'CATALOG_PRICES_JSON_INVALID',
            '{"unknown_period":1}' => 'CATALOG_PERIOD_UNSUPPORTED',
        ] as $json => $code) {
            $this->prices($plan, $json);
            $result = $this->captureCatalog(retry: DB::table('tx_plan_catalog_checkpoint')->exists() ? $plan->id : null);
            $this->assertSame($code, $result['results'][0]['error_code']);
        }
        $this->assertSame(0, PlanRevision::query()->count());
        $this->assertSame(4, (int) DB::table('tx_plan_catalog_exception')->value('attempts'));
    }

    public function test_quota_overflow_does_not_create_a_shadow_revision(): void
    {
        $this->plan(['transfer_enable' => '8589934592']);
        $result = $this->captureCatalog();
        $this->assertSame('CATALOG_QUOTA_OUT_OF_RANGE', $result['results'][0]['error_code']);
        $this->assertSame(0, PlanRevision::query()->count());
    }

    public function test_null_and_zero_quota_preserve_distinct_legacy_semantics(): void
    {
        $null = $this->plan(['transfer_enable' => null, 'prices' => []]);
        $zero = $this->plan(['transfer_enable' => 0]);
        $this->captureCatalog();
        $nullRevision = PlanRevision::query()->where('plan_id', $null->id)->firstOrFail();
        $this->assertNull($nullRevision->quota_bytes);
        $this->assertSame('legacy_null_unknown', $nullRevision->source_snapshot['quota_semantics']);
        $this->assertSame(0, $nullRevision->offers()->count());
        $this->assertSame('0', PlanRevision::query()->where('plan_id', $zero->id)->firstOrFail()->quota_bytes);
    }

    public function test_follow_system_reset_is_resolved_and_frozen_from_the_actual_runtime_setting(): void
    {
        $settings = Mockery::mock(RuntimeSetting::class);
        $settings->shouldReceive('get')->with('reset_traffic_method')->andReturn(4);
        $this->app->instance(RuntimeSetting::class, $settings);
        $this->plan(['reset_traffic_method' => null]);
        $this->captureCatalog();
        $policy = PlanRevision::query()->firstOrFail()->reset_policy;
        $this->assertSame(4, $policy['method']);
        $this->assertNull($policy['plan_method']);
        $this->assertFalse($policy['indefinite_expiry_resets']);
    }

    public function test_explicit_currency_and_timezone_are_required_and_cannot_change_mid_run(): void
    {
        $this->plan();
        $service = app(PlanCatalogShadowService::class);
        foreach ([['billing_timezone' => 'Mars/City', 'currency' => 'USD'],
            ['billing_timezone' => 'UTC', 'currency' => 'JPY'], ['billing_timezone' => 'UTC', 'currency' => 'ZZZ']] as $context) {
            try {
                $service->run('invalid-context', 10, $context);
                $this->fail('An unsupported context must be rejected.');
            } catch (\InvalidArgumentException $error) {
                $this->assertContains($error->getMessage(), ['INVALID_CATALOG_TIMEZONE', 'CATALOG_CURRENCY_UNSUPPORTED']);
            }
        }
        $this->assertSame(0, DB::table('tx_plan_catalog_checkpoint')->count());
        $this->captureCatalog();
        $this->expectExceptionMessage('CATALOG_CONTEXT_CHANGED');
        $service->run('catalog-test', 10, ['billing_timezone' => 'UTC', 'currency' => 'EUR']);
    }

    public function test_calendar_preview_matches_the_existing_order_algorithm_instead_of_fixed_days(): void
    {
        $this->plan();
        $this->captureCatalog();
        $offer = PlanOffer::query()->firstOrFail();
        $anchor = Carbon::create(2030, 1, 31, 12, 0, 0, 'UTC')->timestamp;
        $method = new \ReflectionMethod(OrderService::class, 'getTime');
        $legacy = $method->invoke(new OrderService(new Order()), Plan::PERIOD_MONTHLY, $anchor);
        $preview = app(PlanCatalogShadowService::class)->previewCalendarEnd($offer, $anchor);
        $this->assertSame($legacy, $preview);
        $this->assertNotSame($anchor + 30 * 86400, $preview);
        $this->assertSame(Carbon::shouldOverflowMonths() ? '2030-03-03' : '2030-02-28', Carbon::createFromTimestamp($preview)->format('Y-m-d'));
    }

    public function test_current_plan_catalog_does_not_overwrite_custom_user_terms_or_enable_revision_references(): void
    {
        (require database_path('migrations/2026_10_12_000001_create_subscription_shadow_tables.php'))->up();
        $plan = $this->plan();
        $subscription = Subscription::create(['user_id' => 7, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => 100]);
        $terms = SubscriptionTerms::create(['subscription_id' => $subscription->id, 'version_no' => 1,
            'effective_from' => 100, 'quota_bytes' => '123', 'reset_policy' => [], 'content_hash' => str_repeat('a', 64)]);
        $this->captureCatalog();
        $this->assertSame('123', $terms->fresh()->quota_bytes);
        $this->assertNull($terms->fresh()->plan_revision_id);
        $this->assertSame('2147483648', PlanRevision::query()->firstOrFail()->quota_bytes);
    }

    public function test_published_catalog_models_are_immutable(): void
    {
        $this->plan();
        $this->captureCatalog();
        foreach ([PlanRevision::query()->firstOrFail(), PlanOffer::query()->firstOrFail()] as $record) {
            $record->content_hash = str_repeat('b', 64);
            try {
                $record->save();
                $this->fail('A published snapshot cannot be edited.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('immutable', $error->getMessage());
            }
        }
    }

    public function test_offer_cannot_reference_a_revision_owned_by_another_plan(): void
    {
        $first = $this->plan();
        $second = $this->plan();
        $this->captureCatalog();
        $offer = PlanOffer::query()->where('plan_id', $first->id)->firstOrFail()->replicate();
        $offer->plan_id = $second->id;
        $offer->version_no = 3;
        $this->expectException(QueryException::class);
        $offer->save();
    }

    public function test_legacy_plan_deletion_keeps_catalog_evidence_and_is_not_blocked_by_new_foreign_keys(): void
    {
        $plan = $this->plan();
        $this->captureCatalog();
        $plan->delete();
        $this->assertSame(1, PlanRevision::query()->count());
        $retry = $this->captureCatalog(retry: $plan->id);
        $this->assertSame('CATALOG_LEGACY_PLAN_MISSING', $retry['results'][0]['error_code']);
    }

    public function test_transaction_failure_rolls_back_catalog_history_and_pointer_together(): void
    {
        $this->plan();
        try {
            DB::transaction(function () {
                $this->captureCatalog();
                throw new RuntimeException('injected failure');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('injected failure', $error->getMessage());
        }
        $this->assertSame(0, PlanRevision::query()->count());
        $this->assertSame(0, DB::table('tx_plan_catalog_shadow')->count());
        $this->assertSame(0, DB::table('tx_plan_catalog_checkpoint')->count());
    }

    public function test_schema_replay_preserves_history_and_recovers_a_missing_later_table(): void
    {
        $this->plan();
        $this->captureCatalog();
        $first = PlanRevision::query()->firstOrFail()->getAttributes();
        Schema::drop('tx_plan_catalog_exception');
        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame($first, PlanRevision::query()->firstOrFail()->getAttributes());
        $this->assertTrue(Schema::hasTable('tx_plan_catalog_exception'));
    }

    public function test_schema_replay_rejects_an_existing_table_with_wrong_columns(): void
    {
        Schema::drop('tx_plan_catalog_exception');
        Schema::create('tx_plan_catalog_exception', fn (Blueprint $table) => $table->integer('legacy_plan_id')->primary());
        $this->expectExceptionMessage('Shadow schema mismatch: tx_plan_catalog_exception.run_key');
        $this->migration()->up();
    }

    public function test_empty_catalog_expansion_can_be_rolled_back(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('tx_plan_revision'));
        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('tx_plan_revision'));
    }

    public function test_catalog_rollback_refuses_to_erase_business_history(): void
    {
        $this->plan();
        $this->captureCatalog();
        $this->expectExceptionMessage('Plan catalog shadow contains data');
        $this->migration()->down();
    }

    public function test_cli_requires_explicit_isolated_copy_and_returns_reviewable_json(): void
    {
        $this->plan();
        $this->assertSame(1, Artisan::call('core:catalog-shadow', ['--run' => 'catalog-cli']));
        $this->assertSame('ISOLATED_COPY_REQUIRED', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error_code']);
        $this->assertSame(0, Artisan::call('core:catalog-shadow', ['--isolated-copy' => true, '--run' => 'catalog-cli',
            '--billing-timezone' => 'UTC', '--currency' => 'USD', '--limit' => 1]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('complete', $result['phase']);
        $this->assertSame('0', $result['unresolved_count']);
    }

    public function test_reconciliation_is_read_only_and_checks_every_bounded_page(): void
    {
        $this->plan(); $this->plan(); $last = $this->plan();
        $this->captureCatalog();
        $before = $this->catalogState();
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $first = $this->reconcileCatalog(limit: 2);
            $second = $this->reconcileCatalog(limit: 2, after: (int) $first['next_after_id']);
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        $this->assertTrue($first['page_ok']); $this->assertTrue($first['has_more']);
        $this->assertTrue($second['page_ok']); $this->assertFalse($second['has_more']);
        $this->assertSame('2', $first['checked_count']); $this->assertSame('1', $second['checked_count']);
        $this->assertSame((string) $last->id, $second['next_after_id']);
        $this->assertSame([], array_intersect(array_column($first['results'], 'legacy_plan_id'), array_column($second['results'], 'legacy_plan_id')));
        foreach ($queries as $query) { $this->assertMatchesRegularExpression('/^select\b/i', $query['query']); }
        $this->assertSame($before, $this->catalogState());
    }

    public function test_reconciliation_distinguishes_source_price_and_availability_drift_without_repair(): void
    {
        $plan = $this->plan(); $this->captureCatalog();
        $this->prices($plan, '{"monthly":7.29}');
        $plan->update(['sell' => false, 'capacity_limit' => 6]);
        $before = $this->catalogState();
        $report = $this->reconcileCatalog();
        $this->assertFalse($report['page_ok']);
        $this->assertContains('CATALOG_SOURCE_CHANGED', $report['results'][0]['issues']);
        $this->assertContains('CATALOG_AVAILABILITY_CHANGED', $report['results'][0]['issues']);
        $this->assertSame($before, $this->catalogState());
        $this->assertSame('revised', $this->captureCatalog(retry: $plan->id)['results'][0]['status']);
        $this->assertTrue($this->reconcileCatalog()['page_ok']);
    }

    public function test_reconciliation_detects_deleted_source_and_missing_pointer_even_on_later_pages(): void
    {
        $deleted = $this->plan(); $unmapped = $this->plan(); $this->captureCatalog();
        DB::table('tx_plan')->where('id', $deleted->id)->delete();
        DB::table('tx_plan_catalog_shadow')->where('legacy_plan_id', $unmapped->id)->delete();
        $first = $this->reconcileCatalog(limit: 1);
        $second = $this->reconcileCatalog(limit: 1, after: (int) $first['next_after_id']);
        $this->assertContains('CATALOG_LEGACY_PLAN_MISSING', $first['results'][0]['issues']);
        $this->assertContains('CATALOG_POINTER_MISSING', $second['results'][0]['issues']);
        DB::table('tx_plan')->where('id', $unmapped->id)->delete();
        $this->assertSame((string) $unmapped->id, $this->reconcileCatalog(after: $deleted->id)['results'][0]['legacy_plan_id']);
    }

    public function test_reconciliation_checks_actual_immutable_columns_and_offer_hashes(): void
    {
        $this->plan(); $this->captureCatalog();
        DB::table('tx_plan_revision')->update(['quota_bytes' => '123']);
        DB::table('tx_plan_offer')->update(['price_minor' => '999']);
        DB::table('tx_plan_catalog_shadow')->update(['source_hash' => str_repeat('0', 64)]);
        $issues = $this->reconcileCatalog()['results'][0]['issues'];
        foreach (['CATALOG_REVISION_FIELDS_MISMATCH', 'CATALOG_OFFER_HASH_MISMATCH',
            'CATALOG_OFFER_FIELDS_MISMATCH', 'CATALOG_POINTER_HASH_MISMATCH'] as $code) { $this->assertContains($code, $issues); }
        $offer = PlanOffer::query()->firstOrFail();
        $actual = ['plan_id' => (string) $offer->plan_id, 'plan_revision_id' => (string) $offer->plan_revision_id,
            'offer_code' => $offer->offer_code, 'version_no' => $offer->version_no, 'currency' => $offer->currency,
            'currency_minor_exponent' => $offer->currency_minor_exponent, 'calendar_policy' => $offer->calendar_policy,
            'price_minor' => $offer->price_minor, 'calendar_months' => $offer->calendar_months,
            'duration_seconds' => $offer->duration_seconds, 'purchase_kind' => $offer->purchase_kind];
        DB::table('tx_plan_offer')->where('id', $offer->id)->update(['content_hash' => app(PlanCatalogShadowService::class)->hash($actual)]);
        $this->assertContains('CATALOG_OFFER_FIELDS_MISMATCH', $this->reconcileCatalog()['results'][0]['issues']);
    }

    public function test_reconciliation_detects_missing_quotes_availability_and_corrupt_snapshot(): void
    {
        $plan = $this->plan(); $this->prices($plan, '{"monthly":1,"yearly":2}'); $this->captureCatalog();
        $offers = PlanOffer::query()->orderBy('id')->get();
        DB::table('tx_plan_offer_availability')->where('offer_id', $offers[0]->id)->delete();
        DB::table('tx_plan_offer_availability')->where('offer_id', $offers[1]->id)->delete();
        DB::table('tx_plan_offer')->where('id', $offers[1]->id)->delete();
        $issues = $this->reconcileCatalog()['results'][0]['issues'];
        $this->assertContains('CATALOG_OFFERS_MISMATCH', $issues);
        $this->assertContains('CATALOG_AVAILABILITY_MISSING', $issues);
        DB::table('tx_plan_revision')->update(['source_snapshot' => '{"changed":true}']);
        $this->assertContains('CATALOG_REVISION_HASH_MISMATCH', $this->reconcileCatalog()['results'][0]['issues']);
    }

    public function test_reconciliation_reports_global_scan_exceptions_and_high_water_growth(): void
    {
        $bad = $this->plan(); $this->prices($bad, '{"monthly":0.001}');
        $this->plan(); $this->captureCatalog(limit: 1); $this->plan();
        $before = $this->catalogState();
        $report = $this->reconcileCatalog();
        foreach (['CATALOG_SCAN_INCOMPLETE', 'CATALOG_HIGH_WATER_GROWTH', 'CATALOG_UNRESOLVED_EXCEPTIONS'] as $code) {
            $this->assertContains($code, $report['global_issues']);
        }
        $this->assertContains('CATALOG_UNRESOLVED_EXCEPTION', $report['results'][0]['issues']);
        $this->assertSame('2', $report['checked_count']); $this->assertSame('1', $report['unresolved_count']);
        $this->assertFalse($report['page_ok']); $this->assertSame($before, $this->catalogState());
    }

    public function test_reconciliation_refuses_invalid_bounds_unknown_run_and_nonisolated_execution(): void
    {
        $this->plan(); $this->captureCatalog();
        foreach ([[0, 0], [1001, 0], [1, -1], [1, 2]] as [$limit, $after]) {
            try { $this->reconcileCatalog($limit, $after); $this->fail('Invalid bounds accepted.'); }
            catch (InvalidArgumentException $error) { $this->assertMatchesRegularExpression('/^(INVALID_CATALOG_BOUNDS|CATALOG_CURSOR_OUTSIDE_HIGH_WATER)$/', $error->getMessage()); }
        }
        try { app(PlanCatalogShadowReconciler::class)->reconcile('unknown'); $this->fail('Missing run accepted.'); }
        catch (RuntimeException $error) { $this->assertSame('CATALOG_RUN_NOT_FOUND', $error->getMessage()); }
        config(['core_shadow.isolated_copy' => false]);
        $this->expectExceptionMessage('ISOLATED_COPY_REQUIRED'); $this->reconcileCatalog();
    }

    public function test_reconciliation_cli_uses_saved_context_and_fails_on_drift(): void
    {
        $plan = $this->plan(); $this->captureCatalog();
        $options = ['--isolated-copy' => true, '--reconcile' => true, '--run' => 'catalog-test', '--limit' => '1'];
        $this->assertSame(0, Artisan::call('core:catalog-shadow', $options));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['read_only']); $this->assertSame('current-pointer-and-offers', $report['scope']);
        $this->prices($plan, '{"monthly":5}');
        $this->assertSame(1, Artisan::call('core:catalog-shadow', $options));
        $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['page_ok']);
        $this->assertSame(1, Artisan::call('core:catalog-shadow', $options + ['--currency' => 'CNY']));
        $this->assertSame('CATALOG_RECONCILE_OPTIONS_CONFLICT', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error_code']);
    }

    private function reconcileCatalog(int $limit = 100, int $after = 0): array
    {
        return app(PlanCatalogShadowReconciler::class)->reconcile('catalog-test', $limit, $after);
    }

    private function catalogState(): array
    {
        $state = [];
        foreach (['tx_plan', 'tx_plan_revision', 'tx_plan_offer', 'tx_plan_offer_availability', 'tx_plan_catalog_shadow',
            'tx_plan_catalog_checkpoint', 'tx_plan_catalog_exception'] as $table) {
            $state[$table] = DB::table($table)->orderBy(match ($table) {
                'tx_plan_offer_availability' => 'offer_id', 'tx_plan_catalog_shadow', 'tx_plan_catalog_exception' => 'legacy_plan_id', default => 'id',
            })->get()->toJson();
        }
        return $state;
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_10_12_000002_create_plan_catalog_shadow_tables.php');
    }

    public function test_waiting_reader_uses_committed_revision_and_offers_after_a_stale_mysql_snapshot(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent MariaDB sessions with REPEATABLE READ.');
        }
        $plan = $this->plan();
        DB::beginTransaction();
        try {
            $this->assertSame(0, PlanRevision::query()->count());
            $this->onFreshCatalogSession(fn () => $this->captureCatalog('peer-capture'));
            $result = $this->captureCatalog('stale-reader');
            $this->assertSame('unchanged', $result['results'][0]['status']);
            $this->assertSame('0', $result['unresolved_count']);
            $this->assertSame(1, DB::table('tx_plan_offer_availability')->sharedLock()->get()->count());
            $this->assertSame((string) $plan->id, $result['results'][0]['legacy_plan_id']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_revision_number_uses_current_read_after_another_session_appends_history(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent MariaDB sessions with REPEATABLE READ.');
        }
        $plan = $this->plan();
        $this->captureCatalog();
        DB::beginTransaction();
        try {
            $this->assertSame(1, PlanRevision::query()->count());
            $this->onFreshCatalogSession(function () use ($plan) {
                $this->prices($plan, '{"monthly":2}');
                $this->assertSame('revised', $this->captureCatalog('peer-revision')['results'][0]['status']);
            });
            $this->prices($plan, '{"monthly":3}');
            $result = $this->captureCatalog('stale-revision');
            $this->assertSame('revised', $result['results'][0]['status']);
            $this->assertSame('3', $result['results'][0]['revision_no']);
            $this->assertSame('0', $result['unresolved_count']);
            $this->assertSame(['1', '2', '3'], PlanRevision::query()->orderBy('revision_no')->sharedLock()->pluck('revision_no')->all());
        } finally {
            DB::rollBack();
        }
    }

    private function onFreshCatalogSession(callable $callback): mixed
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.plan_catalog_peer' => config('database.connections.'.$original)]);
        DB::purge('plan_catalog_peer');
        DB::setDefaultConnection('plan_catalog_peer');
        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('plan_catalog_peer');
        }
    }

    private function clearMysqlCatalogFixture(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach (['tx_plan_catalog_exception', 'tx_plan_catalog_checkpoint', 'tx_plan_catalog_shadow',
                'tx_plan_offer_availability', 'tx_plan_offer', 'tx_plan_revision', 'tx_plan'] as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function captureCatalog(string $key = 'catalog-test', int $limit = 100, ?int $retry = null): array
    {
        return app(PlanCatalogShadowService::class)->run($key, $limit, $this->context, $retry);
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::create($overrides + ['name' => 'Current catalog', 'group_id' => 1, 'transfer_enable' => 2,
            'speed_limit' => 3, 'device_limit' => 2, 'prices' => ['monthly' => 0.1],
            'show' => true, 'sell' => true, 'renew' => true, 'reset_traffic_method' => 0, 'capacity_limit' => null]);
    }

    private function prices(Plan $plan, string $json): void
    {
        DB::table('tx_plan')->where('id', $plan->id)->update(['prices' => $json]);
    }
}
