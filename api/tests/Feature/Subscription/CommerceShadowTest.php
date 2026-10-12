<?php

namespace Tests\Feature\Subscription;

use App\Models\CommerceShadowMoneyAccount;
use App\Models\CommerceShadowMoneyJournal;
use App\Models\CommerceShadowMoneyPosting;
use App\Models\CommerceShadowOrder;
use App\Models\CommerceShadowUserOpening;
use App\Services\Subscription\CommerceShadowCodec;
use App\Services\Subscription\CommerceShadowService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CommerceShadowTest extends TestCase
{
    private array $context = ['currency' => 'USD', 'snapshot_key' => 'quiesced-test-copy'];
    private const TABLES = ['tx_commerce_shadow_exception', 'tx_commerce_shadow_checkpoint', 'tx_commerce_shadow_order',
        'tx_commerce_shadow_user_opening', 'tx_commerce_shadow_money_posting', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_account', 'tx_wallet_recharge', 'tx_order', 'tx_user'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['core_shadow.isolated_copy' => true]);
        if (getenv('CORE_COMMERCE_TEST_MYSQL') === '1') {
            config(['database.default' => 'commerce_mysql_testing',
                'database.connections.commerce_mysql_testing' => config('database.connections.mysql')]);
            DB::purge('commerce_mysql_testing');
            if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') {
                throw new RuntimeException('COMMERCE_TEST_DEDICATED_DATABASE_REQUIRED');
            }
            $this->clearFixtures();
        } else {
            config(['database.default' => 'commerce_testing', 'database.connections.commerce_testing' =>
                array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]);
            DB::purge('commerce_testing');
        }
        Schema::create('tx_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('commission_balance')->default(0);
            $table->string('uuid')->nullable();
            $table->string('token')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        Schema::create('tx_order', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            foreach (['payment_id', 'coupon_id', 'invite_user_id'] as $field) {
                $table->unsignedBigInteger($field)->nullable();
            }
            $table->string('trade_no');
            $table->string('callback_no')->nullable();
            $table->string('period');
            $table->integer('status')->default(0);
            $table->integer('type')->default(1);
            $table->integer('commission_status')->nullable();
            foreach (['total_amount', 'handling_amount', 'discount_amount', 'surplus_amount', 'surplus_credit',
                'balance_amount', 'commission_balance', 'actual_commission_balance'] as $field) {
                $table->bigInteger($field)->nullable();
            }
            $table->text('surplus_order_ids')->nullable();
            $table->unsignedBigInteger('paid_at')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        Schema::create('tx_wallet_recharge', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('payment_id');
            $table->string('callback_no')->nullable();
            $table->integer('amount_minor');
            $table->integer('fee_minor');
            $table->string('request_key');
        });
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if (getenv('CORE_COMMERCE_TEST_MYSQL') === '1' && DB::getDriverName() === 'mysql'
            && DB::connection()->getDatabaseName() === 'txboard_concurrency_test') {
            $this->clearFixtures();
            DB::purge('commerce_mysql_testing');
        }
        DB::purge('commerce_testing');
        parent::tearDown();
    }

    public function test_wallet_and_commission_openings_are_separate_balanced_and_do_not_change_legacy(): void
    {
        $user = $this->user(['balance' => 1250, 'commission_balance' => 700, 'uuid' => 'raw-user-secret', 'token' => 'raw-link-secret']);
        $before = DB::table('tx_user')->first();
        $result = $this->capture();
        $this->assertSame('0', $result['unresolved_count']);
        $this->assertSame('isolated_shadow', $result['mode']);
        $this->assertSame('retained', $result['legacy_authority']);
        foreach (['tx_money_account', 'tx_money_journal', 'tx_money_posting', 'tx_order_item', 'tx_payment_attempt'] as $liveTable) {
            $this->assertFalse(Schema::hasTable($liveTable));
        }
        $this->assertEquals($before, DB::table('tx_user')->first());
        $this->assertSame(2, DB::table('tx_commerce_shadow_money_journal')->count());
        $this->assertSame(4, DB::table('tx_commerce_shadow_money_posting')->count());
        $opening = CommerceShadowUserOpening::query()->firstOrFail();
        $this->assertSame('1250', $opening->source_snapshot['wallet_minor']);
        $this->assertSame('700', $opening->source_snapshot['commission_minor']);
        $this->assertSame((string) $user, $opening->toArray()['legacy_user_id']);
        foreach (DB::table('tx_commerce_shadow_money_journal')->get() as $journal) {
            $lines = DB::table('tx_commerce_shadow_money_posting')->where('journal_id', $journal->id)->get();
            $this->assertSame('posted', $journal->status);
            $this->assertSame((string) $lines[0]->amount_minor, (string) $lines[1]->amount_minor);
            $this->assertSame(['debit', 'credit'], $lines->pluck('side')->all());
        }
        $this->assertSame(['wallet', 'migration_wallet_clearing', 'commission', 'migration_commission_clearing'],
            DB::table('tx_commerce_shadow_money_account')->orderBy('id')->pluck('purpose')->all());
        $this->assertStringNotContainsString('raw-user-secret', json_encode($opening->toArray()));
        $this->assertStringNotContainsString('raw-link-secret', json_encode($opening->toArray()));
        $this->assertSame('0', $this->service()->reconcile('commerce-test', 'user', '0', 100)['difference_count']);
    }

    public function test_zero_opening_has_no_fabricated_journals_or_zero_postings(): void
    {
        $this->user();
        $this->capture();
        $opening = DB::table('tx_commerce_shadow_user_opening')->first();
        $this->assertNull($opening->wallet_journal_id);
        $this->assertNull($opening->commission_journal_id);
        $this->assertSame(0, DB::table('tx_commerce_shadow_money_journal')->count());
        $this->assertSame(0, DB::table('tx_commerce_shadow_money_posting')->count());
        $this->assertSame('0', $this->service()->reconcile('commerce-test', 'user', '0', 100)['difference_count']);
    }

    public function test_pending_wallet_predebit_and_discounted_surplus_are_evidence_not_new_spending(): void
    {
        $user = $this->user(['balance' => 300]);
        $prior = $this->order($user, ['status' => 4, 'total_amount' => 1000, 'paid_at' => 1600000020]);
        $new = $this->order($user, ['type' => 3, 'total_amount' => 800, 'discount_amount' => 100, 'surplus_amount' => 500,
            'balance_amount' => 200, 'surplus_order_ids' => json_encode([$prior])]);
        $beforeOrders = DB::table('tx_order')->orderBy('id')->get();
        $this->capture();
        $shadow = CommerceShadowOrder::query()->where('legacy_order_id', $new)->firstOrFail();
        $this->assertSame('legacy_snapshot_unknown', $shadow->historical_terms);
        $this->assertSame('1500', $shadow->amount_evidence['reconstructed_item_net_minor']);
        $this->assertSame('1600', $shadow->amount_evidence['reconstructed_item_gross_minor']);
        $this->assertSame('pending_predebit', $shadow->amount_evidence['wallet_disposition']);
        $this->assertSame('500', $shadow->amount_evidence['surplus_applied_minor']);
        $this->assertSame([(string) $prior], $shadow->source_snapshot['surplus_order_ids']);
        $this->assertSame(1, DB::table('tx_commerce_shadow_money_journal')->count());
        $this->assertEquals($beforeOrders, DB::table('tx_order')->orderBy('id')->get());
        $this->assertSame('300', (string) DB::table('tx_user')->value('balance'));
        $this->assertSame('0', $this->service()->reconcile('commerce-test', 'order', '0', 100)['difference_count']);
    }

    public function test_free_completed_order_and_excess_surplus_do_not_manufacture_provider_collection_or_fulfillment(): void
    {
        $user = $this->user(['balance' => 250]);
        $prior = $this->order($user, ['status' => 4]);
        $free = $this->order($user, ['status' => 3, 'total_amount' => 0, 'balance_amount' => 0,
            'surplus_amount' => 1250, 'surplus_credit' => 250, 'surplus_order_ids' => json_encode([$prior]),
            'trade_no' => 'free-trade-raw', 'callback_no' => 'free-trade-raw', 'paid_at' => 1600000040]);
        $this->capture();
        $evidence = CommerceShadowOrder::query()->where('legacy_order_id', $free)->firstOrFail()->amount_evidence;
        $this->assertSame('none', $evidence['provider_collection_evidence']);
        $this->assertTrue($evidence['legacy_fulfillment_recorded']);
        $this->assertSame('1000', $evidence['surplus_applied_minor']);
        $this->assertSame('250', $evidence['surplus_credit_minor']);
        $this->assertSame('250', (string) DB::table('tx_user')->value('balance'));
        $this->assertFalse(Schema::hasTable('tx_payment_attempt'));
        $this->assertSame(1, DB::table('tx_commerce_shadow_money_journal')->count());
    }

    public function test_cancelled_wallet_predebit_is_not_released_again(): void
    {
        $user = $this->user(['balance' => 500]);
        $order = $this->order($user, ['status' => 2, 'balance_amount' => 200]);
        $this->capture();
        $shadow = CommerceShadowOrder::query()->where('legacy_order_id', $order)->firstOrFail();
        $this->assertSame('cancelled_legacy_release', $shadow->amount_evidence['wallet_disposition']);
        $this->assertSame('500', (string) DB::table('tx_user')->value('balance'));
        $this->assertSame(1, DB::table('tx_commerce_shadow_money_journal')->count());
    }

    public function test_replay_same_source_returns_same_history_across_runs_and_independent_retries(): void
    {
        $user = $this->user(['balance' => 200, 'commission_balance' => 30]);
        $order = $this->order($user);
        $this->capture();
        $before = DB::table('tx_commerce_shadow_user_opening')->first();
        $this->assertSame([], $this->capture()['results']);
        $this->assertSame('unchanged', $this->capture(retryType: 'user', retryId: (string) $user)['results'][0]['status']);
        $this->assertSame('unchanged', $this->capture(retryType: 'order', retryId: (string) $order)['results'][0]['status']);
        $this->capture('another-run');
        $this->assertEquals($before, DB::table('tx_commerce_shadow_user_opening')->first());
        $this->assertSame(1, DB::table('tx_commerce_shadow_order')->count());
        $this->assertSame(2, DB::table('tx_commerce_shadow_money_journal')->count());
        $this->assertSame('commerce-test', DB::table('tx_commerce_shadow_order')->value('run_key'));
    }

    public function test_bounded_user_then_order_cursor_resumes_below_both_high_waters(): void
    {
        $firstUser = $this->user();
        $secondUser = $this->user();
        $firstOrder = $this->order($firstUser);
        $secondOrder = $this->order($secondUser);
        $first = $this->capture(limit: 1);
        $lateUser = $this->user();
        $lateOrder = $this->order($lateUser);
        $this->assertSame('processing', $first['phase']);
        $this->assertSame((string) $firstUser, $first['cursor_user_id']);
        $this->assertSame('0', $first['cursor_order_id']);
        $this->capture(limit: 1);
        $third = $this->capture(limit: 1);
        $this->assertSame((string) $firstOrder, $third['cursor_order_id']);
        $last = $this->capture(limit: 1);
        $this->assertSame('complete', $last['phase']);
        $this->assertSame('4', $last['processed_count']);
        $this->assertSame((string) $secondUser, $last['high_water_user_id']);
        $this->assertSame((string) $secondOrder, $last['high_water_order_id']);
        $this->assertFalse(DB::table('tx_commerce_shadow_user_opening')->where('legacy_user_id', $lateUser)->exists());
        $this->assertFalse(DB::table('tx_commerce_shadow_order')->where('legacy_order_id', $lateOrder)->exists());
    }

    public function test_negative_balance_rolls_back_aggregate_and_retries_after_source_repair(): void
    {
        $user = $this->user(['balance' => -1]);
        $failed = $this->capture();
        $this->assertSame('COMMERCE_INTEGER_INVALID', $failed['results'][0]['error_code']);
        $this->assertSame(0, DB::table('tx_commerce_shadow_money_account')->count());
        $this->assertSame(0, DB::table('tx_commerce_shadow_user_opening')->count());
        DB::table('tx_user')->where('id', $user)->update(['balance' => 20]);
        $fixed = $this->capture(retryType: 'user', retryId: (string) $user);
        $this->assertSame('mapped', $fixed['results'][0]['status']);
        $exception = DB::table('tx_commerce_shadow_exception')->first();
        $this->assertSame('resolved', $exception->state);
        $this->assertSame('1', (string) $exception->attempts);
        $this->assertSame('0', $fixed['unresolved_count']);
    }

    public function test_minor_unit_codec_rejects_floats_signs_noncanonical_values_and_overflow(): void
    {
        foreach ([1.0, -1, '-1', '+1', '01', '1.00', '1e2', true, null, '9223372036854775808', INF] as $invalid) {
            try {
                CommerceShadowCodec::integer($invalid);
                $this->fail('Invalid input accepted');
            } catch (RuntimeException $error) {
                $this->assertSame('COMMERCE_INTEGER_INVALID', $error->getMessage());
            }
        }
        $this->assertSame('9223372036854775807', CommerceShadowCodec::integer('9223372036854775807'));
        $this->expectExceptionMessage('COMMERCE_AMOUNT_OVERFLOW');
        CommerceShadowCodec::add('9223372036854775807', '1');
    }

    public function test_large_ids_and_minor_amounts_are_decimal_strings_without_float_conversion(): void
    {
        $user = $this->user(['id' => '9007199254740993', 'balance' => '9223372036854775807']);
        $this->order($user, ['id' => '9007199254740995', 'total_amount' => '9007199254740993']);
        $result = $this->capture();
        $this->assertSame('0', $result['unresolved_count']);
        $opening = CommerceShadowUserOpening::query()->firstOrFail()->toArray();
        $this->assertSame('9007199254740993', $opening['legacy_user_id']);
        $this->assertSame('9223372036854775807', $opening['source_snapshot']['wallet_minor']);
        $order = CommerceShadowOrder::query()->firstOrFail()->toArray();
        $this->assertSame('9007199254740995', $order['legacy_order_id']);
        $this->assertSame('9007199254740993', $order['amount_evidence']['provider_due_minor']);
        $this->assertSame('9223372036854775807', CommerceShadowMoneyPosting::query()->firstOrFail()->toArray()['amount_minor']);
    }

    public function test_source_changes_are_readonly_differences_and_never_overwrite_or_reopen(): void
    {
        $user = $this->user(['balance' => 100]);
        $order = $this->order($user);
        $this->capture();
        $oldUser = DB::table('tx_commerce_shadow_user_opening')->first();
        $oldOrder = DB::table('tx_commerce_shadow_order')->first();
        DB::table('tx_order')->where('id', $order)->update(['status' => 3, 'total_amount' => 900]);
        $retry = $this->capture(retryType: 'order', retryId: (string) $order);
        $this->assertSame('COMMERCE_ORDER_SOURCE_CHANGED', $retry['results'][0]['error_code']);
        DB::table('tx_user')->where('id', $user)->update(['balance' => 101]);
        $this->assertSame('COMMERCE_USER_SOURCE_CHANGED', $this->capture(retryType: 'user', retryId: (string) $user)['results'][0]['error_code']);
        $checkpoint = DB::table('tx_commerce_shadow_checkpoint')->first();
        $exceptions = DB::table('tx_commerce_shadow_exception')->orderBy('id')->get();
        $userReport = $this->service()->reconcile('commerce-test', 'user', '0', 1);
        $orderReport = $this->service()->reconcile('commerce-test', 'order', '0', 1);
        $this->assertContains('source.wallet_minor', array_column($userReport['results'][0]['differences'], 'field'));
        $balanceDifference = array_column($userReport['results'][0]['differences'], null, 'field')['source.wallet_minor'];
        $this->assertSame('100', $balanceDifference['expected']);
        $this->assertSame('101', $balanceDifference['actual']);
        $this->assertContains('source.total_amount', array_column($orderReport['results'][0]['differences'], 'field'));
        $this->assertContains('source.status', array_column($orderReport['results'][0]['differences'], 'field'));
        $this->assertEquals($checkpoint, DB::table('tx_commerce_shadow_checkpoint')->first());
        $this->assertEquals($exceptions, DB::table('tx_commerce_shadow_exception')->orderBy('id')->get());
        $this->assertEquals($oldUser, DB::table('tx_commerce_shadow_user_opening')->first());
        $this->assertEquals($oldOrder, DB::table('tx_commerce_shadow_order')->first());
        $this->assertSame(1, DB::table('tx_commerce_shadow_money_journal')->count());
    }

    public function test_order_and_recharge_duplicate_callbacks_block_capture_without_persisting_raw_secrets(): void
    {
        $user = $this->user();
        $order = $this->order($user, ['payment_id' => 10, 'callback_no' => 'provider-secret-reference', 'status' => 3]);
        DB::table('tx_wallet_recharge')->insert(['user_id' => $user, 'payment_id' => 10, 'callback_no' => 'provider-secret-reference',
            'amount_minor' => 1000, 'fee_minor' => 10, 'request_key' => 'recharge-raw-secret']);
        $result = $this->capture();
        $this->assertSame('COMMERCE_CALLBACK_DUPLICATE', $result['results'][1]['error_code']);
        $this->assertSame(0, DB::table('tx_commerce_shadow_order')->count());
        $this->assertStringNotContainsString('provider-secret-reference', json_encode($result));
        $this->assertStringNotContainsString('recharge-raw-secret', json_encode($result));
        DB::table('tx_wallet_recharge')->delete();
        $this->assertSame('mapped', $this->capture(retryType: 'order', retryId: (string) $order)['results'][0]['status']);
        $shadow = CommerceShadowOrder::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $shadow->source_snapshot['callback_digest']);
        $this->assertSame('legacy_marker_unverified', $shadow->amount_evidence['provider_collection_evidence']);
        $this->assertStringNotContainsString('provider-secret-reference', json_encode($shadow->toArray()));
        $other = $this->order($user, ['payment_id' => 10, 'callback_no' => 'provider-secret-reference', 'status' => 3]);
        $this->capture('duplicate-order-run');
        $this->assertSame('COMMERCE_CALLBACK_DUPLICATE', $this->capture('duplicate-order-run', retryType: 'order', retryId: (string) $other)['results'][0]['error_code']);
    }

    public function test_surplus_owner_mismatch_and_inconsistent_amounts_are_retryable_exceptions(): void
    {
        $first = $this->user();
        $second = $this->user();
        $prior = $this->order($second, ['status' => 4]);
        $cross = $this->order($first, ['surplus_amount' => 100, 'surplus_order_ids' => json_encode([$prior])]);
        $bad = $this->order($first, ['surplus_amount' => 50, 'surplus_credit' => 100, 'surplus_order_ids' => json_encode([$cross])]);
        $this->capture();
        $this->assertSame('COMMERCE_SURPLUS_OWNER_MISMATCH', DB::table('tx_commerce_shadow_exception')->where('legacy_id', $cross)->value('error_code'));
        $this->assertSame('COMMERCE_SURPLUS_INCONSISTENT', DB::table('tx_commerce_shadow_exception')->where('legacy_id', $bad)->value('error_code'));
        $this->assertSame(1, DB::table('tx_commerce_shadow_order')->count());
    }

    public function test_negative_order_unknown_callback_scope_free_fee_and_amount_overflow_are_blocking(): void
    {
        $user = $this->user();
        $cases = [
            [$this->order($user, ['total_amount' => -10]), 'COMMERCE_INTEGER_INVALID'],
            [$this->order($user, ['callback_no' => 'opaque-reference-without-provider']), 'COMMERCE_CALLBACK_SCOPE_UNKNOWN'],
            [$this->order($user, ['total_amount' => 0, 'handling_amount' => 1]), 'COMMERCE_FREE_ORDER_FEE_UNEXPLAINED'],
            [$this->order($user, ['total_amount' => '9223372036854775807', 'balance_amount' => 1]), 'COMMERCE_AMOUNT_OVERFLOW'],
        ];
        $this->capture();
        foreach ($cases as [$order, $code]) {
            $this->assertSame($code, DB::table('tx_commerce_shadow_exception')->where('aggregate_type', 'order')->where('legacy_id', $order)->value('error_code'));
        }
        $this->assertSame(0, DB::table('tx_commerce_shadow_order')->count());
        $this->assertSame(0, DB::table('tx_commerce_shadow_money_journal')->count());
        $this->assertSame('-10', (string) DB::table('tx_order')->where('id', $cases[0][0])->value('total_amount'));
    }

    public function test_sql_order_evidence_tampering_is_detected_without_leaking_unexpected_secret_fields(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        $this->capture();
        $evidence = CommerceShadowOrder::query()->firstOrFail()->amount_evidence;
        $evidence['provider_due_minor'] = 'raw-secret-in-amount-field';
        $evidence['unexpected-secret-key'] = 'raw-secret-in-extra-field';
        DB::table('tx_commerce_shadow_order')->update(['amount_evidence' => json_encode($evidence)]);
        $report = $this->service()->reconcile('commerce-test', 'order', '0', 100);
        $this->assertSame('1', $report['difference_count']);
        $json = json_encode($report);
        $this->assertStringNotContainsString('raw-secret', $json);
        $this->assertStringNotContainsString('unexpected-secret-key', $json);
        $this->assertContains('amount_evidence.unexpected_field_count', array_column($report['results'][0]['differences'], 'field'));
        $this->assertSame('COMMERCE_SHADOW_DAMAGED', $this->capture(retryType: 'order', retryId: (string) $order)['results'][0]['error_code']);
        $this->assertSame(1, DB::table('tx_commerce_shadow_order')->count());
    }

    public function test_ledger_sql_tampering_is_reported_and_retry_does_not_hide_or_repair_it(): void
    {
        $user = $this->user(['balance' => 100]);
        $this->capture();
        $posting = DB::table('tx_commerce_shadow_money_posting')->where('side', 'credit')->first();
        DB::table('tx_commerce_shadow_money_posting')->where('id', $posting->id)->update(['amount_minor' => 99]);
        $report = $this->service()->reconcile('commerce-test', 'user', '0', 100);
        $this->assertSame('1', $report['difference_count']);
        $this->assertContains('COMMERCE_JOURNAL_UNBALANCED', array_column($report['results'][0]['differences'], 'error_code'));
        $this->assertSame('COMMERCE_LEDGER_DAMAGED', $this->capture(retryType: 'user', retryId: (string) $user)['results'][0]['error_code']);
        $this->assertSame('99', (string) DB::table('tx_commerce_shadow_money_posting')->where('id', $posting->id)->value('amount_minor'));
    }

    public function test_failure_after_journal_creation_rolls_back_accounts_journals_and_opening_together(): void
    {
        $this->user(['balance' => 500, 'commission_balance' => 100]);
        $mysql = DB::getDriverName() === 'mysql';
        DB::unprepared($mysql
            ? "CREATE TRIGGER commerce_fail_posting BEFORE INSERT ON tx_commerce_shadow_money_posting FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'"
            : "CREATE TRIGGER commerce_fail_posting BEFORE INSERT ON tx_commerce_shadow_money_posting BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $result = $this->capture();
        $this->assertSame('exception', $result['results'][0]['status']);
        foreach (['tx_commerce_shadow_money_account', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_posting', 'tx_commerce_shadow_user_opening'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame(1, DB::table('tx_commerce_shadow_exception')->count());
        DB::unprepared('DROP TRIGGER commerce_fail_posting');
    }

    public function test_in_transaction_user_and_order_source_changes_abort_without_partial_evidence(): void
    {
        $user = $this->user(['balance' => 500]);
        $order = $this->order($user);
        $mysql = DB::getDriverName() === 'mysql';
        DB::unprepared($mysql
            ? 'CREATE TRIGGER commerce_change_user AFTER INSERT ON tx_commerce_shadow_user_opening FOR EACH ROW UPDATE tx_user SET balance = balance + 1 WHERE id = NEW.legacy_user_id'
            : 'CREATE TRIGGER commerce_change_user AFTER INSERT ON tx_commerce_shadow_user_opening BEGIN UPDATE tx_user SET balance = balance + 1 WHERE id = NEW.legacy_user_id; END');
        $this->assertSame('COMMERCE_USER_CHANGED_DURING_CAPTURE', $this->capture()['results'][0]['error_code']);
        $this->assertSame('500', (string) DB::table('tx_user')->value('balance'));
        $this->assertSame(0, DB::table('tx_commerce_shadow_money_journal')->count());
        DB::unprepared('DROP TRIGGER commerce_change_user');
        $this->capture(retryType: 'user', retryId: (string) $user);
        DB::unprepared($mysql
            ? 'CREATE TRIGGER commerce_change_order AFTER INSERT ON tx_commerce_shadow_order FOR EACH ROW UPDATE tx_order SET total_amount = total_amount + 1 WHERE id = NEW.legacy_order_id'
            : 'CREATE TRIGGER commerce_change_order AFTER INSERT ON tx_commerce_shadow_order BEGIN UPDATE tx_order SET total_amount = total_amount + 1 WHERE id = NEW.legacy_order_id; END');
        $retry = $this->capture(retryType: 'order', retryId: (string) $order);
        $this->assertSame('COMMERCE_SOURCE_CHANGED_DURING_CAPTURE', $retry['results'][0]['error_code']);
        $this->assertSame('1000', (string) DB::table('tx_order')->value('total_amount'));
        $this->assertSame(0, DB::table('tx_commerce_shadow_order')->count());
        DB::unprepared('DROP TRIGGER commerce_change_order');
    }

    public function test_composite_currency_foreign_keys_and_immutable_models_protect_history(): void
    {
        $user = $this->user(['balance' => 100]);
        $this->order($user);
        $this->capture();
        foreach ([CommerceShadowMoneyAccount::class, CommerceShadowMoneyJournal::class, CommerceShadowMoneyPosting::class,
            CommerceShadowUserOpening::class, CommerceShadowOrder::class] as $modelClass) {
            $model = $modelClass::query()->firstOrFail();
            try {
                $model->content_hash = str_repeat('f', 64);
                $model->save();
                $this->fail('Published history updated');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
            try {
                $model->delete();
                $this->fail('Published history deleted');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $posting = (array) DB::table('tx_commerce_shadow_money_posting')->first();
        unset($posting['id']);
        $posting['currency'] = 'CNY';
        $posting['line_no'] = 3;
        $this->expectException(QueryException::class);
        DB::table('tx_commerce_shadow_money_posting')->insert($posting);
    }

    public function test_legacy_deletions_keep_audit_evidence_and_readonly_report_marks_missing_sources(): void
    {
        $user = $this->user(['balance' => 50]);
        $this->order($user);
        $this->capture();
        DB::table('tx_order')->delete();
        DB::table('tx_user')->delete();
        $this->assertSame(1, DB::table('tx_commerce_shadow_user_opening')->count());
        $this->assertSame(1, DB::table('tx_commerce_shadow_order')->count());
        foreach (['user', 'order'] as $type) {
            $report = $this->service()->reconcile('commerce-test', $type, '0', 100);
            $this->assertSame('COMMERCE_SOURCE_MISSING', $report['results'][0]['differences'][0]['error_code']);
        }
    }

    public function test_currency_snapshot_context_drift_and_unknown_currency_are_rejected(): void
    {
        $this->user(['balance' => 10]);
        $this->capture();
        foreach ([['currency' => '', 'snapshot_key' => 'x'], ['currency' => 'JPY', 'snapshot_key' => 'x'],
            ['currency' => 'USD', 'snapshot_key' => '']] as $badContext) {
            try {
                $this->service()->run('bad-context', 100, $badContext);
                $this->fail('Invalid context accepted');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $otherCurrency = $this->service()->run('other-currency', 100, ['currency' => 'CNY', 'snapshot_key' => 'copy']);
        $this->assertSame('COMMERCE_USER_CURRENCY_CHANGED', $otherCurrency['results'][0]['error_code']);
        $this->assertSame(1, DB::table('tx_commerce_shadow_money_journal')->count());
        $this->expectExceptionMessage('COMMERCE_CONTEXT_CHANGED');
        $this->service()->run('commerce-test', 100, ['currency' => 'USD', 'snapshot_key' => 'different-copy']);
    }

    public function test_schema_replay_preserves_history_recovers_later_tables_and_rejects_wrong_shape(): void
    {
        $this->user(['balance' => 10]);
        $this->capture();
        $before = DB::table('tx_commerce_shadow_money_posting')->get();
        Schema::drop('tx_commerce_shadow_exception');
        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('tx_commerce_shadow_exception'));
        $this->assertEquals($before, DB::table('tx_commerce_shadow_money_posting')->get());
        Schema::table('tx_commerce_shadow_exception', fn (Blueprint $table) => $table->dropColumn('error_code'));
        $this->expectExceptionMessage('Shadow schema mismatch: tx_commerce_shadow_exception.error_code');
        $this->migration()->up();
    }

    public function test_destructive_rollback_is_refused_after_any_shadow_data(): void
    {
        $this->user();
        $this->capture();
        try {
            $this->migration()->down();
            $this->fail('Rollback erased data');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('contains data', $error->getMessage());
        }
        $this->assertSame(1, DB::table('tx_commerce_shadow_user_opening')->count());
    }

    public function test_empty_schema_rollback_is_allowed(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('tx_commerce_shadow_money_account'));
        $this->assertFalse(Schema::hasTable('tx_commerce_shadow_checkpoint'));
        $this->assertTrue(Schema::hasTable('tx_user'));
    }

    public function test_cli_requires_explicit_isolated_copy_and_returns_json_for_capture_and_readonly_reconcile(): void
    {
        $this->assertSame(1, Artisan::call('core:commerce-shadow', ['--run' => 'cli']));
        $this->assertSame('ISOLATED_COPY_REQUIRED', json_decode(Artisan::output(), true)['error_code']);
        $user = $this->user(['balance' => 30]);
        $options = ['--isolated-copy' => true, '--run' => 'cli', '--snapshot' => 'copy', '--currency' => 'USD'];
        $this->assertSame(0, Artisan::call('core:commerce-shadow', $options));
        $this->assertSame('complete', json_decode(Artisan::output(), true)['phase']);
        $this->assertSame(0, Artisan::call('core:commerce-shadow', $options + ['--reconcile' => 'user']));
        $this->assertSame('0', json_decode(Artisan::output(), true)['difference_count']);
        DB::table('tx_user')->where('id', $user)->update(['balance' => 31]);
        $this->assertSame(1, Artisan::call('core:commerce-shadow', $options + ['--reconcile' => 'user']));
        $this->assertSame('1', json_decode(Artisan::output(), true)['difference_count']);
        config(['core_shadow.isolated_copy' => false]);
        $this->assertSame(1, Artisan::call('core:commerce-shadow', $options));
        $this->assertSame('ISOLATED_COPY_REQUIRED', json_decode(Artisan::output(), true)['error_code']);
    }

    public function test_real_mysql_sessions_replay_opening_once_with_database_unique_keys_and_user_locks(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires dedicated MariaDB and independent pcntl sessions.');
        }
        $user = $this->user(['balance' => 300, 'commission_balance' => 90]);
        $this->order($user);
        $connection = DB::getDefaultConnection();
        $directory = sys_get_temp_dir().'/txboard-commerce-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        try {
            DB::disconnect($connection);
            for ($worker = 0; $worker < 8; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Commerce worker fork failed');
                }
                if ($pid === 0) {
                    $exitCode = 2;
                    try {
                        DB::purge($connection);
                        // Every independent session freezes a read view before any opening exists.
                        // The barrier makes the current-read regression deterministic rather than timing dependent.
                        DB::beginTransaction();
                        DB::table('tx_commerce_shadow_money_journal')->count();
                        touch($directory.'/ready-'.$worker);
                        $deadline = microtime(true) + 20;
                        while (!is_file($directory.'/go') && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (!is_file($directory.'/go')) {
                            throw new RuntimeException('Commerce race barrier timed out');
                        }
                        $result = $this->capture('race-'.$worker);
                        DB::commit();
                        $exitCode = $result['unresolved_count'] === '0' ? 0 : 2;
                    } catch (\Throwable $error) {
                        $result = ['error' => get_class($error)];
                        if (DB::transactionLevel() > 0) {
                            DB::rollBack();
                        }
                    }
                    file_put_contents($directory.'/worker-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                    exit($exitCode);
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 20;
            while (count(glob($directory.'/ready-*') ?: []) !== 8 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(8, glob($directory.'/ready-*') ?: [], 'Every worker must freeze its snapshot before the barrier opens.');
            touch($directory.'/go');
            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wexitstatus($status);
            }
            $children = [];
            DB::purge($connection);
            $mapped = 0;
            $summaries = [];
            for ($worker = 0; $worker < 8; $worker++) {
                $result = json_decode(file_get_contents($directory.'/worker-'.$worker), true, flags: JSON_THROW_ON_ERROR);
                $summaries[] = $result;
                $this->assertSame('0', $result['unresolved_count']);
                $mapped += count(array_filter($result['results'], fn ($row) => $row['aggregate_type'] === 'user' && $row['status'] === 'mapped'));
            }
            $this->assertSame(array_fill(0, 8, 0), $exitCodes, json_encode($summaries));
            $this->assertSame(1, $mapped);
            $this->assertSame(1, DB::table('tx_commerce_shadow_user_opening')->count());
            $this->assertSame(1, DB::table('tx_commerce_shadow_order')->count());
            $this->assertSame(2, DB::table('tx_commerce_shadow_money_journal')->count());
            $this->assertSame(4, DB::table('tx_commerce_shadow_money_posting')->count());
            $this->assertSame('300', (string) DB::table('tx_user')->value('balance'));
        } finally {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::purge($connection);
        }
    }

    private function capture(string $run = 'commerce-test', int $limit = 100, ?string $retryType = null, ?string $retryId = null): array
    {
        return $this->service()->run($run, $limit, $this->context, $retryType, $retryId);
    }

    private function service(): CommerceShadowService
    {
        return app(CommerceShadowService::class);
    }

    private function user(array $attributes = []): int|string
    {
        return DB::table('tx_user')->insertGetId($attributes + ['balance' => 0, 'commission_balance' => 0,
            'created_at' => 1600000000, 'updated_at' => 1600000010]);
    }

    private function order(int|string $user, array $attributes = []): int|string
    {
        return DB::table('tx_order')->insertGetId($attributes + ['user_id' => $user, 'plan_id' => 1, 'period' => 'monthly',
            'trade_no' => 'test-order-'.bin2hex(random_bytes(8)), 'total_amount' => 1000,
            'created_at' => 1600000000, 'updated_at' => 1600000010]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_12_000004_create_commerce_shadow_tables.php');
    }

    private function clearFixtures(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') {
            throw new RuntimeException('COMMERCE_TEST_DEDICATED_DATABASE_REQUIRED');
        }
        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::TABLES as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
