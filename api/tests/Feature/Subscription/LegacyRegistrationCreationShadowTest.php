<?php

namespace Tests\Feature\Subscription;

use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacyAdminCreationShadow;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyRegistrationCreationShadow;
use App\Services\Subscription\LegacyUserCreationEvidence;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\UserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyRegistrationCreationShadowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::purge($connection); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true])); Bus::fake();
        config(['core_shadow.isolated_copy' => true, 'legacy_registration_creation_shadow.enabled' => true,
            'legacy_admin_creation_shadow.enabled' => false, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'registration-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD', 'legacy_observer_capture.enabled' => false]);
        admin_setting(['captcha_enable' => 0, 'stop_register' => 0, 'email_verify' => 0, 'invite_force' => 0,
            'invite_never_expire' => 0, 'try_out_plan_id' => 0, 'register_limit_by_ip_enable' => 0, 'secure_path' => 'registration_admin']);
    }
    protected function tearDown(): void
    { if ($this->mysql()) $this->clearMysql(); DB::purge($this->connection()); parent::tearDown(); }

    public function test_native_guest_registration_records_actual_self_bound_initial_snapshot_without_secrets(): void
    {
        $response = $this->registerAccount()->assertCreated(); $user = $this->registered(); $row = $this->evidence($user->id);
        $this->assertSame('user.register', $row->source_kind); $this->assertSame(LegacyUserCreationEvidence::REGISTRATION_VERSION, $row->source_version);
        $this->assertSame($user->id, (int) $row->actor_id);
        $snapshot = json_decode($row->initial_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(LegacyUserMutationCapture::FIELDS, array_keys($snapshot));
        $this->assertSame($this->source($user->id), $this->sort($snapshot)); $this->assertSame('0', $snapshot['balance']);
        $this->assertNull($snapshot['plan_id']); $this->assertNull($snapshot['next_reset_at']); $this->assertFalse($snapshot['banned']);
        $this->assertTrue($this->service()->verify($user->id)['source_matches_initial']); $this->assertSame(1, $user->tokens()->count());
        foreach ([$user->email, $user->password, $user->uuid, $user->token, 'RegistrationFixturePassword77', $response->json('data.auth_data')] as $secret)
            $this->assertStringNotContainsString($secret, json_encode($row));
        foreach (['tx_legacy_capture_shadow_event', 'tx_legacy_subscription_map', 'tx_subscription_terms', 'tx_access_subject'] as $table)
            $this->assertSame(0, DB::table($table)->count());
    }

    public function test_trial_plan_native_defaults_and_after_commit_schedule_are_separate_from_initial_evidence(): void
    {
        $plan = Plan::create(['name' => 'Registration trial', 'group_id' => 1, 'transfer_enable' => 3, 'speed_limit' => 19,
            'prices' => ['monthly' => 1], 'reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH]);
        admin_setting(['try_out_plan_id' => $plan->id, 'try_out_hour' => 2]); config(['legacy_observer_capture.enabled' => true]);
        $this->registerAccount()->assertCreated(); $user = $this->registered(); $snapshot = json_decode($this->evidence($user->id)->initial_snapshot, true);
        $this->assertSame('3221225472', $snapshot['transfer_enable']); $this->assertSame((string) $plan->id, $snapshot['plan_id']);
        $this->assertNull($snapshot['next_reset_at']); $this->assertNotNull($user->next_reset_at);
        $report = $this->service()->verify($user->id); $this->assertTrue($report['first_mutation_before_matches_initial']);
        $this->assertSame(['CREATION_SHADOW_SOURCE_CHANGED'], $report['issues']); $this->assertFalse($report['migration_gate_open']);
        $this->assertSame('user.schedule.observer', DB::table('tx_legacy_capture_shadow_event')->value('source_kind'));
    }

    public function test_default_off_production_saves_without_added_transaction_or_evidence_queries(): void
    {
        config(['legacy_registration_creation_shadow.enabled' => false, 'legacy_capture_shadow.enabled' => 'invalid', 'core_shadow.isolated_copy' => false]);
        $this->app['env'] = 'production'; Schema::drop('tx_legacy_user_creation_shadow'); $level = null;
        Event::listen('eloquent.created: '.User::class, function () use (&$level): void { $level = DB::transactionLevel(); });
        DB::enableQueryLog(); DB::flushQueryLog(); $this->registerAccount()->assertCreated();
        $this->assertSame(0, $level);
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_user_creation_shadow|tx_legacy_capture_shadow/', $q['query']))));
    }

    public function test_original_validation_stopped_registration_and_duplicate_email_refuse_without_second_anchor(): void
    {
        $this->registerAccount(['password' => 'x'])->assertStatus(422); admin_setting(['stop_register' => 1]);
        $this->registerAccount()->assertStatus(422); $this->assertSame(0, User::count());
        admin_setting(['stop_register' => 0]); $this->registerAccount()->assertCreated(); $history = $this->history();
        config(['legacy_registration_creation_shadow.enabled' => 'invalid']);
        $this->registerAccount()->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT'); $this->assertSame($history, $this->history());
    }

    public function test_strict_flags_and_isolation_requirements_refuse_account_and_evidence_save(): void
    {
        foreach ([1, '1', null] as $flag) { config(['legacy_registration_creation_shadow.enabled' => $flag]); $this->registerAccount()->assertStatus(409); }
        config(['legacy_registration_creation_shadow.enabled' => true, 'legacy_capture_shadow.enabled' => false]); $this->registerAccount()->assertStatus(409);
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]); $this->registerAccount()->assertStatus(409);
        config(['core_shadow.isolated_copy' => true]); $this->app['env'] = 'production'; $this->registerAccount()->assertStatus(409);
        $this->app['env'] = 'testing'; config(['legacy_capture_shadow.currency' => 'JPY']); $this->registerAccount()->assertStatus(409);
        $this->assertSame(0, User::count()); $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
    }

    public function test_missing_evidence_storage_rolls_back_user_without_session_or_private_diagnostics(): void
    {
        Schema::drop('tx_legacy_user_creation_shadow'); $response = $this->registerAccount()->assertStatus(409);
        $this->assertSame(0, User::count()); $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('RegistrationFixturePassword77', $response->getContent()); Bus::assertNothingDispatched();
    }

    public function test_storage_failure_after_insert_rolls_back_user_evidence_but_keeps_original_invite_consumption(): void
    {
        $inviter = $this->inviter(); $invite = new InviteCode(); $invite->user_id = $inviter->id;
        $invite->code = 'registration-fixture-invite'; $invite->status = InviteCode::STATUS_UNUSED; $invite->saveOrFail();
        admin_setting(['invite_force' => 1]); $active = true;
        DB::listen(function ($query) use (&$active): void {
            if ($active && preg_match('/^insert.*tx_legacy_user_creation_shadow/i', $query->sql)) throw new \RuntimeException('private-registration-storage-detail');
        });
        try { $response = $this->registerAccount(['invite_code' => $invite->code])->assertStatus(409); } finally { $active = false; }
        $this->assertNull(User::byEmail('registered@example.test')->first()); $this->assertTrue($invite->fresh()->status);
        $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count()); $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertStringNotContainsString('private-registration-storage-detail', $response->getContent());
        $this->assertStringNotContainsString($invite->code, $response->getContent());
    }

    public function test_successful_invitation_preserves_native_inviter_reference_and_excludes_invite_secret(): void
    {
        $inviter = $this->inviter(); $invite = new InviteCode(); $invite->user_id = $inviter->id;
        $invite->code = 'registration-success-invite'; $invite->status = InviteCode::STATUS_UNUSED; $invite->saveOrFail();
        $this->registerAccount(['invite_code' => $invite->code])->assertCreated(); $user = $this->registered();
        $this->assertSame($inviter->id, $user->invite_user_id); $this->assertTrue($invite->fresh()->status);
        $row = $this->evidence($user->id); $this->assertSame($user->id, (int) $row->actor_id);
        $this->assertStringNotContainsString($invite->code, json_encode($row));
        $this->assertArrayNotHasKey('invite_user_id', json_decode($row->initial_snapshot, true));
    }

    public function test_saving_cancellation_keeps_original_false_save_response_without_evidence_or_session(): void
    {
        Event::listen('eloquent.saving: '.User::class, fn () => false);
        $this->registerAccount()->assertStatus(422); $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count()); $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_context_drift_during_created_event_rolls_back_both_rows(): void
    {
        Event::listen('eloquent.created: '.User::class, fn () => config(['legacy_capture_shadow.run_key' => 'changed-context']));
        $this->registerAccount()->assertStatus(409); $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count()); Bus::assertNothingDispatched();
    }

    public function test_original_registration_after_hook_can_hide_committed_user_and_initial_evidence(): void
    {
        HookManager::register('user.register.after', function (): void { throw new \RuntimeException('synthetic-register-after-failure'); });
        $this->registerAccount()->assertStatus(500); $user = $this->registered();
        $this->assertTrue($this->service()->verify($user->id)['source_matches_initial']); $this->assertSame(0, $user->tokens()->count());
        $this->registerAccount()->assertStatus(409); $this->assertSame(1, DB::table('tx_legacy_user_creation_shadow')->count());
    }

    public function test_admin_and_registration_creation_gates_verifiers_and_tickets_remain_independent(): void
    {
        $this->registerAccount()->assertCreated(); $registered = $this->registered(); $admin = $this->inviter(['is_admin' => true]);
        config(['legacy_admin_creation_shadow.enabled' => true]); Sanctum::actingAs($admin);
        $id = $this->postJson('/txapi/admin/registration_admin/users', ['email_prefix' => 'admin-created', 'email_suffix' => 'example.test',
            'password' => 'AdminCreationFixturePassword77'])->assertCreated()->json('data.id');
        $this->assertTrue(app(LegacyAdminCreationShadow::class)->verify($id)['source_matches_initial']);
        foreach ([fn () => app(LegacyAdminCreationShadow::class)->verify($registered->id), fn () => $this->service()->verify($id)] as $verify) {
            try { $verify(); $this->fail('cross-policy verifier accepted'); }
            catch (LegacyCaptureShadowException $exception) { $this->assertSame('CREATION_SHADOW_EVIDENCE_CHANGED', $exception->getMessage()); }
        }
        DB::transaction(function () use ($registered): void {
            $ticket = app(LegacyUserCreationEvidence::class)->begin('user.register', 0);
            try { app(LegacyAdminCreationShadow::class)->append($ticket, $registered->id); $this->fail('cross-policy ticket accepted'); }
            catch (LegacyCaptureShadowException $exception) { $this->assertSame('CREATION_SHADOW_TICKET_INVALID', $exception->getMessage()); }
        });
        config(['legacy_registration_creation_shadow.enabled' => false]);
        $this->assertTrue(app(LegacyAdminCreationShadow::class)->verify($id)['source_matches_initial']);
    }

    public function test_bounded_cli_requires_marker_and_self_binding_tampering_refuses(): void
    {
        $this->registerAccount()->assertCreated(); $user = $this->registered();
        $this->assertSame(1, Artisan::call('core:registration-creation-shadow-verify', ['--user' => (string) $user->id]));
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame(0, Artisan::call('core:registration-creation-shadow-verify', ['--user' => (string) $user->id, '--isolated-copy' => true]));
        foreach (DB::getQueryLog() as $query) $this->assertMatchesRegularExpression('/^select/i', $query['query']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR); $this->assertFalse($report['global_coverage_complete']);
        DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $user->id)->update(['actor_id' => $user->id+1]);
        $this->expectException(LegacyCaptureShadowException::class); $this->expectExceptionMessage('CREATION_SHADOW_EVIDENCE_CHANGED');
        $this->service()->verify($user->id);
    }

    public function test_persist_rejects_existing_users_and_outer_transaction_without_inventing_creation(): void
    {
        $user = $this->inviter();
        try { $this->service()->persist($user); $this->fail('existing user was accepted'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame('REGISTRATION_SHADOW_NEW_USER_REQUIRED', $exception->getMessage()); }
        DB::transaction(function (): void {
            $new = app(UserService::class)->createUser(['email' => 'outer@example.test', 'password' => 'OuterFixturePassword77']);
            try { $this->service()->persist($new); $this->fail('outer transaction accepted'); }
            catch (LegacyCaptureShadowException $exception) { $this->assertSame('REGISTRATION_SHADOW_OUTER_TRANSACTION_UNSUPPORTED', $exception->getMessage()); }
        });
        $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
    }

    public function test_real_mysql_independent_session_observes_original_registration_insert_and_evidence_atomically(): void
    {
        if (!$this->mysql()) $this->markTestSkipped('Requires dedicated MySQL independent session visibility.');
        config(['database.connections.registration_observer' => config('database.connections.'.$this->connection())]);
        DB::purge('registration_observer'); $other = DB::connection('registration_observer'); $checks = 0;
        DB::listen(function ($query) use ($other, &$checks): void {
            if (preg_match('/^insert.*tx_legacy_user_creation_shadow/i', $query->sql)) {
                $this->assertSame(0, $other->table('tx_user')->count()); $this->assertSame(0, $other->table('tx_legacy_user_creation_shadow')->count()); ++$checks;
            }
        });
        $this->registerAccount()->assertCreated(); $this->assertSame(1, $checks); $user = $this->registered();
        $this->assertSame(1, $other->table('tx_user')->where('id', $user->id)->count());
        $this->assertSame(1, $other->table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $user->id)->count());
        DB::purge('registration_observer');
    }

    private function registerAccount(array $input = []): \Illuminate\Testing\TestResponse
    { return $this->postJson('/txapi/auth/register', array_replace(['email' => 'registered@example.test', 'password' => 'RegistrationFixturePassword77'], $input)); }
    private function registered(): User { return User::byEmail('registered@example.test')->firstOrFail(); }
    private function service(): LegacyRegistrationCreationShadow { return app(LegacyRegistrationCreationShadow::class); }
    private function evidence(int $id): object { return DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $id)->firstOrFail(); }
    private function inviter(array $attributes = []): User
    { return User::withoutEvents(fn () => User::create(array_replace(['email' => 'inviter@example.test', 'password' => 'synthetic-password',
        'uuid' => 'c0000000-0000-4000-8000-000000000001', 'token' => 'synthetic-inviter-token'], $attributes))); }
    private function sort(array $row): array { ksort($row, SORT_STRING); return $row; }
    private function source(int $id): array
    {
        $row = (array) DB::table('tx_user')->where('id', $id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value);
        return $this->sort($row);
    }
    private function history(): string { return DB::table('tx_legacy_user_creation_shadow')->orderBy('id')->get()->toJson(); }
    private function mysql(): bool { return getenv('CORE_REGISTRATION_CREATION_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'registration_mysql_testing' : 'registration_sqlite_testing'; }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_registration_creation_test') throw new \RuntimeException('Registration evidence requires its dedicated test database.');
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
