<?php

namespace Tests\Unit\Domains\InternalApi;

use App\Domains\InternalApi\AccountReadOperations;
use App\Domains\InternalApi\OperationRegistry;
use App\Domains\InternalApi\OrderReadOperations;
use App\Domains\InternalApi\SessionMutationOperations;
use App\Domains\InternalApi\SessionReadOperations;
use App\Domains\InternalApi\UsageReadOperations;
use PHPUnit\Framework\TestCase;

final class SessionOperationRegistryTest extends TestCase
{
    public function test_compiled_read_and_command_targets_are_closed_exact_maps(): void
    {
        $handlers = [];
        $commands = [];
        foreach (OperationRegistry::all() as $id => $spec) {
            $this->assertArrayHasKey('command', $spec);
            if ($spec['handler'] !== null) {
                $handlers[$id] = [$spec['handler'], $spec['method'], $spec['mode']];
                $this->assertNull($spec['controller'], $id);
                $this->assertNull($spec['command'], $id);
                $this->assertSame('read', $spec['mode'], $id);
            }
            if ($spec['command'] !== null) {
                $commands[$id] = [$spec['command'], $spec['method'], $spec['mode']];
                $this->assertNull($spec['controller'], $id);
                $this->assertNull($spec['handler'], $id);
                $this->assertSame('authenticated', $spec['actor'], $id);
                $this->assertSame(['user', 'admin'], $spec['domains'], $id);
            }
        }
        $this->assertSame([
            'identity.account.get' => [AccountReadOperations::class, 'account', 'read'],
            'identity.admin.get' => [AccountReadOperations::class, 'account', 'read'],
            'identity.sessions.list' => [SessionReadOperations::class, 'listing', 'read'],
            'subscription.mine.get' => [AccountReadOperations::class, 'subscription', 'read'],
            'commerce.orders.list' => [OrderReadOperations::class, 'listing', 'read'],
            'commerce.orders.get' => [OrderReadOperations::class, 'find', 'read'],
            'usage.mine.list' => [UsageReadOperations::class, 'listing', 'read'],
        ], $handlers);
        $this->assertSame([
            'identity.sessions.revoke' => [SessionMutationOperations::class, 'revoke', 'workflow'],
            'identity.session.end' => [SessionMutationOperations::class, 'end', 'once'],
        ], $commands);
    }

    public function test_session_operations_do_not_expand_input_or_output_vocabulary(): void
    {
        $all = OperationRegistry::all();
        $this->assertSame([], $all['identity.sessions.list']['rules']);
        $this->assertSame(['session_id'], array_keys($all['identity.sessions.revoke']['rules']));
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
