<?php

namespace Tests\Feature\AuthorityMigration;

use App\Console\Commands\TxboardInstall;
use App\Domains\AuthorityMigration\AuthorityIdentityInventory;
use App\Domains\AuthorityMigration\AuthorityInventory;
use App\Domains\AuthorityMigration\AuthorityFinanceInventory;
use App\Domains\AuthorityMigration\AuthorityIdentitySqlPhase;
use App\Domains\AuthorityMigration\AuthorityNativeInventory;
use App\Domains\AuthorityMigration\AuthorityPostcommitHandoff;
use App\Domains\AuthorityMigration\AuthorityPostcommitIntent;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Jobs\NodeUserSyncJob;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Models\InviteCode;
use App\Models\Setting as SettingModel;
use App\Models\SubscribeTemplate;
use App\Models\User;
use App\Services\AuthService;
use App\Services\MailService;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\LegacyUserCreationEvidence;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthorityIdentityFixture;
use Tests\TestCase;

/** Actual native roots and persisted partial outcomes, on an isolated SQL copy. */
class AuthorityIdentityWritersTest extends TestCase
{
    use AuthorityIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureIdentityDatabase();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        Bus::fake();
        HookManager::reset();
        config(['authority_writer_fence.enabled' => false, 'core_shadow.isolated_copy' => true,
            'legacy_capture_shadow.enabled' => false, 'legacy_admin_creation_shadow.enabled' => false,
            'legacy_registration_creation_shadow.enabled' => false, 'legacy_bootstrap_creation_shadow.enabled' => false,
            'legacy_admin_deletion_shadow.enabled' => false, 'legacy_observer_capture.enabled' => false,
            'legacy_standalone_reset_capture.enabled' => false, 'legacy_plan_propagation_capture.enabled' => false,
            'legacy_bulk_ban_capture.enabled' => false]);
        admin_setting(['secure_path' => 'identity_fixture', 'try_out_plan_id' => 0, 'captcha_enable' => 0,
            'password_limit_enable' => 0, 'email_verify' => 0, 'invite_force' => 0,
            'commission_withdraw_limit' => 0, 'commission_withdraw_method' => ['alipay'], 'invite_gen_limit' => 5]);
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => '1', 'worker_role' => 'legacy']]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
        // Daily generation uses only native SQL; its legacy constructor still resolves Redis.
        Redis::shouldReceive('connection')->zeroOrMoreTimes()->andReturn(new \stdClass());
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        if ($this->identityMysql()) $this->clearIdentityDatabase();
        DB::purge($this->identityConnection());
        parent::tearDown();
    }

    public function test_exact_identity_upgrade_binds_parent_without_rewriting_old_receipts_and_replays_after_prepare(): void
    {
        $this->native();
        $history = $this->rows(['tx_authority_inventory_upgrade', 'tx_authority_finance_upgrade', 'tx_authority_native_upgrade']);
        $parent = (array) DB::table('tx_authority_writer_fence')->first();
        $result = $this->fence()->extendIdentity('identity-fixture', '4');
        $receipt = $result['identity_receipt'];
        $this->assertSame('5', $result['authority_epoch']);
        $this->assertSame($parent['content_hash'], $receipt['prior_state_hash']);
        $this->assertSame($history, $this->rows(array_keys($history)));
        $this->assertSame(DB::table('tx_authority_native_upgrade')->value('content_hash'), $receipt['parent_native_upgrade_hash']);
        $this->assertCount(53, $this->fence()->status()['covered_writers']);
        $this->assertSame(AuthorityIdentityInventory::SCOPE, $this->fence()->status()['scope']);
        config(['authority_writer_fence.worker_epoch' => '5', 'authority_writer_fence.worker_scope' => AuthorityIdentityInventory::SCOPE,
            'authority_writer_fence.worker_version' => AuthorityIdentityInventory::VERSION]);
        $prepared = $this->fence()->prepare('identity-stop', '5');
        $this->assertSame('6', $prepared['authority_epoch']);
        $before = $this->rows(['tx_authority_writer_fence', 'tx_authority_prepare_receipt', 'tx_authority_identity_upgrade']);
        $this->assertSame($receipt, $this->fence()->extendIdentity('identity-fixture', '4')['identity_receipt']);
        $recovered = $this->fence()->recoverIdentity('identity-fixture', '4');
        $this->assertSame('committed_identity', $recovered['recovery_status']);
        $this->assertTrue($recovered['read_only']);
        $this->assertSame($before, $this->rows(array_keys($before)));
        $this->refused(fn () => $this->fence()->extendIdentity('different-key', '4'), 'AUTHORITY_IDENTITY_REQUEST_CONFLICT');
        foreach (['global_writer_coverage_complete', 'global_drain_complete', 'target_authority_active', 'migration_gate_open'] as $key) {
            $this->assertFalse($this->fence()->status()[$key]);
        }
    }

    public static function oldVersions(): array
    {
        return ['v1' => [1, AuthorityWriterFence::SCOPE, AuthorityWriterFence::VERSION],
            'v2' => [2, AuthorityInventory::SCOPE, AuthorityInventory::VERSION],
            'v3' => [3, AuthorityFinanceInventory::SCOPE, AuthorityFinanceInventory::VERSION],
            'v4' => [4, AuthorityNativeInventory::SCOPE, AuthorityNativeInventory::VERSION]];
    }

    #[DataProvider('oldVersions')]
    public function test_old_prepared_versions_are_immutable_and_cannot_be_reinterpreted_as_identity_open(
        int $version, string $scope, string $fenceVersion
    ): void
    {
        $this->fence()->initialize();
        if ($version >= 2) $this->fence()->expand('identity-parent-inventory', '1');
        if ($version >= 3) $this->fence()->extendFinance('identity-parent-finance', '2');
        if ($version >= 4) $this->fence()->extendNative('identity-parent-native', '3');
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => (string) $version,
            'worker_role' => 'legacy', 'worker_scope' => $scope, 'worker_version' => $fenceVersion]]);
        $this->fence()->prepare('old-native-stop', (string) $version);
        $before = $this->rows(['tx_authority_writer_fence', 'tx_authority_prepare_receipt',
            'tx_authority_inventory_upgrade', 'tx_authority_finance_upgrade', 'tx_authority_native_upgrade']);
        $this->refused(fn () => $this->fence()->extendIdentity('identity-fixture', '4'), 'AUTHORITY_IDENTITY_EXTEND_REFUSED');
        $this->assertSame($before, $this->rows(array_keys($before)));
        $this->assertSame((string) ($version + 1), $this->fence()->status()['authority_epoch']);
        $this->assertSame($scope, $this->fence()->status()['scope']);
        $this->assertSame(0, DB::table('tx_authority_identity_upgrade')->count());
    }

    public function test_every_identity_only_owner_is_refused_under_the_old_native_declaration_before_callback_sql(): void
    {
        $this->native();
        $user = $this->user();
        foreach (array_diff(AuthorityIdentityInventory::WRITERS, AuthorityNativeInventory::WRITERS) as $writer) {
            $entered = false;
            $this->refused(fn () => app(AuthorityIdentitySqlPhase::class)->run($writer, function () use (&$entered, $user): void {
                $entered = true;
                DB::table('tx_user')->where('id', $user->id)->update(['balance' => 999]);
            }), 'AUTHORITY_FENCE_WRITER_INVALID');
            $this->assertFalse($entered, $writer);
            $this->assertSame(0, (int) $user->fresh()->balance);
        }
    }

    public function test_native_register_login_and_admin_login_issue_original_sessions_with_nonsecret_v5_evidence(): void
    {
        $this->identity();
        $email = 'identity-register@example.test';
        $registered = $this->postJson('/txapi/auth/register', ['email' => $email, 'password' => 'FixturePassword77'])->assertCreated();
        $user = User::byEmail($email)->firstOrFail();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertStringStartsWith('Bearer ', $registered->json('data.auth_data'));
        $this->postJson('/txapi/auth/login', ['email' => $email, 'password' => 'FixturePassword77'])->assertOk();
        $this->assertSame(2, $user->tokens()->count());
        $this->postJson('/txapi/auth/admin/login', ['email' => $email, 'password' => 'FixturePassword77'])->assertForbidden();
        $this->assertSame(2, $user->tokens()->count());
        $admin = $this->user(['is_admin' => true]);
        $this->postJson('/txapi/auth/admin/login', ['email' => $admin->email, 'password' => 'FixturePassword77'])
            ->assertOk()->assertJsonPath('data.secure_path', 'identity_fixture');
        $encoded = json_encode($this->rows(['tx_authority_identity_upgrade', 'tx_authority_postcommit_intent']), JSON_THROW_ON_ERROR);
        foreach ([$email, 'FixturePassword77', $user->password, $user->uuid, $user->token, $registered->json('data.auth_data')] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_actual_http_identity_roots_fail_closed_under_an_enabled_old_v4_worker(): void
    {
        $this->native();
        $user = $this->user();
        $admin = $this->user(['is_admin' => true]);
        $before = $this->rows(['tx_user', 'personal_access_tokens', 'tx_settings', 'tx_invite_code']);
        $this->postJson('/txapi/auth/login', ['email' => $user->email, 'password' => 'FixturePassword77'])->assertStatus(409);
        $this->postJson('/txapi/auth/register', ['email' => 'old-worker-identity@example.test', 'password' => 'FixturePassword77'])->assertStatus(409);
        Sanctum::actingAs($user);
        $this->patchJson('/txapi/me/preferences', ['remind_expire' => false])->assertStatus(409);
        $this->postJson('/txapi/invites')->assertStatus(409);
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/identity_fixture/settings', ['app_name' => 'Old worker forbidden'])->assertStatus(409);
        $this->assertSame($before, $this->rows(array_keys($before)));
    }

    public function test_prepare_at_register_after_preserves_invite_and_user_and_consumes_code_before_touch_refusal(): void
    {
        $this->identity();
        admin_setting(['invite_force' => 1, 'email_verify' => 1]);
        $inviter = $this->user();
        $invite = $this->invite($inviter);
        $email = 'identity-partial@example.test';
        $key = CacheKey::get('EMAIL_VERIFY_CODE', $email);
        Cache::put($key, '123456', 300);
        $order = [];
        HookManager::register('user.register.before', function () use (&$order): void { $order[] = 'before'; });
        HookManager::register('user.register.after', function (User $user) use (&$order, $invite): void {
            $order[] = 'after';
            $this->assertTrue((bool) $invite->fresh()->status);
            $this->assertTrue($user->exists);
            $this->assertSame(0, DB::transactionLevel());
            $this->stop();
        });
        $this->postJson('/txapi/auth/register', ['email' => $email, 'password' => 'FixturePassword77',
            'invite_code' => $invite->code, 'email_code' => '123456'])->assertStatus(409);
        $created = User::byEmail($email)->firstOrFail();
        $this->assertSame(['before', 'after'], $order);
        $this->assertSame($inviter->id, (int) $created->invite_user_id);
        $this->assertNull($created->last_login_at);
        $this->assertSame(0, $created->tokens()->count());
        $this->assertNull(Cache::get($key));
        $this->assertTrue((bool) $invite->fresh()->status);
    }

    public function test_register_invite_commit_is_not_compensated_when_prepare_refuses_user_insert(): void
    {
        $this->identity();
        $invite = $this->invite($this->user());
        $this->prepareAfterCommit(fn () => (bool) $invite->fresh()->status);
        $this->postJson('/txapi/auth/register', ['email' => 'identity-invite-only@example.test',
            'password' => 'FixturePassword77', 'invite_code' => $invite->code])->assertStatus(409);
        $this->assertTrue((bool) $invite->fresh()->status);
        $this->assertNull(User::byEmail('identity-invite-only@example.test')->first());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_prepare_after_login_touch_retains_last_login_but_refuses_later_session_issue(): void
    {
        $this->identity();
        $user = $this->user(['last_login_at' => null]);
        HookManager::register('user.login.after', function (): void { $this->stop(); });
        $this->postJson('/txapi/auth/login', ['email' => $user->email, 'password' => 'FixturePassword77'])->assertStatus(409);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_never_expire_invite_stays_unused_across_native_registration(): void
    {
        $this->identity();
        admin_setting(['invite_force' => 1, 'invite_never_expire' => 1]);
        $invite = $this->invite($this->user());
        $this->postJson('/txapi/auth/register', ['email' => 'never-expire-identity@example.test',
            'password' => 'FixturePassword77', 'invite_code' => $invite->code])->assertCreated();
        $this->assertFalse((bool) $invite->fresh()->status);
    }

    public function test_register_hook_failure_preserves_insert_but_leaves_later_native_code_and_touch_unexecuted(): void
    {
        $this->identity();
        admin_setting(['email_verify' => 1]);
        $key = CacheKey::get('EMAIL_VERIFY_CODE', 'hook-failure-identity@example.test');
        Cache::put($key, '123456', 300);
        HookManager::register('user.register.after', function (): void { throw new \RuntimeException('synthetic hook refusal'); });
        $this->postJson('/txapi/auth/register', ['email' => 'hook-failure-identity@example.test',
            'password' => 'FixturePassword77', 'email_code' => '123456'])->assertStatus(500);
        $user = User::byEmail('hook-failure-identity@example.test')->firstOrFail();
        $this->assertNull($user->last_login_at);
        $this->assertSame('123456', Cache::get($key));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_prepare_after_register_touch_retains_user_and_last_login_but_refuses_token_phase(): void
    {
        $this->identity();
        $email = 'touch-only-identity@example.test';
        $this->prepareAfterCommit(fn () => User::byEmail($email)->whereNotNull('last_login_at')->exists());
        $this->postJson('/txapi/auth/register', ['email' => $email, 'password' => 'FixturePassword77'])->assertStatus(409);
        $user = User::byEmail($email)->firstOrFail();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_actual_bearer_logout_deletes_current_session_and_keeps_other_devices(): void
    {
        $this->identity();
        $user = $this->user();
        $current = $user->createToken('logout-current');
        $other = $user->createToken('logout-other');
        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)->postJson('/txapi/auth/logout')->assertOk();
        $this->assertNull($current->accessToken->fresh());
        $this->assertNotNull($other->accessToken->fresh());
    }

    public function test_session_issue_rechecks_current_ban_and_admin_role_after_native_login_hook(): void
    {
        $this->identity();
        $user = $this->user(['is_admin' => true]);
        HookManager::register('user.login.after', function (User $current): void {
            DB::table('tx_user')->where('id', $current->id)->update(['is_admin' => false]);
        });
        $this->postJson('/txapi/auth/admin/login', ['email' => $user->email, 'password' => 'FixturePassword77'])->assertForbidden();
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame(0, $user->tokens()->count());
        HookManager::reset();
        HookManager::register('user.login.after', function (User $current): void {
            DB::table('tx_user')->where('id', $current->id)->update(['banned' => true]);
        });
        $this->postJson('/txapi/auth/login', ['email' => $user->email, 'password' => 'FixturePassword77'])->assertForbidden();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_real_bearer_runs_sanctum_last_used_after_native_event_and_preserves_modified_records(): void
    {
        $this->identity();
        $user = $this->user();
        $token = $user->createToken('identity-real-bearer', ['*'], now()->addHour());
        $seen = false;
        Event::listen(TokenAuthenticated::class, function ($event) use (&$seen): void {
            $seen = true;
            $this->assertNull($event->token->last_used_at);
        });
        DB::connection()->setRecordModificationState(false);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson('/txapi/me')->assertOk();
        $this->assertTrue($seen);
        $this->assertNotNull($token->accessToken->fresh()->last_used_at);
        $this->assertFalse(DB::connection()->hasModifiedRecords());
        Auth::forgetGuards();
        Event::forget(TokenAuthenticated::class);
        DB::connection()->setRecordModificationState(true);
        $this->getJson('/txapi/me')->assertOk();
        $this->assertTrue(DB::connection()->hasModifiedRecords());
        Auth::forgetGuards();
        $this->stop();
        $before = $token->accessToken->fresh()->last_used_at->toISOString();
        $this->getJson('/txapi/me')->assertStatus(409);
        $this->assertSame($before, $token->accessToken->fresh()->last_used_at->toISOString());
    }

    public function test_invalid_expired_and_retired_agent_bearers_never_touch_native_last_used(): void
    {
        $this->identity();
        $user = $this->user();
        foreach ([['agent:retired', ['*'], now()->addHour()], ['ordinary', ['agent:read'], now()->addHour()],
            ['expired', ['*'], now()->subMinute()]] as [$name, $abilities, $expiry]) {
            Auth::forgetGuards();
            $token = $user->createToken($name, $abilities, $expiry);
            $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson('/txapi/me')->assertUnauthorized();
            $this->assertNull($token->accessToken->fresh()->last_used_at);
        }
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer invalid-identity-fixture')->getJson('/txapi/me')->assertUnauthorized();
    }

    public function test_preferences_rotation_invites_withdrawal_and_session_revoke_use_actual_native_http_owners(): void
    {
        $this->identity();
        $user = $this->user(['commission_balance' => 1000]);
        Sanctum::actingAs($user);
        $this->patchJson('/txapi/me/preferences', ['remind_expire' => false, 'balance' => 999])
            ->assertOk()->assertJsonPath('data.remind_expire', false);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $old = $user->token;
        $this->postJson('/txapi/me/subscription-credentials/rotate')->assertOk();
        $this->assertNotSame($old, $user->fresh()->token);
        $this->postJson('/txapi/invites')->assertCreated();
        $invite = InviteCode::where('user_id', $user->id)->firstOrFail();
        $this->postJson('/txapi/public/invite-page-view', ['invite_code' => $invite->code])->assertOk();
        $this->assertSame(1, (int) $invite->fresh()->pv);
        $this->postJson('/txapi/billing/withdrawals', ['withdraw_method' => 'alipay', 'withdraw_account' => 'fixture-only'])->assertCreated();
        $this->postJson('/txapi/billing/withdrawals', ['withdraw_method' => 'alipay', 'withdraw_account' => 'fixture-only'])->assertStatus(409);
        $token = $user->createToken('revoke-fixture');
        $this->deleteJson('/txapi/auth/sessions/'.$token->accessToken->id)->assertOk();
        $this->deleteJson('/txapi/auth/sessions/'.$token->accessToken->id)->assertNotFound();
        $this->assertNull($token->accessToken->fresh());
    }

    public function test_prepare_refuses_actual_native_http_roots_without_business_mutation(): void
    {
        $this->identity();
        $user = $this->user(['commission_balance' => 1000]);
        $invite = $this->invite($user);
        $token = $user->createToken('prepared-session');
        $this->stop();
        Sanctum::actingAs($user);
        $before = $this->rows(['tx_user', 'tx_invite_code', 'personal_access_tokens', 'tx_ticket']);
        $this->patchJson('/txapi/me/preferences', ['remind_traffic' => false])->assertStatus(409);
        $this->postJson('/txapi/me/subscription-credentials/rotate')->assertStatus(409);
        $this->postJson('/txapi/invites')->assertStatus(409);
        $this->postJson('/txapi/public/invite-page-view', ['invite_code' => $invite->code])->assertStatus(409);
        $this->postJson('/txapi/billing/withdrawals', ['withdraw_method' => 'alipay', 'withdraw_account' => 'fixture-only'])->assertStatus(409);
        $this->deleteJson('/txapi/auth/sessions/'.$token->accessToken->id)->assertStatus(409);
        $this->postJson('/txapi/auth/password', ['old_password' => 'FixturePassword77', 'new_password' => 'ChangedPassword88'])->assertStatus(409);
        $this->postJson('/txapi/auth/login', ['email' => $user->email, 'password' => 'FixturePassword77'])->assertStatus(409);
        $this->postJson('/txapi/auth/register', ['email' => 'blocked@example.test', 'password' => 'FixturePassword77'])->assertStatus(409);
        $this->assertSame($before, $this->rows(array_keys($before)));
    }

    public function test_password_commit_survives_prepare_before_original_later_session_revocation(): void
    {
        $this->identity();
        $user = $this->user();
        $session = $user->createToken('password-other-device');
        Sanctum::actingAs($user);
        $this->prepareAfterCommit(fn () => password_verify('ChangedPassword88', $user->fresh()->password));
        $this->postJson('/txapi/auth/password', ['old_password' => 'FixturePassword77', 'new_password' => 'ChangedPassword88'])->assertStatus(409);
        $this->assertTrue(password_verify('ChangedPassword88', $user->fresh()->password));
        $this->assertNotNull($session->accessToken->fresh());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_forgot_password_native_success_revokes_sessions_and_consumes_original_email_code(): void
    {
        $this->identity();
        $user = $this->user();
        $user->createToken('recovery-old-device');
        $key = CacheKey::get('EMAIL_VERIFY_CODE', $user->email);
        Cache::put($key, '123456', 300);
        $this->postJson('/txapi/auth/password/forgot', ['email' => $user->email, 'email_code' => '123456',
            'password' => 'RecoveredPassword88'])->assertOk();
        $this->assertTrue(password_verify('RecoveredPassword88', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull(Cache::get($key));
        $this->postJson('/txapi/auth/password/forgot', ['email' => $user->email, 'email_code' => '123456',
            'password' => 'NeverAppliedPassword99'])->assertStatus(400);
        $this->assertTrue(password_verify('RecoveredPassword88', $user->fresh()->password));
    }

    public function test_one_time_login_code_remains_consumed_when_session_gate_refuses(): void
    {
        $this->identity();
        $user = $this->user();
        $key = CacheKey::get('TEMP_TOKEN', 'fixture-disposable');
        Cache::put($key, $user->id, 300);
        $this->stop();
        $this->postJson('/txapi/auth/one-time-token', ['verify' => 'fixture-disposable'])->assertStatus(409);
        $this->assertNull(Cache::get($key));
        $this->postJson('/txapi/auth/one-time-token', ['verify' => 'fixture-disposable'])->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_original_per_key_settings_commits_first_key_when_prepare_refuses_the_second(): void
    {
        $this->identity();
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->prepareAfterCommit(fn () => SettingModel::where('name', 'app_name')->value('value') === 'Committed identity name');
        $this->postJson('/txapi/admin/identity_fixture/settings', ['app_name' => 'Committed identity name',
            'app_description' => 'Should remain absent'])->assertStatus(409);
        $this->assertSame('Committed identity name', SettingModel::where('name', 'app_name')->value('value'));
        $this->assertNull(SettingModel::where('name', 'app_description')->first());
    }

    public function test_admin_settings_template_and_remove_are_fixed_native_phases_with_no_model_schedule_events(): void
    {
        $this->identity();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->postJson('/txapi/admin/identity_fixture/settings', ['subscribe_template_clash' => 'fixture-template-content'])
            ->assertOk();
        $this->assertSame('fixture-template-content', SubscribeTemplate::where('name', 'clash')->value('content'));
        app(Setting::class)->set('identity_fixture_setting', 'fixture-value');
        app(Setting::class)->remove('identity_fixture_setting');
        $this->assertNull(SettingModel::where('name', 'identity_fixture_setting')->first());
        $this->assertSame(0, DB::table('tx_authority_postcommit_intent')->count());
        $this->stop();
        $before = $this->rows(['tx_settings', 'tx_subscribe_templates']);
        $this->postJson('/txapi/admin/identity_fixture/settings', ['subscribe_template_clash' => 'refused-content'])->assertStatus(409);
        $this->refused(fn () => app(Setting::class)->remove('secure_path'), 'AUTHORITY_FENCE_STALE_EPOCH');
        $this->assertSame($before, $this->rows(array_keys($before)));
    }

    public function test_successful_admin_response_keeps_setting_commit_when_later_audit_append_is_refused(): void
    {
        $this->identity();
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->prepareAfterCommit(fn () => SettingModel::where('name', 'app_name')->value('value') === 'Audit boundary fixture');
        $this->postJson('/txapi/admin/identity_fixture/settings', ['app_name' => 'Audit boundary fixture'])->assertOk();
        $this->assertSame('Audit boundary fixture', SettingModel::where('name', 'app_name')->value('value'));
        $this->assertSame(0, DB::table('tx_admin_audit_log')->count());
    }

    public function test_settings_cache_outcome_failure_keeps_original_sql_commit_and_reports_unknown(): void
    {
        $this->identity();
        Cache::store(config('cache.setting_store', 'redis'))->forever(Setting::CACHE_KEY, ['identity_cache_result' => 'before']);
        Event::listen(KeyForgotten::class, function ($event): void {
            if ($event->key === Setting::CACHE_KEY) throw new \RuntimeException('synthetic cache result lost');
        });
        try {
            app(Setting::class)->set('identity_cache_result', 'fixture-value');
            $this->fail('Expected fixed unknown external outcome.');
        } catch (\RuntimeException $error) {
            $this->assertSame('AUTHORITY_SETTINGS_CACHE_OUTCOME_UNKNOWN', $error->getMessage());
        }
        $this->assertSame('fixture-value', SettingModel::where('name', 'identity_cache_result')->value('value'));
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertFalse($this->fence()->status()['global_drain_complete']);
    }

    public function test_native_stat_user_arithmetic_uses_actual_named_driver_and_prepare_blocks_following_user(): void
    {
        $this->identity();
        $first = $this->user();
        $second = $this->user();
        $job = new StatUserJob(['id' => 1, 'rate' => 2], [$first->id => [3, 5]], 'vmess');
        $job->handle();
        $job->handle();
        $this->assertSame(12, (int) DB::table('tx_stat_user')->where('user_id', $first->id)->value('u'));
        $this->assertSame(20, (int) DB::table('tx_stat_user')->where('user_id', $first->id)->value('d'));
        $this->prepareAfterCommit(fn () => (int) DB::table('tx_stat_user')->where('user_id', $first->id)->value('u') === 14);
        $this->refused(fn () => (new StatUserJob(['id' => 1, 'rate' => 2],
            [$first->id => [1, 1], $second->id => [10, 20]], 'vmess'))->handle(), 'AUTHORITY_FENCE_STALE_EPOCH');
        $this->assertSame(14, (int) DB::table('tx_stat_user')->where('user_id', $first->id)->value('u'));
        $this->assertSame(0, DB::table('tx_stat_user')->where('user_id', $second->id)->count());
        $this->assertSame(0, DB::table('tx_authority_postcommit_intent')->count());
    }

    public function test_native_server_stat_commit_survives_prepare_refusing_separate_traffic_increment(): void
    {
        $this->identity();
        $id = DB::table('tx_server')->insertGetId(['type' => 'vmess', 'name' => 'identity stat fixture', 'rate' => 1,
            'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443, 'u' => 0, 'd' => 0]);
        $this->prepareAfterCommit(fn () => DB::table('tx_stat_server')->where('server_id', $id)->exists());
        $this->refused(fn () => (new StatServerJob(['id' => $id, 'rate' => 1], [1 => [7, 11]], 'vmess'))->handle(),
            'AUTHORITY_FENCE_STALE_EPOCH');
        $this->assertSame(7, (int) DB::table('tx_stat_server')->where('server_id', $id)->value('u'));
        $this->assertSame(11, (int) DB::table('tx_stat_server')->where('server_id', $id)->value('d'));
        $this->assertSame(0, (int) DB::table('tx_server')->where('id', $id)->value('u'));
        $this->assertSame(0, (int) DB::table('tx_server')->where('id', $id)->value('d'));
        $this->assertSame(0, DB::table('tx_authority_postcommit_intent')->count());
    }

    public function test_native_daily_statistics_cli_keeps_its_catch_boundary_after_prepare(): void
    {
        $this->identity();
        $this->assertSame(0, Artisan::call('txboard:statistics'));
        $this->assertSame(1, DB::table('tx_stat')->count());
        $before = $this->rows(['tx_stat']);
        $this->stop();
        $this->assertSame(0, Artisan::call('txboard:statistics'));
        $this->assertSame($before, $this->rows(['tx_stat']));
    }

    public function test_native_password_reset_cli_preserves_sessions_and_original_salt_semantics(): void
    {
        $this->identity();
        $user = $this->user(['password_salt' => 'saltfix001']);
        $session = $user->createToken('cli-session');
        $this->assertSame(0, Artisan::call('reset:password', ['email' => $user->email, 'password' => 'CliPassword88']));
        $this->assertStringContainsString('CliPassword88', Artisan::output());
        $this->assertTrue(password_verify('CliPassword88', $user->fresh()->password));
        $this->assertSame('saltfix001', $user->fresh()->password_salt);
        $this->assertNotNull($session->accessToken->fresh());
        $this->stop();
        $before = $user->fresh()->password;
        $this->refused(fn () => Artisan::call('reset:password', ['email' => $user->email, 'password' => 'BlockedPassword99']),
            'AUTHORITY_FENCE_STALE_EPOCH');
        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_confirmed_cli_user_rotation_retains_first_commit_when_prepare_refuses_remaining_users(): void
    {
        $this->identity();
        $first = $this->user();
        $second = $this->user();
        $original = [$first->token, $second->token];
        $this->prepareAfterCommit(fn () => $first->fresh()->token !== $original[0]);
        $this->refused(fn () => $this->artisan('reset:user')
            ->expectsConfirmation('确定要重置所有用户安全信息吗？', 'yes')->run(), 'AUTHORITY_FENCE_STALE_EPOCH');
        $this->assertNotSame($original[0], $first->fresh()->token);
        $this->assertSame($original[1], $second->fresh()->token);
    }

    public function test_isolated_install_entry_seeds_original_keys_and_bootstrap_creation_with_strict_outer_refusal(): void
    {
        $this->identity();
        $seed = new \ReflectionMethod(TxboardInstall::class, 'seedCoreSettings');
        $seed->invoke(app(TxboardInstall::class));
        $this->assertNotNull(SettingModel::where('name', 'app_name')->first());
        $this->assertNotNull(SettingModel::where('name', 'app_url')->first());
        $this->capture();
        $this->assertTrue(TxboardInstall::registerAdmin('bootstrap-identity@example.test', 'BootstrapPassword77'));
        $user = User::byEmail('bootstrap-identity@example.test')->firstOrFail();
        $this->assertTrue((bool) $user->is_admin);
        $creation = DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $user->id)->first();
        $this->assertSame('install.admin.create', $creation->source_kind);
        $this->assertSame('bootstrap-creation-shadow/v1', $creation->source_version);
        $this->assertSame(AuthorityIdentityInventory::SCOPE,
            DB::table('tx_authority_postcommit_intent')->where('legacy_user_id', $user->id)->value('scope'));
        try {
            DB::transaction(fn () => TxboardInstall::registerAdmin('forbidden-outer@example.test', 'BootstrapPassword77'));
            $this->fail('Captured bootstrap must reject a caller outer transaction.');
        } catch (\App\Services\Subscription\LegacyCaptureShadowException $error) {
            $this->assertSame('BOOTSTRAP_SHADOW_OUTER_TRANSACTION_UNSUPPORTED', $error->getMessage());
        }
        $this->assertNull(User::byEmail('forbidden-outer@example.test')->first());
    }

    public function test_registration_creation_anchor_precedes_commit_time_schedule_for_trial_and_no_trial(): void
    {
        $this->identity();
        $this->capture();
        $plan = $this->plan();
        $afterSchedule = [];
        HookManager::register('user.register.after', function (User $user) use (&$afterSchedule): void {
            $afterSchedule[$user->email] = $user->next_reset_at;
            $this->assertSame($user->fresh()->next_reset_at, $user->next_reset_at);
        });
        foreach ([false, true] as $trial) {
            admin_setting(['try_out_plan_id' => $trial ? $plan->id : 0, 'try_out_hour' => 24]);
            $email = ($trial ? 'trial' : 'plain').'-identity-anchor@example.test';
            $this->postJson('/txapi/auth/register', ['email' => $email, 'password' => 'FixturePassword77'])->assertCreated();
            $user = User::byEmail($email)->firstOrFail();
            $anchor = DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $user->id)->first();
            $initial = json_decode($anchor->initial_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $this->assertNull($initial['next_reset_at']);
            $event = DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->orderBy('sequence')->first();
            if ($trial) {
                $this->assertNotNull($event);
                $this->assertSame('user.schedule.observer', $event->source_kind);
                $this->assertSame($initial, json_decode($event->before_snapshot, true, flags: JSON_THROW_ON_ERROR));
                $this->assertSame($anchor->initial_hash, $event->before_hash);
            } else {
                $this->assertNull($event, 'No schedule mutation means no fabricated capture event.');
            }
            $this->assertSame($trial, $user->fresh()->next_reset_at !== null);
            $this->assertSame($trial, $afterSchedule[$email] !== null);
            $verified = app(LegacyUserCreationEvidence::class)->verify($user->id, 'user.register');
            $this->assertTrue($verified['evidence_valid']);
            if ($trial) {
                $this->assertTrue($verified['first_mutation_before_matches_initial']);
                $this->assertTrue(app(LegacyUserMutationCapture::class)->verify($user->id)['page_ok']);
            } else {
                $this->assertNull($verified['first_mutation_before_matches_initial']);
            }
            $intent = DB::table('tx_authority_postcommit_intent')->where('legacy_user_id', $user->id)->where('action', 'created')->first();
            $this->assertSame('5', (string) $intent->authority_epoch);
            $this->assertSame('user.register.create', $intent->writer);
            $this->assertSame(AuthorityIdentityInventory::VERSION, $intent->version);
        }
    }

    public function test_pending_old_v4_intent_can_handoff_under_declared_v5_without_rehashing_immutable_payload(): void
    {
        $this->native();
        $user = $this->user(['group_id' => 1]);
        $previousBus = Bus::getFacadeRoot();
        Bus::swap(new class {
            public function dispatch($job) { throw new \RuntimeException('Synthetic queue unavailable'); }
        });
        DB::transaction(function () use ($user): void {
            $this->fence()->admit('admin.user.update');
            $user->update(['group_id' => 2]);
        });
        $before = $this->rows(['tx_authority_postcommit_intent']);
        $intent = DB::table('tx_authority_postcommit_intent')->first();
        $this->assertSame(AuthorityNativeInventory::VERSION, $intent->version);
        $this->assertSame('unknown', DB::table('tx_authority_postcommit_delivery')->value('handoff_state'));
        $this->fence()->extendIdentity('identity-fixture', '4');
        config(['authority_writer_fence.worker_epoch' => '5', 'authority_writer_fence.worker_scope' => AuthorityIdentityInventory::SCOPE,
            'authority_writer_fence.worker_version' => AuthorityIdentityInventory::VERSION]);
        Bus::swap($previousBus);
        $report = app(AuthorityPostcommitHandoff::class)->run(1);
        $this->assertSame('handed_off', $report['results'][0]['handoff_status']);
        $this->assertFalse($report['node_delivery_verified']);
        $this->assertFalse($report['global_drain_complete']);
        Bus::assertDispatched(NodeUserSyncJob::class);
        $this->assertSame($before, $this->rows(['tx_authority_postcommit_intent']));
        $this->refused(fn () => AuthorityPostcommitIntent::findOrFail($intent->intent_id)->delete(), 'AUTHORITY_POSTCOMMIT_INTENT_IMMUTABLE');
    }

    public function test_register_commit_callback_failure_rolls_back_creation_capture_intents_and_actual_pdo(): void
    {
        $this->identity();
        $this->capture();
        config(['legacy_observer_capture.enabled' => 'invalid']);
        $this->postJson('/txapi/auth/register', ['email' => 'rolled-back-identity@example.test',
            'password' => 'FixturePassword77'])->assertStatus(409);
        $this->assertNull(User::byEmail('rolled-back-identity@example.test')->first());
        $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
        $this->assertSame(0, DB::table('tx_authority_postcommit_intent')->count());
        $this->assertSame(0, DB::table('tx_authority_postcommit_delivery')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        Bus::assertNothingDispatched();
    }

    public function test_closed_phase_enum_unknown_outer_and_expired_actual_admission_cannot_authorize_sql(): void
    {
        $this->identity();
        $phase = app(AuthorityIdentitySqlPhase::class);
        $entered = false;
        $this->refused(fn () => $phase->run('request.user.operation', function () use (&$entered): void { $entered = true; }),
            'AUTHORITY_IDENTITY_WRITER_INVALID');
        $this->assertFalse($entered);
        $user = $this->user();
        DB::transaction(function () use ($user): void {
            $this->refused(fn () => (new AuthService($user))->generateAuthData(), 'AUTHORITY_ADMISSION_REQUIRED');
        });
        DB::beginTransaction();
        $proof = $this->fence()->admit(AuthorityIdentitySqlPhase::SESSION_ISSUE);
        DB::rollBack();
        DB::beginTransaction();
        $this->refused(fn () => $proof->assertValid(), 'AUTHORITY_ADMISSION_LIFETIME_INVALID');
        $this->refused(fn () => (new AuthService($user))->generateAuthData(), 'AUTHORITY_ADMISSION_REQUIRED');
        DB::rollBack();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_native_mail_transport_success_precedes_refused_log_without_resend_or_false_delivery_certificate(): void
    {
        $this->identity();
        config(['mail.driver' => 'array']);
        Mail::purge();
        // The array transport emits the real Laravel MessageSent event after send.
        $sends = 0;
        Event::listen(MessageSent::class, function () use (&$sends): void { ++$sends; $this->stop(); });
        DB::table('tx_mail_templates')->insert(['name' => 'identity-fixture', 'subject' => 'Identity fixture',
            'content' => 'Synthetic acceptance content', 'created_at' => now(), 'updated_at' => now()]);
        $result = MailService::sendEmail(['email' => 'mail-identity@example.test', 'subject' => 'Identity fixture',
            'template_name' => 'identity-fixture', 'template_value' => []]);
        $this->assertSame(1, $sends);
        $this->assertNull($result['error']);
        $this->assertSame(0, DB::table('tx_mail_log')->count());
        $this->assertFalse($this->fence()->status()['global_drain_complete']);
    }

    public function test_default_off_native_http_cli_and_bearer_require_no_authority_data_or_receipt_io(): void
    {
        config(['authority_writer_fence' => ['enabled' => false, 'worker_epoch' => 'invalid', 'worker_role' => 'invalid'],
            'core_shadow.isolated_copy' => false]);
        foreach (['tx_authority_writer_fence', 'tx_authority_prepare_receipt', 'tx_authority_inventory_upgrade',
            'tx_authority_finance_upgrade', 'tx_authority_native_upgrade', 'tx_authority_identity_upgrade',
            'tx_authority_postcommit_delivery', 'tx_authority_postcommit_intent'] as $table) Schema::drop($table);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $email = 'default-off-identity@example.test';
        $response = $this->postJson('/txapi/auth/register', ['email' => $email, 'password' => 'FixturePassword77'])->assertCreated();
        Auth::forgetGuards();
        $this->withHeader('Authorization', $response->json('data.auth_data'))->getJson('/txapi/me')->assertOk();
        $user = User::byEmail($email)->firstOrFail();
        $this->assertNotNull($user->tokens()->first()->last_used_at);
        $this->assertSame(0, Artisan::call('reset:password', ['email' => $email, 'password' => 'DefaultOffPassword88']));
        app(Setting::class)->set('identity_default_off', 'fixture-value');
        app(Setting::class)->remove('identity_default_off');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            // Installation admission still checks for its persistent authority table.
            if (str_contains($query['query'], 'sqlite_master') || str_contains($query['query'], 'information_schema')) continue;
            $this->assertStringNotContainsString('tx_authority_', $query['query']);
        }
        $this->assertTrue(password_verify('DefaultOffPassword88', $user->fresh()->password));
    }

    private function capture(): void
    {
        config(['legacy_capture_shadow' => ['enabled' => true, 'run_key' => 'identity-capture',
            'billing_timezone' => 'UTC', 'currency' => 'USD'], 'legacy_observer_capture.enabled' => true,
            'legacy_registration_creation_shadow.enabled' => true, 'legacy_bootstrap_creation_shadow.enabled' => true]);
    }

    private function invite(User $user): InviteCode
    {
        $invite = new InviteCode();
        $invite->user_id = $user->id;
        $invite->code = 'identity-'.bin2hex(random_bytes(6));
        $invite->status = InviteCode::STATUS_UNUSED;
        $invite->pv = 0;
        $invite->saveOrFail();
        return $invite;
    }

    private function rows(array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) $rows[$table] = DB::table($table)->get()->toJson();
        return $rows;
    }

    /** Observe the real committed phase, then close authority before the next phase. */
    private function prepareAfterCommit(callable $predicate): void
    {
        $prepared = false;
        Event::listen(TransactionCommitted::class, function ($event) use ($predicate, &$prepared): void {
            if (!$prepared && $event->connection->getName() === $this->identityConnection()
                && DB::transactionLevel() === 0 && $predicate()) {
                $prepared = true;
                $this->stop();
            }
        });
    }
}
