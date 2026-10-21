<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Contracts\AccountAdministration;
use App\Domains\Identity\Contracts\AdminAccountListQuery;
use App\Domains\Identity\NativeAccountQueries;
use App\Domains\Identity\NativeIdentityRevision;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeAdminAccountWorkflowTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;

    public function test_batch_and_profile_recheck_current_session_and_grants_before_owner_replay(): void
    {
        $this->activate();
        $admin = $this->workflowActor(true, ['identity.accounts.manage']);
        $token = $this->authenticateWorkflowActor($admin);
        $target = $this->workflowActor();
        $owner = app(AccountAdministration::class);
        $batchKey = $this->workflowKey(); $profileKey = $this->workflowKey();
        $accounts = [['email' => 'authorized-once@example.test', 'password' => 'private-password']];
        $revision = NativeIdentityRevision::forUser($target);
        $changes = ['email' => 'authorized-profile@example.test'];
        $owner->createBatch($admin->id, $accounts, $batchKey);
        $owner->profile($admin->id, $target->id, $revision, $changes, $profileKey);
        $replays = [fn () => $owner->createBatch($admin->id, $accounts, $batchKey),
            fn () => $owner->profile($admin->id, $target->id, $revision, $changes, $profileKey)];
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        foreach ($replays as $replay) $this->workflowHttpFailure(403, $replay);
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id, 'permission' => 'identity.accounts.manage', 'created_at' => time()]);
        $token->delete();
        foreach ($replays as $replay) {
            try { $replay(); $this->fail('Deleted session replayed a permanent receipt'); }
            catch (\Illuminate\Auth\AuthenticationException) { $this->assertTrue(true); }
        }
        $this->assertSame(2, DB::table('tx_native_identity_admin_request')->count());
        $this->assertSame('authorized-profile@example.test', $target->fresh()->email);
    }

    public function test_batch_creation_is_atomic_and_replays_permanent_owner_receipts_without_storing_passwords(): void
    {
        $this->activate(); $admin = $this->workflowActor(true, ['identity.accounts.manage']); $this->authenticateWorkflowActor($admin); $owner = app(AccountAdministration::class);
        $key = $this->workflowKey();
        $input = [['email' => 'batch-b@example.test', 'password' => 'private-batch-password'], ['email' => 'batch-a@example.test', 'password' => 'private-other-password']];
        $first = $owner->createBatch($admin->id, $input, $key);
        $this->assertSame(2, $first['created_count']);
        $this->assertSame($first, $owner->createBatch($admin->id, $input, $key));
        $this->assertSame(2, User::query()->whereIn('email', array_column($input, 'email'))->count());
        foreach (User::query()->whereIn('email', array_column($input, 'email'))->get() as $user) {
            $this->assertFalse($user->is_admin); $this->assertNull($user->plan_id); $this->assertSame('', $user->uuid);
            $this->assertSame(0, (int) $user->balance);
        }
        $receipt = json_encode((array) DB::table('tx_native_identity_admin_request')->first());
        $this->assertStringNotContainsString('private-batch-password', $receipt);
        $this->assertStringNotContainsString('batch-a@example.test', $receipt);
        $this->expectExceptionMessage('NATIVE_ACCOUNT_IDEMPOTENCY_CONFLICT');
        $owner->createBatch($admin->id, [['email' => 'changed@example.test', 'password' => 'private-batch-password']], $key);
    }

    public function test_batch_conflicts_and_invalid_members_leave_no_partially_created_accounts(): void
    {
        $this->activate(); $admin = $this->workflowActor(true, ['identity.accounts.manage']); $this->authenticateWorkflowActor($admin); $owner = app(AccountAdministration::class);
        foreach ([
            [['email' => 'new-before-conflict@example.test', 'password' => 'valid-password'], ['email' => $admin->email, 'password' => 'valid-password']],
            [['email' => 'new-before-conflict@example.test', 'password' => 'valid-password'], ['email' => 'bad-email', 'password' => 'valid-password']],
            [['email' => 'new-before-conflict@example.test', 'password' => 'valid-password'], ['email' => 'NEW-BEFORE-CONFLICT@example.test', 'password' => 'valid-password']],
        ] as $accounts) {
            try { $owner->createBatch($admin->id, $accounts, $this->workflowKey()); $this->fail('Invalid batch accepted'); }
            catch (DomainException) { $this->assertFalse(User::byEmail('new-before-conflict@example.test')->exists()); }
        }
        $this->assertSame(0, DB::table('tx_native_identity_admin_request')->count());
    }

    public function test_profile_edit_uses_an_opaque_revision_revokes_sessions_and_preserves_other_authorities(): void
    {
        $this->activate(); $admin = $this->workflowActor(true, ['identity.accounts.manage']); $this->authenticateWorkflowActor($admin); $target = $this->workflowActor();
        DB::table('tx_user')->where('id', $target->id)->update(['uuid' => 'provider-owned-uuid', 'token' => 'provider-owned-token', 'balance' => 4321, 'commission_balance' => 123]);
        $target = $target->fresh(); $target->createToken('old-login-session');
        $owner = app(AccountAdministration::class); $oldRevision = NativeIdentityRevision::forUser($target); $key = $this->workflowKey();
        $changes = ['email' => 'RENAMED@example.test', 'password' => 'new-login-password'];
        $result = $owner->profile($admin->id, $target->id, $oldRevision, $changes, $key);
        $this->assertSame('renamed@example.test', $result['email']); $this->assertSame(1, $result['sessions_revoked']);
        $this->assertNotSame($oldRevision, $result['identity_revision']);
        $this->assertTrue(password_verify('new-login-password', $target->fresh()->getRawOriginal('password')));
        $this->assertSame($result, $owner->profile($admin->id, $target->id, $oldRevision, $changes, $key));
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame('provider-owned-uuid', $target->fresh()->uuid); $this->assertSame('provider-owned-token', $target->fresh()->token);
        $this->assertSame(4321, (int) $target->fresh()->balance); $this->assertSame(123, (int) $target->fresh()->commission_balance);
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $detail = app(NativeAccountQueries::class)->adminFind($target->id);
        $this->assertSame($result['identity_revision'], $detail['identity_revision']);
        foreach (['password', 'uuid', 'token', 'balance', 'commission_balance'] as $field) $this->assertArrayNotHasKey($field, $detail);
        $this->expectExceptionMessage('NATIVE_ACCOUNT_VERSION_CONFLICT');
        $owner->profile($admin->id, $target->id, $oldRevision, ['email' => 'stale@example.test'], $this->workflowKey());
    }

    public function test_protected_targets_ordinary_actors_and_secret_state_changes_cannot_bypass_profile_guards(): void
    {
        $this->activate(); $admin = $this->workflowActor(true, ['identity.accounts.manage']); $this->authenticateWorkflowActor($admin); $target = $this->workflowActor(); $owner = app(AccountAdministration::class);
        foreach ([[$admin->id, $admin, 'NATIVE_ACCOUNT_PROTECTED'], [$target->id, $target, 'NATIVE_ACCOUNT_ACTOR_REJECTED']] as [$actor, $user, $code]) {
            try { $owner->profile($actor, $user->id, NativeIdentityRevision::forUser($user), ['email' => 'blocked@example.test'], $this->workflowKey()); $this->fail('Guard bypassed'); }
            catch (DomainException $error) { $this->assertSame($code, $error->getMessage()); }
        }
        $revision = NativeIdentityRevision::forUser($target);
        DB::table('tx_user')->where('id', $target->id)->update(['password' => password_hash('concurrent-password', PASSWORD_DEFAULT)]);
        try { $owner->profile($admin->id, $target->id, $revision, ['email' => 'stale@example.test'], $this->workflowKey()); $this->fail('Concurrent credential change overwritten'); }
        catch (DomainException $error) { $this->assertSame('NATIVE_ACCOUNT_VERSION_CONFLICT', $error->getMessage()); }
        $this->assertSame(0, DB::table('tx_native_identity_admin_request')->count());
    }

    public function test_account_rules_filter_before_pagination_and_sort_stably_with_literal_like_characters(): void
    {
        $this->activate(); $admin = $this->workflowActor(true, ['identity.accounts.manage']); $this->authenticateWorkflowActor($admin); $owner = app(AccountAdministration::class);
        $owner->createBatch($admin->id, array_map(static fn ($email): array => ['email' => $email, 'password' => 'valid-password'],
            ['query-c@example.test', 'query-a@example.test', 'query-b@example.test', 'query%_literal@example.test']), $this->workflowKey());
        $query = app(NativeAccountQueries::class);
        $rules = [['field' => 'email', 'operator' => 'starts_with', 'value' => 'query-'], ['field' => 'banned', 'operator' => 'eq', 'value' => '0']];
        $first = $query->adminListing(new AdminAccountListQuery(1, 2, filters: $rules, sortBy: 'email', sortDirection: 'asc'));
        $second = $query->adminListing(new AdminAccountListQuery(2, 2, filters: $rules, sortBy: 'email', sortDirection: 'asc'));
        $this->assertSame(3, $first['meta']['total']);
        $this->assertSame(['query-a@example.test', 'query-b@example.test'], array_column($first['items'], 'email'));
        $this->assertSame(['query-c@example.test'], array_column($second['items'], 'email'));
        $literal = $query->adminListing(new AdminAccountListQuery(filters: [['field' => 'email', 'operator' => 'contains', 'value' => '%_']]));
        $this->assertSame(['query%_literal@example.test'], array_column($literal['items'], 'email'));
    }
}
