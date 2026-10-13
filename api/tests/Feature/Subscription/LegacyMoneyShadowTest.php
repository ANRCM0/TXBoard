<?php

namespace Tests\Feature\Subscription;

use App\Models\User;
use App\Services\Subscription\CommerceShadowCodec;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyMoneyShadow;
use App\Services\Subscription\LegacyUserMutationCapture;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyMoneyShadowTest extends TestCase
{
    private int $counter = 0;
    protected function setUp(): void
    {
        parent::setUp(); $name = $this->connection();
        config(['database.default' => $name, 'database.connections.'.$name => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::purge($name); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true])); Bus::fake();
        config(['core_shadow.isolated_copy' => true, 'legacy_money_shadow.enabled' => true,
            'legacy_capture_shadow.enabled' => true, 'legacy_capture_shadow.run_key' => 'money-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD']);
    }
    protected function tearDown(): void
    { if ($this->mysql()) $this->clearMysql(); DB::purge($this->connection()); parent::tearDown(); }

    public function test_native_admin_edits_project_exact_endpoints_with_unclassified_balanced_lines(): void
    {
        $user = $this->user(); $admin = $this->user(['is_admin' => true]); Sanctum::actingAs($admin);
        admin_setting(['secure_path' => 'money_fixture']);
        $this->postJson('/txapi/admin/money_fixture/users/'.$user->id.'/update',
            ['balance' => '0.25', 'expected_balance_minor' => 100, 'commission_balance' => '0.75', 'expected_commission_balance_minor' => 50])->assertOk();
        $native = $this->nativeState();
        $result = $this->money()->project($user->id);
        $this->assertSame('25', $result['wallet_minor']); $this->assertSame('75', $result['commission_minor']);
        $this->assertTrue($result['complete']); $this->assertFalse($result['migration_gate_open']);
        $lines = DB::table('tx_legacy_money_shadow_posting')->orderBy('line_no')->get();
        $this->assertSame(['user_wallet', 'capture_wallet_clearing', 'user_commission', 'capture_commission_clearing'], $lines->pluck('purpose')->all());
        $this->assertSame(['debit', 'credit', 'credit', 'debit'], $lines->pluck('side')->all());
        $this->assertSame(['75', '75', '25', '25'], $lines->map(fn ($p) => (string) $p->amount_minor)->all());
        $this->assertSame($native, $this->nativeState()); $this->assertTrue($this->money()->verify($user->id)['page_ok']);
        $this->assertFalse(app(LegacyUserMutationCapture::class)->verify($user->id)['projection_applied']);
    }

    public function test_page_resume_noop_and_explicit_extension_preserve_frozen_high_water(): void
    {
        $user = $this->user(); $this->capture($user, ['balance' => 110]); $this->capture($user, ['balance' => 80]);
        $first = $this->money()->project($user->id, 1); $this->assertSame('2', $first['high_water']); $this->assertSame('1', $first['cursor']);
        $this->capture($user, ['commission_balance' => 0]);
        $this->failure('MONEY_SHADOW_EXTENSION_NOT_READY', fn () => $this->money()->project($user->id, 1, true));
        $second = $this->money()->project($user->id, 1); $this->assertTrue($second['complete']); $this->assertTrue($second['needs_extension']);
        $before = $this->shadowState(); $this->assertSame(0, $this->money()->project($user->id)['processed']);
        $this->assertSame($before, $this->shadowState());
        $third = $this->money()->project($user->id, 1, true); $this->assertSame('3', $third['high_water']);
        $this->assertSame('0', $third['commission_minor']); $this->assertSame(3, DB::table('tx_legacy_money_shadow_step')->count());
        $this->assertSame(6, DB::table('tx_legacy_money_shadow_posting')->count());
        $this->assertTrue($this->money()->verify($user->id)['page_ok']);
    }

    public function test_nonmoney_events_advance_cursor_without_invented_zero_journal(): void
    {
        $user = $this->user(['balance' => 0, 'commission_balance' => 0]); $this->capture($user, ['u' => 9007199254740993]);
        $result = $this->money()->project($user->id); $this->assertSame('0', $result['wallet_minor']);
        $this->assertSame(1, DB::table('tx_legacy_money_shadow_step')->count());
        $this->assertSame(0, DB::table('tx_legacy_money_shadow_posting')->count());
        $cp = DB::table('tx_legacy_money_shadow_checkpoint')->first(); $this->assertSame('0', (string) $cp->anchor_wallet_minor);
        $this->assertTrue($this->money()->verify($user->id)['page_ok']);
    }

    public function test_repeated_native_work_has_separate_deltas_and_int_boundary_stays_exact(): void
    {
        $user = $this->user(['balance' => 2147483647]); $this->capture($user, ['balance' => 0]);
        $this->capture($user, ['balance' => 10]); $this->capture($user, ['balance' => 20]);
        $this->money()->project($user->id);
        $this->assertSame('2147483647', (string) DB::table('tx_legacy_money_shadow_posting')->orderBy('id')->first()->amount_minor);
        $this->assertSame(3, DB::table('tx_legacy_money_shadow_step')->count());
        $this->assertSame('20', $this->money()->verify($user->id)['wallet_minor']);
    }

    public function test_select_only_reconciliation_pages_and_cursor_bounds(): void
    {
        $user = $this->user(); for ($i = 1; $i <= 5; $i++) $this->capture($user, ['balance' => 100 + $i]);
        $this->money()->project($user->id, 3); $before = $this->allState(); DB::enableQueryLog(); DB::flushQueryLog();
        $one = $this->money()->verify($user->id, 0, 2); $two = $this->money()->verify($user->id, 2, 2);
        $empty = $this->money()->verify($user->id, 3, 2);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        foreach ($queries as $q) $this->assertMatchesRegularExpression('/^select\b/i', $q['query']);
        $this->assertSame('2', $one['next_cursor']); $this->assertTrue($one['has_more']);
        $this->assertSame('3', $two['next_cursor']); $this->assertFalse($two['has_more']); $this->assertSame(0, $empty['verified']);
        $this->assertSame($before, $this->allState());
        $this->failure('MONEY_SHADOW_CURSOR_AHEAD', fn () => $this->money()->verify($user->id, 4));
    }

    public function test_strict_gate_outer_transaction_invalid_pages_and_missing_stream_are_write_free(): void
    {
        $user = $this->user();
        foreach ([false, 'true', 1, null] as $bad) {
            config(['legacy_money_shadow.enabled' => $bad]);
            $this->failure('MONEY_SHADOW_ISOLATED_REQUIRED', fn () => $this->money()->project($user->id));
        }
        config(['legacy_money_shadow.enabled' => true]); app()->instance('env', 'production');
        $this->failure('MONEY_SHADOW_ISOLATED_REQUIRED', fn () => $this->money()->project($user->id)); app()->instance('env', 'testing');
        config(['core_shadow.isolated_copy' => false]);
        $this->failure('MONEY_SHADOW_ISOLATED_REQUIRED', fn () => $this->money()->project($user->id)); config(['core_shadow.isolated_copy' => true]);
        foreach ([0, 101] as $limit) $this->failure('MONEY_SHADOW_PAGE_INVALID', fn () => $this->money()->project($user->id, $limit));
        DB::beginTransaction(); try { $this->failure('MONEY_SHADOW_OUTER_TRANSACTION_REFUSED', fn () => $this->money()->project($user->id)); } finally { DB::rollBack(); }
        $this->failure('CAPTURE_STREAM_MISSING', fn () => $this->money()->project($user->id));
        $this->assertSame(0, DB::table('tx_legacy_money_shadow_checkpoint')->count());
    }

    public function test_source_gap_and_context_drift_refuse_resume_without_mutation(): void
    {
        $user = $this->user(); $this->capture($user, ['balance' => 110]); $this->capture($user, ['balance' => 120]);
        $this->money()->project($user->id, 1); $before = $this->shadowState();
        DB::table('tx_user')->where('id', $user->id)->update(['u' => 9]);
        $this->failure('CAPTURE_SOURCE_GAP', fn () => $this->money()->project($user->id));
        $this->failure('CAPTURE_SOURCE_GAP', fn () => $this->money()->verify($user->id));
        $this->assertSame($before, $this->shadowState());
        DB::table('tx_user')->where('id', $user->id)->update(['u' => 0]); config(['legacy_capture_shadow.currency' => 'EUR']);
        $this->failure('CAPTURE_CONTEXT_DRIFT', fn () => $this->money()->project($user->id));
        $this->assertSame($before, $this->shadowState());
    }

    public function test_tampered_capture_payload_and_chain_are_not_projected(): void
    {
        $user = $this->user(); $this->capture($user, ['balance' => 110]); $this->capture($user, ['balance' => 120]);
        $event = DB::table('tx_legacy_capture_shadow_event')->orderBy('sequence')->first();
        DB::table('tx_legacy_capture_shadow_event')->where('id', $event->id)->update(['before_snapshot' => '{}']);
        $this->failure('CAPTURE_SOURCE_INVALID', fn () => $this->money()->project($user->id));
        DB::table('tx_legacy_capture_shadow_event')->where('id', $event->id)->update(['before_snapshot' => $event->before_snapshot]);
        DB::table('tx_legacy_capture_shadow_event')->where('id', $event->id)->update(['previous_event_hash' => str_repeat('a', 64)]);
        $this->failure('CAPTURE_EVENT_TAMPERED', fn () => $this->money()->project($user->id));
        $this->assertSame(0, DB::table('tx_legacy_money_shadow_checkpoint')->count());
    }

    public function test_posting_corruption_and_extra_zero_lines_are_read_only_failures(): void
    {
        $user = $this->user(); $this->capture($user, ['balance' => 110]); $this->money()->project($user->id);
        $row = DB::table('tx_legacy_money_shadow_posting')->orderBy('id')->first();
        DB::table('tx_legacy_money_shadow_posting')->where('id', $row->id)->update(['amount_minor' => 9]);
        $before = $this->shadowState(); $this->failure('MONEY_SHADOW_POSTING_DAMAGED', fn () => $this->money()->verify($user->id));
        $this->assertSame($before, $this->shadowState());
        DB::table('tx_legacy_money_shadow_posting')->where('id', $row->id)->update(['amount_minor' => $row->amount_minor]);
        $extra = (array) $row; unset($extra['id']); $extra['line_no'] = 3; $extra['amount_minor'] = 0;
        DB::table('tx_legacy_money_shadow_posting')->insert($extra);
        $this->failure('MONEY_SHADOW_POSTING_DAMAGED', fn () => $this->money()->project($user->id));
    }

    public function test_checkpoint_damage_and_rehashed_wrong_totals_are_refused(): void
    {
        $user = $this->user(); $this->capture($user, ['balance' => 110]); $this->money()->project($user->id);
        $cp = DB::table('tx_legacy_money_shadow_checkpoint')->first();
        DB::table('tx_legacy_money_shadow_checkpoint')->where('id', $cp->id)->update(['wallet_minor' => 109]);
        $this->failure('MONEY_SHADOW_CHECKPOINT_DAMAGED', fn () => $this->money()->project($user->id));
        $raw = (array) $cp; unset($raw['id'], $raw['content_hash']);
        foreach ($raw as $key => $value) if (is_int($value)) $raw[$key] = (string) $value;
        $raw['wallet_minor'] = '109'; $raw['content_hash'] = CommerceShadowCodec::hash($raw);
        DB::table('tx_legacy_money_shadow_checkpoint')->where('id', $cp->id)->update($raw);
        $this->failure('MONEY_SHADOW_CURSOR_DAMAGED', fn () => $this->money()->verify($user->id));
    }

    public function test_post_insert_fault_rolls_back_entire_page_and_safe_retry_does_not_duplicate(): void
    {
        $user = $this->user(); $this->capture($user, ['balance' => 110]); $this->capture($user, ['balance' => 120]);
        $this->money()->project($user->id, 1); $before = $this->allState(); $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && preg_match('/^insert.*tx_legacy_money_shadow_posting/i', $query->sql)) {
                $armed = false; throw new \RuntimeException('private-sql-fixture@example.test');
            }
        });
        $this->failure('MONEY_SHADOW_STORAGE_OR_EVIDENCE_FAILED', fn () => $this->money()->project($user->id));
        $this->assertSame($before, $this->allState()); $this->assertSame(1, $this->money()->project($user->id)['processed']);
        $this->assertSame(4, DB::table('tx_legacy_money_shadow_posting')->count());
    }

    public function test_cli_requires_marker_rejects_conflicting_modes_and_returns_no_private_data(): void
    {
        $user = $this->user(); $this->capture($user, ['balance' => 110]);
        $this->assertSame(1, Artisan::call('core:legacy-money-shadow', ['--user' => (string) $user->id]));
        $this->assertSame(1, Artisan::call('core:legacy-money-shadow', ['--isolated-copy' => true, '--user' => (string) $user->id, '--verify' => true, '--extend' => true]));
        $this->assertSame(0, Artisan::call('core:legacy-money-shadow', ['--isolated-copy' => true, '--user' => (string) $user->id]));
        $this->assertFalse(json_decode(Artisan::output(), true)['migration_gate_open']);
        $this->assertSame(0, Artisan::call('core:legacy-money-shadow', ['--isolated-copy' => true, '--user' => (string) $user->id, '--verify' => true]));
        foreach ([$user->email, $user->token, $user->uuid, 'before_snapshot', 'password'] as $secret) $this->assertStringNotContainsString($secret, Artisan::output());
    }

    public function test_schema_replay_retains_evidence_and_populated_rollback_is_refused(): void
    {
        $user = $this->user(); $this->capture($user, ['balance' => 110]); $this->money()->project($user->id);
        $migration = require database_path('migrations/2026_10_13_000001_create_legacy_money_shadow_tables.php');
        $before = $this->allState(); $migration->up(); $this->assertSame($before, $this->allState());
        try { $migration->down(); $this->fail('Deleted evidence'); }
        catch (\RuntimeException $e) { $this->assertSame('MONEY_SHADOW_POPULATED_ROLLBACK_REFUSED', $e->getMessage()); }
        $this->assertSame($before, $this->allState());
    }

    public function test_mysql_two_sessions_resume_the_same_page_without_duplicate_steps(): void
    {
        $this->requireFork(); $user = $this->user(); $this->capture($user, ['balance' => 110]); $this->capture($user, ['balance' => 120]);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fwrite($child, 'R'); fread($child, 1);
            try { fwrite($child, (string) $this->money()->project($user->id, 1)['cursor']); } catch (\Throwable) { fwrite($child, 'ERR'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1));
            $armed = true;
            DB::listen(function ($query) use (&$armed, $parent): void {
                if ($armed && preg_match('/^insert.*tx_legacy_money_shadow_checkpoint/i', $query->sql)) {
                    $armed = false; fwrite($parent, 'S'); $read = [$parent]; $write = []; $except = [];
                    $this->assertSame(0, stream_select($read, $write, $except, 0, 250000));
                }
            });
            $first = $this->money()->project($user->id, 1);
            $this->assertSame('1', $first['cursor']); $this->assertSame('2', stream_get_contents($parent, 32));
        } finally { fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertSame(2, DB::table('tx_legacy_money_shadow_step')->count());
        $this->assertTrue($this->money()->verify($user->id)['page_ok']);
    }

    public function test_mysql_sigkill_before_and_after_commit_resumes_durable_page(): void
    {
        $this->requireFork(); $user = $this->user(); $this->capture($user, ['balance' => 110]);
        $this->killProject($user, false); $this->assertSame(0, DB::table('tx_legacy_money_shadow_checkpoint')->count());
        $this->killProject($user, true); $this->assertSame(1, DB::table('tx_legacy_money_shadow_checkpoint')->count());
        $this->assertSame(0, $this->money()->project($user->id)['processed']);
        $this->assertSame(2, DB::table('tx_legacy_money_shadow_posting')->count()); $this->assertTrue($this->money()->verify($user->id)['page_ok']);
    }

    private function killProject(User $user, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier = function () use ($child): void { fwrite($child, 'K'); while (true) usleep(10000); };
            if ($afterCommit) Event::listen(TransactionCommitted::class, function ($event) use ($barrier): void {
                if ($event->connection->transactionLevel() === 0) $barrier();
            });
            else DB::listen(function ($query) use ($barrier): void {
                if (preg_match('/^update.*tx_legacy_money_shadow_checkpoint/i', $query->sql)) $barrier();
            });
            $this->money()->project($user->id); exit(2);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try { $this->assertSame('K', fread($parent, 1)); }
        finally { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); fclose($parent);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status)); }
    }
    private function capture(User $user, array $changes): void
    {
        DB::transaction(function () use ($user, $changes): void {
            DB::table('tx_user')->where('id', $user->id)->lockForUpdate()->first();
            $capture = app(LegacyUserMutationCapture::class); $ticket = $capture->begin($user->id);
            DB::table('tx_user')->where('id', $user->id)->update($changes); $capture->append($ticket, $user->id);
        });
    }
    private function user(array $overrides = []): User
    {
        $id = ++$this->counter;
        return User::create(array_replace(['email' => 'money-'.$id.'@example.test', 'password' => 'money-private-password',
            'uuid' => sprintf('b0000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'balance' => 100, 'commission_balance' => 50, 'transfer_enable' => 1000, 'u' => 0, 'd' => 0], $overrides));
    }
    private function money(): LegacyMoneyShadow { return app(LegacyMoneyShadow::class); }
    private function nativeState(): array
    {
        $out = [];
        foreach (['tx_user', 'tx_order', 'tx_subscription', 'tx_subscription_terms', 'tx_usage_period',
            'tx_legacy_capture_shadow_event', 'tx_legacy_capture_shadow_stream', 'tx_legacy_subscription_map',
            'tx_commerce_shadow_money_account', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_posting'] as $t)
            $out[$t] = DB::table($t)->orderBy('id')->get()->toJson();
        return $out;
    }
    private function shadowState(): array
    {
        $out = []; foreach (['checkpoint', 'step', 'posting'] as $suffix)
            $out[$suffix] = DB::table('tx_legacy_money_shadow_'.$suffix)->orderBy('id')->get()->toJson();
        return $out;
    }
    private function allState(): array { return $this->nativeState() + $this->shadowState(); }
    private function failure(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Accepted invalid shadow evidence.'); }
        catch (LegacyCaptureShadowException $e) { $this->assertSame($code, $e->getMessage()); $this->assertNull($e->getPrevious()); }
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'money_shadow_mysql_testing' : 'money_shadow_sqlite_testing'; }
    private function requireFork(): void
    { if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('Requires dedicated MySQL-family process fixtures.'); }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') throw new \RuntimeException('Money fixture requires its dedicated database.');
        Schema::disableForeignKeyConstraints(); try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
