<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\Identity\AccountSqlPhase;
use App\Domains\AuthorityMigration\AuthorityIdentityUpgrade;
use App\Services\AuthService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthorityIdentityFixture;
use Tests\TestCase;

class AuthorityIdentityEvidenceTest extends TestCase
{
    use AuthorityIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureIdentityDatabase();
        config(['authority_writer_fence.enabled'=>false, 'core_shadow.isolated_copy'=>true]);
        $this->assertSame(0, Artisan::call('migrate', ['--force'=>true]));
        Bus::fake();
        config(['authority_writer_fence'=>['enabled'=>true, 'worker_epoch'=>'1', 'worker_role'=>'legacy']]);
    }

    protected function tearDown(): void
    {
        if ($this->identityMysql()) $this->clearIdentityDatabase();
        DB::purge($this->identityConnection());
        parent::tearDown();
    }

    public function test_identity_receipt_is_immutable_schema_replay_keeps_rows_and_populated_rollback_refuses(): void
    {
        $this->identity();
        $before=DB::table('tx_authority_identity_upgrade')->get()->toJson();
        $receipt=AuthorityIdentityUpgrade::findOrFail('identity-fixture');
        $this->refused(fn()=>$receipt->update(['expected_epoch'=>3]), 'AUTHORITY_IDENTITY_RECEIPT_IMMUTABLE');
        $this->refused(fn()=>$receipt->delete(), 'AUTHORITY_IDENTITY_RECEIPT_IMMUTABLE');
        $migration=require database_path('migrations/2026_10_14_000006_extend_identity_writer_authority.php');
        $migration->up();
        $this->assertSame($before, DB::table('tx_authority_identity_upgrade')->get()->toJson());
        try {
            $migration->down();
            $this->fail('Populated identity receipt cannot be dropped.');
        } catch (\RuntimeException $error) {
            $this->assertSame('AUTHORITY_IDENTITY_POPULATED_ROLLBACK_REFUSED', $error->getMessage());
        }
        $this->assertSame($before, DB::table('tx_authority_identity_upgrade')->get()->toJson());
    }

    public function test_corrupt_identity_parent_hash_refuses_status_and_actual_session_without_repair(): void
    {
        $this->identity();
        $user=$this->user();
        DB::table('tx_authority_identity_upgrade')->update(['parent_native_upgrade_hash'=>str_repeat('0',64)]);
        $before=DB::table('tx_authority_identity_upgrade')->get()->toJson();
        $this->refused(fn()=>$this->fence()->status(), 'AUTHORITY_IDENTITY_EVIDENCE_INVALID');
        $this->refused(fn()=>(new AuthService($user))->generateAuthData(), 'AUTHORITY_IDENTITY_EVIDENCE_INVALID');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame($before, DB::table('tx_authority_identity_upgrade')->get()->toJson());
    }

    public function test_malformed_enable_flag_never_bypasses_admission_and_old_unrelated_proof_cannot_revoke_sessions(): void
    {
        $this->native();
        $user=$this->user();
        $token=$user->createToken('synthetic-evidence-fixture');
        DB::transaction(function()use($user):void{
            $this->fence()->admit('admin.plan.flags');
            $this->refused(fn()=>(new AuthService($user))->removeAllSessions(), 'AUTHORITY_FENCE_WRITER_INVALID');
        });
        $this->assertNotNull($token->accessToken->fresh());
        $this->fence()->extendIdentity('identity-fixture','4');
        $this->identityDeclaration();
        foreach ([null, 'false', 0] as $flag) {
            config(['authority_writer_fence.enabled'=>$flag]);
            $entered=false;
            $this->refused(fn()=>app(AccountSqlPhase::class)->run(AccountSqlPhase::SESSION_ISSUE,
                function()use(&$entered):void{$entered=true;}), 'AUTHORITY_FENCE_ISOLATED_REQUIRED');
            $this->assertFalse($entered);
        }
        $this->assertSame(1, $user->tokens()->count());
    }

    private function identityDeclaration(): void
    {
        config(['authority_writer_fence.worker_epoch'=>'5',
            'authority_writer_fence.worker_scope'=>\App\Domains\AuthorityMigration\AuthorityIdentityInventory::SCOPE,
            'authority_writer_fence.worker_version'=>\App\Domains\AuthorityMigration\AuthorityIdentityInventory::VERSION]);
    }
}
