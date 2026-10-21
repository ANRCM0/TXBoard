<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\NativeAccountMetadata;
use App\Domains\Identity\NativeAccountQueries;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeAccountMetadataTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;

    public function test_metadata_rechecks_current_session_and_grant_before_replaying_notes(): void
    {
        $this->activate(); $admin = $this->workflowActor(true, ['identity.accounts.manage']);
        $token = $this->authenticateWorkflowActor($admin); $target = $this->workflowActor();
        $owner = app(NativeAccountMetadata::class); $key = $this->workflowKey();
        $revision = $owner->read($target->id)['metadata_revision'];
        $owner->update($admin->id, $target->id, $revision, 'authorized note', $key);
        $replay = fn () => $owner->update($admin->id, $target->id, $revision, 'authorized note', $key);
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $this->workflowHttpFailure(403, $replay);
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id, 'permission' => 'identity.accounts.manage', 'created_at' => time()]);
        $token->delete();
        try { $replay(); $this->fail('Deleted session replayed a metadata receipt'); }
        catch (\Illuminate\Auth\AuthenticationException) { $this->assertTrue(true); }
        $this->assertSame('authorized note', $owner->read($target->id)['remarks']);
        $this->assertSame(1, DB::table('tx_native_account_metadata_revision')->count());
    }

    public function test_notes_append_independent_revisions_replay_and_cannot_edit_protected_or_financial_state(): void
    {
        $this->activate(); $admin = $this->workflowActor(true, ['identity.accounts.manage']); $this->authenticateWorkflowActor($admin); $user = $this->workflowActor(); $metadata = app(NativeAccountMetadata::class);
        $initial = $metadata->read($user->id);
        $this->assertNull($initial['remarks']); $this->assertNull($initial['referral']);
        $key = $this->workflowKey();
        $first = $metadata->update($admin->id, $user->id, $initial['metadata_revision'], 'Support note', $key);
        $this->assertSame($first, $metadata->update($admin->id, $user->id, $initial['metadata_revision'], 'Support note', $key));
        $this->assertSame(1, DB::table('tx_native_account_metadata_revision')->count());
        $this->assertNotSame($initial['metadata_revision'], $first['metadata_revision']);
        $this->assertSame('Support note', app(NativeAccountQueries::class)->adminFind($user->id)['remarks']);
        try { $metadata->update($admin->id, $user->id, $initial['metadata_revision'], 'stale', $this->workflowKey()); $this->fail('Stale metadata revision accepted'); }
        catch (DomainException $error) { $this->assertSame('NATIVE_ACCOUNT_VERSION_CONFLICT', $error->getMessage()); }
        $next = $metadata->update($admin->id, $user->id, $first['metadata_revision'], null, $this->workflowKey());
        $this->assertNull($next['remarks']); $this->assertSame(2, DB::table('tx_native_account_metadata_revision')->count());
        $this->assertSame('Support note', DB::table('tx_native_account_metadata_revision')->where('version', 1)->value('remarks'));
        $this->assertSame(0, (int) $user->fresh()->balance); $this->assertSame('', $user->fresh()->uuid);
        try { $metadata->update($admin->id, $admin->id, $metadata->read($admin->id)['metadata_revision'], 'protected', $this->workflowKey()); $this->fail('Admin metadata mutated'); }
        catch (DomainException $error) { $this->assertSame('NATIVE_ACCOUNT_PROTECTED', $error->getMessage()); }
        DB::table('tx_native_account_metadata_revision')->where('user_id', $user->id)->where('version', 2)->update(['remarks' => 'tampered']);
        $this->expectExceptionMessage('NATIVE_ACCOUNT_METADATA_INVALID'); $metadata->read($user->id);
    }

    public function test_native_sponsor_is_projected_without_mutating_the_immutable_binding(): void
    {
        $this->activate(); $sponsor = $this->workflowActor(); $target = $this->workflowActor();
        $this->authenticateWorkflowActor($sponsor);
        $code = app(\App\Domains\Identity\NativeReferrals::class)->execute($sponsor->id, 'code', [], $this->workflowKey());
        $this->authenticateWorkflowActor($target);
        app(\App\Domains\Identity\NativeReferrals::class)->execute($target->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey());
        $before = (array) DB::table('tx_native_referral')->first();
        $result = app(NativeAccountMetadata::class)->read($target->id);
        $this->assertSame(['sponsor_id' => $sponsor->id, 'sponsor_email' => $sponsor->email, 'bound_at' => (int) $before['created_at']], $result['referral']);
        $this->assertSame($before, (array) DB::table('tx_native_referral')->first());
    }
}
