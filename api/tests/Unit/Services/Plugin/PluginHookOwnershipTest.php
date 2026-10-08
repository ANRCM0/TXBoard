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
}
