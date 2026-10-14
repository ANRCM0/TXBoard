<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\AuthorityMigration\AuthorityIdentityInventory;
use App\Domains\AuthorityMigration\AuthorityTicketInventory;
use App\Domains\AuthorityMigration\AuthorityTicketSqlPhase;
use App\Domains\AuthorityMigration\AuthorityTicketUpgrade;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuthorityTicketFixture;
use Tests\TestCase;

/** Immutable evidence and closed declaration boundaries, separate from runtime coverage. */
class AuthorityTicketEvidenceTest extends TestCase
{
    use AuthorityTicketFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTicketDatabase();
        config(['authority_writer_fence.enabled' => false, 'core_shadow.isolated_copy' => true]);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => '1', 'worker_role' => 'legacy']]);
    }

    protected function tearDown(): void
    {
        $this->cleanupTicketDatabase();
        parent::tearDown();
    }

    public function test_exact_59_writer_extension_preserves_ancestry_replays_after_prepare7_and_recovers_with_bounded_selects(): void
    {
        $this->identity();
        $parentTables = ['tx_authority_inventory_upgrade', 'tx_authority_finance_upgrade',
            'tx_authority_native_upgrade', 'tx_authority_identity_upgrade'];
        $before = $this->rows($parentTables);
        $prior = (array) DB::table('tx_authority_writer_fence')->first();
        $result = $this->fence()->extendTicket('ticket-fixture', '5');
        $receipt = $result['ticket_receipt'];
        $this->assertFalse($result['replayed']);
        $this->assertSame('6', $result['authority_epoch']);
        $this->assertSame($prior['content_hash'], $receipt['prior_state_hash']);
        $this->assertSame(DB::table('tx_authority_identity_upgrade')->value('content_hash'), $receipt['parent_identity_upgrade_hash']);
        $this->assertSame($before, $this->rows($parentTables));
        $this->declare(6, AuthorityTicketInventory::SCOPE, AuthorityTicketInventory::VERSION);
        $status = $this->fence()->status();
        $this->assertSame(AuthorityTicketInventory::WRITERS, $status['covered_writers']);
        $this->assertCount(59, array_unique($status['covered_writers']));
        $this->assertSame('1', (string) DB::table('tx_authority_writer_fence')->value('id'));
        $replayed = $this->fence()->extendTicket('ticket-fixture', '5');
        $this->assertTrue($replayed['replayed']);
        $this->assertSame($receipt, $replayed['ticket_receipt']);
        $prepared = $this->fence()->prepare('ticket-stop', '6');
        $this->assertSame('7', $prepared['authority_epoch']);
        $this->assertSame($receipt['expanded_state_hash'], $prepared['receipt']['prior_state_hash']);
        $frozen = $this->authorityRows();
        $this->assertSame($receipt, $this->fence()->extendTicket('ticket-fixture', '5')['ticket_receipt']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->fence()->status();
        $recovered = $this->fence()->recoverTicket('ticket-fixture', '5');
        $recoveredPrepare = $this->fence()->recover('ticket-stop', '6');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('committed_ticket', $recovered['recovery_status']);
        $this->assertTrue($recovered['read_only']);
        $this->assertTrue($recoveredPrepare['read_only']);
        $this->assertCount(24, $queries);
        $ticketReads = 0;
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringStartsWith('select ', $sql);
            $this->assertStringNotContainsString('select *', $sql);
            $this->assertStringContainsString(str_contains($sql, 'tx_authority_writer_fence') && str_contains($sql, 'where') ? 'limit 1' : 'limit 2', $sql);
            if (str_contains($sql, 'tx_authority_ticket_upgrade')) ++$ticketReads;
        }
        $this->assertSame(3, $ticketReads);
        $this->assertSame($frozen, $this->authorityRows());
        $this->assertSame($before, $this->rows($parentTables));
        $this->refused(fn () => $this->fence()->extendTicket('different-ticket', '5'), 'AUTHORITY_TICKET_REQUEST_CONFLICT');
        foreach (['global_writer_coverage_complete', 'global_drain_complete', 'target_authority_active', 'migration_gate_open'] as $key) {
            $this->assertFalse($this->fence()->status()[$key]);
        }
    }

    public function test_same_numeric_epoch_does_not_reopen_prepared_v5_or_admit_ticket_only_owners(): void
    {
        $this->identity();
        $inconclusive = $this->fence()->recoverTicket('ticket-fixture', '5');
        $this->assertSame('inconclusive_no_committed_ticket', $inconclusive['recovery_status']);
        $this->assertNull($inconclusive['ticket_receipt']);
        foreach (array_diff(AuthorityTicketInventory::WRITERS, AuthorityIdentityInventory::WRITERS) as $writer) {
            $entered = false;
            $this->refused(fn () => app(AuthorityTicketSqlPhase::class)->run($writer, function () use (&$entered): void {
                $entered = true;
            }), 'AUTHORITY_FENCE_WRITER_INVALID');
            $this->assertFalse($entered);
        }
        $this->fence()->prepare('v5-stop', '5');
        $before = $this->authorityRows();
        $this->refused(fn () => $this->fence()->extendTicket('ticket-fixture', '5'), 'AUTHORITY_TICKET_EXTEND_REFUSED');
        $this->refused(fn () => $this->fence()->recoverTicket('ticket-fixture', '5'), 'AUTHORITY_TICKET_EXTEND_REFUSED');
        $this->assertSame($before, $this->authorityRows());
        $this->assertSame('6', $this->fence()->status()['authority_epoch']);
        $this->assertSame(AuthorityIdentityInventory::SCOPE, $this->fence()->status()['scope']);
        $this->assertSame(0, DB::table('tx_authority_ticket_upgrade')->count());
    }

    public function test_self_hashed_wrong_parent_and_ancestor_tampering_refuse_new_and_old_replays_without_repair(): void
    {
        $this->tickets();
        $ticket = (array) DB::table('tx_authority_ticket_upgrade')->first();
        $identity = (array) DB::table('tx_authority_identity_upgrade')->first();
        $wrong = $ticket;
        $wrong['parent_identity_upgrade_hash'] = str_repeat('0', 64);
        $this->rehash($wrong);
        DB::table('tx_authority_ticket_upgrade')->update($wrong);
        $before = $this->authorityRows();
        foreach ([fn () => $this->fence()->status(), fn () => $this->fence()->extendTicket('ticket-fixture', '5'),
            fn () => $this->fence()->recoverTicket('ticket-fixture', '5'),
            fn () => $this->fence()->extendIdentity('ticket-parent-identity', '4'),
            fn () => $this->fence()->recoverIdentity('ticket-parent-identity', '4')] as $call) {
            $this->refused($call, 'AUTHORITY_TICKET_EVIDENCE_INVALID');
            $this->assertSame($before, $this->authorityRows());
        }
        DB::table('tx_authority_ticket_upgrade')->update($ticket);
        $wrong = $identity;
        $wrong['parent_native_upgrade_hash'] = str_repeat('0', 64);
        $this->rehash($wrong);
        DB::table('tx_authority_identity_upgrade')->update($wrong);
        $before = $this->authorityRows();
        foreach ([fn () => $this->fence()->status(), fn () => $this->fence()->extendTicket('ticket-fixture', '5'),
            fn () => $this->fence()->recoverTicket('ticket-fixture', '5')] as $call) {
            $this->refused($call, 'AUTHORITY_IDENTITY_EVIDENCE_INVALID');
            $this->assertSame($before, $this->authorityRows());
        }
    }

    public function test_missing_or_extra_ticket_history_fails_closed_and_does_not_infer_recoverable_output_loss(): void
    {
        $this->tickets();
        $ticket = (array) DB::table('tx_authority_ticket_upgrade')->first();
        DB::table('tx_authority_ticket_upgrade')->delete();
        $before = $this->authorityRows();
        $this->refused(fn () => $this->fence()->recoverTicket('ticket-fixture', '5'), 'AUTHORITY_TICKET_EVIDENCE_INVALID');
        $this->refused(fn () => $this->fence()->extendNative('ticket-parent-native', '3'), 'AUTHORITY_TICKET_EVIDENCE_INVALID');
        $this->assertSame($before, $this->authorityRows());
        DB::table('tx_authority_ticket_upgrade')->insert($ticket);
        $ticket['upgrade_key'] = 'ticket-extra';
        DB::table('tx_authority_ticket_upgrade')->insert($ticket);
        $before = $this->authorityRows();
        $this->refused(fn () => $this->fence()->status(), 'AUTHORITY_TICKET_EVIDENCE_INVALID');
        $this->refused(fn () => $this->fence()->extendTicket('ticket-fixture', '5'), 'AUTHORITY_TICKET_EVIDENCE_INVALID');
        $this->assertSame($before, $this->authorityRows());
    }

    public function test_ticket_schema_replay_and_model_immutability_preserve_receipts_and_reject_wrong_shape(): void
    {
        $this->tickets();
        $before = $this->authorityRows();
        $receipt = AuthorityTicketUpgrade::findOrFail('ticket-fixture');
        $this->refused(fn () => $receipt->update(['expected_epoch' => 4]), 'AUTHORITY_TICKET_RECEIPT_IMMUTABLE');
        $this->refused(fn () => $receipt->delete(), 'AUTHORITY_TICKET_RECEIPT_IMMUTABLE');
        $migration = require database_path('migrations/2026_10_14_000007_extend_ticket_writer_authority.php');
        $migration->up();
        $this->assertSame($before, $this->authorityRows());
        try { $migration->down(); $this->fail('Populated receipt rollback must refuse.'); }
        catch (\RuntimeException $error) { $this->assertSame('AUTHORITY_TICKET_POPULATED_ROLLBACK_REFUSED', $error->getMessage()); }
        $this->assertSame($before, $this->authorityRows());
        // Deliberate isolated schema corruption, after proving rollback cannot erase history.
        Schema::drop('tx_authority_ticket_upgrade');
        Schema::create('tx_authority_ticket_upgrade', function ($table): void { $table->string('upgrade_key', 64)->nullable(); });
        try { $migration->up(); $this->fail('A wrong existing schema must never be accepted or replaced.'); }
        catch (\RuntimeException $error) { $this->assertSame('Shadow schema mismatch: tx_authority_ticket_upgrade.upgrade_key', $error->getMessage()); }
        $this->assertCount(1, Schema::getColumns('tx_authority_ticket_upgrade'));
    }

    public function test_every_old_prepared_parent_preserves_exact_history_and_cannot_be_reinterpreted_as_v6(): void
    {
        $versions = [
            1 => [\App\Domains\AuthorityMigration\AuthorityWriterFence::SCOPE, \App\Domains\AuthorityMigration\AuthorityWriterFence::VERSION],
            2 => [\App\Domains\AuthorityMigration\AuthorityInventory::SCOPE, \App\Domains\AuthorityMigration\AuthorityInventory::VERSION],
            3 => [\App\Domains\AuthorityMigration\AuthorityFinanceInventory::SCOPE, \App\Domains\AuthorityMigration\AuthorityFinanceInventory::VERSION],
            4 => [\App\Domains\AuthorityMigration\AuthorityNativeInventory::SCOPE, \App\Domains\AuthorityMigration\AuthorityNativeInventory::VERSION],
            5 => [AuthorityIdentityInventory::SCOPE, AuthorityIdentityInventory::VERSION],
        ];
        foreach ($versions as $version => [$scope, $protocol]) {
            config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => '1', 'worker_role' => 'legacy']]);
            $this->fence()->initialize();
            if ($version >= 2) $this->fence()->expand('ticket-parent-inventory', '1');
            if ($version >= 3) $this->fence()->extendFinance('ticket-parent-finance', '2');
            if ($version >= 4) $this->fence()->extendNative('ticket-parent-native', '3');
            if ($version >= 5) $this->fence()->extendIdentity('ticket-parent-identity', '4');
            $this->declare($version, $scope, $protocol);
            $this->fence()->prepare('old-parent-stop', (string) $version);
            $before = $this->authorityRows();
            $this->refused(fn () => $this->fence()->extendTicket('ticket-fixture', '5'), 'AUTHORITY_TICKET_EXTEND_REFUSED');
            $this->refused(fn () => $this->fence()->recoverTicket('ticket-fixture', '5'), 'AUTHORITY_TICKET_EXTEND_REFUSED');
            $this->assertSame($before, $this->authorityRows());
            $this->assertSame($scope, $this->fence()->status()['scope']);
            $this->assertSame((string) ($version + 1), $this->fence()->status()['authority_epoch']);
            foreach (array_reverse(array_keys($before)) as $table) DB::table($table)->delete();
        }
    }
    private function rehash(array &$row): void
    {
        foreach (['expected_epoch', 'expanded_epoch', 'prior_updated_at', 'captured_at'] as $key) $row[$key] = (string) $row[$key];
        unset($row['content_hash']);
        $row['content_hash'] = app(AuthorityTicketInventory::class)->hash($row);
    }
}