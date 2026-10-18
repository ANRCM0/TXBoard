<?php

namespace Tests\Unit\Domains\InternalApi;

use App\Domains\InternalApi\AccountReadOperations;
use App\Domains\InternalApi\BillingReadOperations;
use App\Domains\InternalApi\CatalogReadOperations;
use App\Domains\InternalApi\CommerceMutationOperations;
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
                $session = str_starts_with($id, 'identity.session');
                $this->assertSame($session ? 'authenticated' : 'user', $spec['actor'], $id);
                $this->assertSame($session ? ['user', 'admin'] : ['user'], $spec['domains'], $id);
            }
        }
        $this->assertSame([
            'identity.account.get' => [AccountReadOperations::class, 'account', 'read'],
            'identity.admin.get' => [AccountReadOperations::class, 'account', 'read'],
            'identity.sessions.list' => [SessionReadOperations::class, 'listing', 'read'],
            'catalog.offers.list' => [CatalogReadOperations::class, 'listing', 'read'],
            'subscription.mine.get' => [AccountReadOperations::class, 'subscription', 'read'],
            'commerce.orders.list' => [OrderReadOperations::class, 'listing', 'read'],
            'commerce.orders.get' => [OrderReadOperations::class, 'find', 'read'],
            'billing.wallet.get' => [BillingReadOperations::class, 'wallet', 'read'],
            'billing.payment-methods.list' => [BillingReadOperations::class, 'methods', 'read'],
            'billing.recharges.list' => [BillingReadOperations::class, 'recharges', 'read'],
            'billing.recharges.get' => [BillingReadOperations::class, 'recharge', 'read'],
            'billing.payment-attempts.get' => [BillingReadOperations::class, 'attempt', 'read'],
            'usage.mine.list' => [UsageReadOperations::class, 'listing', 'read'],
        ], $handlers);
        $this->assertSame([
            'identity.sessions.revoke' => [SessionMutationOperations::class, 'revoke', 'workflow'],
            'identity.session.end' => [SessionMutationOperations::class, 'end', 'once'],
            'commerce.orders.create' => [CommerceMutationOperations::class, 'purchase', 'domain'],
            'commerce.orders.checkout' => [CommerceMutationOperations::class, 'checkout', 'domain'],
            'commerce.orders.cancel' => [CommerceMutationOperations::class, 'cancel', 'workflow'],
            'billing.recharges.create' => [CommerceMutationOperations::class, 'recharge', 'domain'],
            'billing.recharges.checkout' => [CommerceMutationOperations::class, 'rechargeCheckout', 'domain'],
            'billing.payment-attempts.query' => [CommerceMutationOperations::class, 'queryAttempt', 'domain'],
        ], $commands);
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
