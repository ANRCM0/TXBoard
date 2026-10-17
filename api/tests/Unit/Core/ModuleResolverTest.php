<?php

namespace Tests\Unit\Core;

use App\Core\Kernel\Modules\CapabilityDefinition as Capability;
use App\Core\Kernel\Modules\CapabilityRequirement as Requirement;
use App\Core\Kernel\Modules\ModuleDefinition as Module;
use App\Core\Kernel\Modules\ModuleError;
use App\Core\Kernel\Modules\ModuleResolver;
use PHPUnit\Framework\TestCase;

final class ModuleResolverTest extends TestCase
{
    public function test_business_dependencies_do_not_create_startup_cycles_and_order_is_deterministic(): void
    {
        $billing = new Module('billing', '1.0.0', 'required-business',
            [new Capability('billing.payment', 'Example\\Payment')],
            [new Requirement('subscription.fulfillment')], required: true);
        $subscription = new Module('subscription', '1.0.0', 'required-business',
            [new Capability('subscription.fulfillment', 'Example\\Fulfillment')],
            [new Requirement('billing.payment')], required: true);
        $resolver = new ModuleResolver;
        $first = $resolver->compose([$billing, $subscription]);
        $second = $resolver->compose([$subscription, $billing]);
        self::assertSame(['billing', 'subscription'], array_column($first->modules, 'id'));
        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertSame(['subscription'], $first->providers('subscription.fulfillment'));
    }

    public function test_two_required_authorities_fail_without_selecting_a_winner(): void
    {
        $this->expectException(ModuleError::class);
        $this->expectExceptionMessage('MODULE_CAPABILITY_CONFLICT');
        (new ModuleResolver)->compose([
            new Module('one', '1.0.0', 'required-business', [new Capability('money.ledger', 'Example\\Ledger')], required: true),
            new Module('two', '1.0.0', 'required-business', [new Capability('money.ledger', 'Example\\Ledger')], required: true),
        ]);
    }

    public function test_optional_conflict_cannot_replace_a_required_authority(): void
    {
        $plan = (new ModuleResolver)->compose([
            new Module('replacement', '1.0.0', 'connector', [new Capability('money.ledger', 'Example\\Ledger')]),
            new Module('billing', '1.0.0', 'required-business', [new Capability('money.ledger', 'Example\\Ledger')], required: true),
        ]);
        self::assertSame(['billing'], $plan->providers('money.ledger'));
        self::assertSame(['replacement' => 'MODULE_CAPABILITY_CONFLICT'], $plan->unavailable);
    }

    public function test_incompatible_optional_provider_removes_its_transitive_dependents(): void
    {
        $plan = (new ModuleResolver)->compose([
            new Module('provider', '1.0.0', 'connector', [new Capability('notify.send', 'Example\\Notify')], minimumKernelApi: 2, maximumKernelApi: 2),
            new Module('first', '1.0.0', 'optional-business', [new Capability('content.post', 'Example\\Post')], [new Requirement('notify.send')]),
            new Module('second', '1.0.0', 'presentation', requires: [new Requirement('content.post')]),
        ]);
        self::assertSame([], $plan->modules);
        self::assertCount(3, $plan->unavailable);
        self::assertSame('MODULE_DEPENDENCY_UNAVAILABLE', $plan->unavailable['second']);
    }

    public function test_required_consumer_rejects_an_incompatible_capability_version(): void
    {
        $this->expectExceptionMessage('MODULE_DEPENDENCY_UNAVAILABLE');
        (new ModuleResolver)->compose([
            new Module('provider', '1.0.0', 'connector', [new Capability('notify.send', 'Example\\Notify', version: 2)]),
            new Module('consumer', '1.0.0', 'required-business', requires: [new Requirement('notify.send', 1, 1)], required: true),
        ]);
    }

    public function test_removing_an_optional_startup_cycle_rechecks_already_ordered_consumers(): void
    {
        $plan = (new ModuleResolver)->compose([
            new Module('a', '1.0.0', 'connector', [new Capability('a.service', 'Example\\A')], bootAfter: ['b']),
            new Module('b', '1.0.0', 'connector', bootAfter: ['a']),
            new Module('consumer', '1.0.0', 'optional-business', requires: [new Requirement('a.service')]),
        ]);
        self::assertSame([], $plan->modules);
        self::assertSame('MODULE_START_CYCLE', $plan->unavailable['a']);
        self::assertSame('MODULE_DEPENDENCY_UNAVAILABLE', $plan->unavailable['consumer']);
    }

    public function test_required_startup_cycle_fails(): void
    {
        $this->expectExceptionMessage('MODULE_START_CYCLE');
        (new ModuleResolver)->compose([
            new Module('a', '1.0.0', 'required-business', bootAfter: ['b'], required: true),
            new Module('b', '1.0.0', 'required-business', bootAfter: ['a'], required: true),
        ]);
    }
}
