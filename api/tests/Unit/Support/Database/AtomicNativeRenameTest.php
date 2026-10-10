<?php

namespace Tests\Unit\Support\Database;

use App\Support\Database\AtomicNativeRename;
use PHPUnit\Framework\TestCase;

class AtomicNativeRenameTest extends TestCase
{
    private function plan(): array
    {
        return [
            'schemaVersion' => 1, 'kind' => 'native-table-cutover-plan',
            'executable' => true, 'requiresManualApproval' => false,
            'blockers' => [], 'proposedRenames' => [
                ['from' => 'v2_user', 'to' => 'tx_user'],
                ['from' => 'v2_order', 'to' => 'tx_order'],
            ],
        ];
    }

    public function test_builds_single_statement_and_reverse(): void
    {
        $this->assertSame(
            'RENAME TABLE `v2_user` TO `tx_user`, `v2_order` TO `tx_order`',
            AtomicNativeRename::sql($this->plan(), ['v2_user', 'v2_order'], 'up')
        );
        $this->assertSame(
            'RENAME TABLE `tx_user` TO `v2_user`, `tx_order` TO `v2_order`',
            AtomicNativeRename::sql($this->plan(), ['tx_user', 'tx_order'], 'down')
        );
    }

    public function test_rejects_blocked_plan(): void
    {
        $plan = $this->plan();
        $plan['blockers'] = ['Runtime not ready'];
        $this->expectException(\RuntimeException::class);
        AtomicNativeRename::sql($plan, ['v2_user', 'v2_order'], 'up');
    }

    public function test_rejects_occupied_target(): void
    {
        $this->expectException(\RuntimeException::class);
        AtomicNativeRename::sql($this->plan(), ['v2_user', 'v2_order', 'tx_user'], 'up');
    }

    public function test_rejects_unsafe_identifier(): void
    {
        $plan = $this->plan();
        $plan['proposedRenames'][0]['to'] = 'tx_user;DROP';
        $this->expectException(\InvalidArgumentException::class);
        AtomicNativeRename::sql($plan, ['v2_user', 'v2_order'], 'up');
    }
}
