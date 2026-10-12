<?php

namespace Plugin\CredentialProviderFixture;

use App\Services\Plugin\AbstractPlugin;
use RuntimeException;

/** Trusted test plugin; not installed or loaded by the application by default. */
final class Plugin extends AbstractPlugin
{
    public static int $bootCount = 0;
    public static int $cleanupCount = 0;
    public static bool $failCleanup = false;

    public static function resetFixture(): void
    {
        self::$bootCount = 0;
        self::$cleanupCount = 0;
        self::$failCleanup = false;
    }

    public function boot(): void
    {
        self::$bootCount++;
        $this->filter('fixture.plugin.boundary', static fn (int $value): int => $value + 1);
    }

    public function cleanup(): void
    {
        self::$cleanupCount++;
        if (self::$failCleanup) {
            throw new RuntimeException('Fixture cleanup failed');
        }
    }
}
