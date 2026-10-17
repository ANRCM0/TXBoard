<?php

namespace Tests\Unit\Core;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ModuleRuntime;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Core\Kernel\Modules\ModuleError;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

interface PublicPort {}
interface LockedPort {}
final class SharedOwner implements PublicPort, LockedPort {}

final class ModuleRuntimeTest extends TestCase
{
    public function test_public_and_internal_aliases_share_the_owner_and_reset_between_work_items(): void
    {
        $container = new Container;
        $runtime = new ModuleRuntime;
        $runtime->register($container, [$this->provider('owner', [
            new CapabilityDefinition('owner.public', PublicPort::class),
            new CapabilityDefinition('owner.locked', LockedPort::class, exported: false),
        ], [new ServiceBinding(PublicPort::class, SharedOwner::class), new ServiceBinding(LockedPort::class, SharedOwner::class)])]);
        $first = $container->make(PublicPort::class);
        self::assertSame($first, $container->make(LockedPort::class));
        self::assertSame($first, $container->make(SharedOwner::class));
        $container->forgetScopedInstances();
        self::assertNotSame($first, $container->make(PublicPort::class));
        self::assertSame($container->make(PublicPort::class), $container->make(LockedPort::class));
        self::assertCount(1, $runtime->inventory()['modules'][0]['provides']);
    }

    public function test_invalid_later_binding_does_not_mutate_the_container_or_publish_a_plan(): void
    {
        $container = new Container;
        $existing = new SharedOwner;
        $container->instance(PublicPort::class, $existing);
        $runtime = new ModuleRuntime;
        try {
            $runtime->register($container, [
                $this->provider('a', [new CapabilityDefinition('a.public', PublicPort::class)], [new ServiceBinding(PublicPort::class, SharedOwner::class)]),
                $this->provider('b', [new CapabilityDefinition('b.locked', LockedPort::class)], [new ServiceBinding(LockedPort::class, \stdClass::class)]),
            ]);
            self::fail('Invalid implementation was accepted');
        } catch (ModuleError $error) {
            self::assertSame('MODULE_BINDING_INVALID', $error->errorCode);
        }
        self::assertSame($existing, $container->make(PublicPort::class));
        self::assertFalse($container->bound(SharedOwner::class));
        $this->expectExceptionMessage('MODULE_COMPOSITION_NOT_REGISTERED');
        $runtime->plan();
    }

    public function test_undeclared_service_cannot_be_registered(): void
    {
        $this->expectExceptionMessage('MODULE_BINDING_INVALID');
        (new ModuleRuntime)->register(new Container, [$this->provider('module', [], [new ServiceBinding(PublicPort::class, SharedOwner::class)])]);
    }

    private function provider(string $id, array $capabilities, array $bindings): ModuleProvider
    {
        return new class($id, $capabilities, $bindings) implements ModuleProvider {
            public function __construct(private string $id, private array $capabilities, private array $services) {}
            public function definition(): ModuleDefinition
            {
                return new ModuleDefinition($this->id, '1.0.0', 'required-business', $this->capabilities, required: true);
            }
            public function bindings(): array { return $this->services; }
        };
    }
}
