<?php

namespace Tests\Unit\Services\Plugin;

use App\Services\Plugin\HookManager;
use Tests\TestCase;

class PluginHookOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::reset();
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_disable_revokes_only_own_hooks_and_avoids_duplicate_registration(): void
    {
        HookManager::registerFilter('billing.amount', fn ($value) => $value + 1);
        $pluginFilter = static fn ($value) => $value * 3;

        HookManager::withOwner('sample', function () use ($pluginFilter): void {
            HookManager::registerFilter('billing.amount', $pluginFilter);
            HookManager::registerFilter('billing.amount', $pluginFilter);
        });
        $this->assertSame(9, HookManager::filter('billing.amount', 2));

        HookManager::removeOwner('sample');
        $this->assertSame(3, HookManager::filter('billing.amount', 2));
    }

    public function test_plugin_boot_exception_leaves_parent_context_intact(): void
    {
        try {
            HookManager::withOwner('broken', static function (): void {
                HookManager::register('payment.after', static fn () => null);
                throw new \RuntimeException('boot failed');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('boot failed', $e->getMessage());
        }

        HookManager::removeOwner('broken');
        $this->assertFalse(HookManager::hasHook('payment.after'));

        HookManager::register('payment.after', static fn () => null);
        $this->assertTrue(HookManager::hasHook('payment.after'));
    }

    public function test_callbacks_keep_their_owner_when_registering_nested_hooks_and_cannot_remove_other_owners(): void
    {
        $seen = [];
        HookManager::register('event.target', function () use (&$seen): void { $seen[] = 'host'; });
        HookManager::withOwner('other', static function () use (&$seen): void {
            HookManager::register('event.target', function () use (&$seen): void { $seen[] = 'other'; });
        });
        HookManager::withOwner('sample', static function () use (&$seen): void {
            HookManager::register('event.start', static function () use (&$seen): void {
                HookManager::remove('event.target');
                HookManager::register('event.target', function () use (&$seen): void { $seen[] = 'sample'; });
            });
        });
        HookManager::call('event.start');
        HookManager::call('event.target');
        $this->assertSame(['host', 'other', 'sample'], $seen);
        HookManager::removeOwner('sample');
        $seen = [];
        HookManager::call('event.target');
        $this->assertSame(['host', 'other'], $seen);
        $this->assertFalse(HookManager::hasHook('event.start'));
    }

    public function test_disable_during_dispatch_skips_removed_callbacks_and_restores_invocation_owner_on_throw(): void
    {
        $seen = [];
        HookManager::withOwner('disabled', static function () use (&$seen): void {
            HookManager::register('event.dispatch', function () use (&$seen): void { $seen[] = 'disabled'; }, 30);
        });
        HookManager::register('event.dispatch', static fn () => HookManager::removeOwner('disabled'), 10);
        HookManager::call('event.dispatch');
        $this->assertSame([], $seen);
        HookManager::withOwner('broken', static fn () => HookManager::registerFilter('value.filter', static function (): never {
            throw new \RuntimeException('callback failed');
        }));
        HookManager::withOwner('parent', function (): void {
            try { HookManager::filter('value.filter', 1); }
            catch (\RuntimeException $error) { $this->assertSame('callback failed', $error->getMessage()); }
            HookManager::register('event.parent', static fn () => null);
        });
        HookManager::removeOwner('broken');
        $this->assertTrue(HookManager::hasHook('event.parent'));
        HookManager::removeOwner('parent');
        $this->assertFalse(HookManager::hasHook('event.parent'));
    }

    public function test_diagnostics_never_serialize_callable_captures_and_reset_clears_ownership(): void
    {
        $secret = 'never-serialize-callback-captures';
        HookManager::withOwner('sample', static fn () => HookManager::register('event.audit', static fn () => $secret));
        $this->assertSame([['kind' => 'action', 'hook' => 'event.audit', 'priority' => 20, 'owner' => 'sample']], HookManager::describe());
        $this->assertStringNotContainsString($secret, json_encode(HookManager::describe(), JSON_THROW_ON_ERROR));
        HookManager::reset();
        $this->assertSame([], HookManager::describe());
        HookManager::register('event.host', static fn () => null);
        $this->assertNull(HookManager::describe()[0]['owner']);
    }
}
