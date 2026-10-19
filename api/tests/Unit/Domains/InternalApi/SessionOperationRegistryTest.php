<?php

namespace Tests\Unit\Domains\InternalApi;

use App\Domains\InternalApi\OperationRegistry;
use PHPUnit\Framework\TestCase;

final class SessionOperationRegistryTest extends TestCase
{
    public function test_compiled_read_and_command_targets_are_closed_exact_maps(): void
    {
        \Tests\Support\ReviewedOperationTargets::assertMatches($this, OperationRegistry::all());
    }

    public function test_session_operations_do_not_expand_input_or_output_vocabulary(): void
    {
        $all = OperationRegistry::all();
        $this->assertSame([], $all['identity.sessions.list']['rules']);
        $this->assertTrue(\App\Domains\InternalApi\OperationSchemas::input('identity.sessions.revoke', ['session_id' => '1']));
        $this->assertFalse(\App\Domains\InternalApi\OperationSchemas::input('identity.sessions.revoke', ['session_id' => 1]));
        $this->assertSame([], $all['identity.session.end']['rules']);
        $this->assertSame(['ok'], $all['identity.sessions.revoke']['fields']);
        $this->assertSame(['ok'], $all['identity.session.end']['fields']);
        $this->assertSame(['id', 'name', 'abilities', 'last_used_at', 'created_at', 'updated_at', 'expires_at'],
            $all['identity.sessions.list']['fields']);
        $this->assertTrue($all['identity.sessions.list']['list']);
        $this->assertSame([], $all['identity.sessions.revoke']['parameters']);
        $this->assertSame([], $all['identity.session.end']['parameters']);
    }
}
