<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NativeNodeAuthorizationConfigTest extends TestCase
{
    public function test_config_file_parses_actual_environment_values_without_boolean_string_casts(): void
    {
        $name = 'NATIVE_NODE_AUTHORIZATION_ENABLED';
        $present = array_key_exists($name, $_ENV);
        $original = $_ENV[$name] ?? null;
        $serverPresent = array_key_exists($name, $_SERVER);
        $serverOriginal = $_SERVER[$name] ?? null;
        try {
            foreach (['true' => true, '(true)' => true, 'TRUE' => true, '1' => true, 'yes' => true,
                'false' => false, '(false)' => false, '0' => false, 'off' => false, 'unexpected' => false] as $raw => $expected) {
                $_ENV[$name] = (string) $raw;
                // Dotenv's ServerConstAdapter precedes EnvConstAdapter. A
                // loaded .env/process value must not shadow this test input.
                $_SERVER[$name] = (string) $raw;
                $parsed = require dirname(__DIR__, 2).'/config/native_node_authorization.php';
                self::assertSame($expected, $parsed['enabled'], (string) $raw);
                self::assertSame('native-node-authorization/v1', $parsed['policy_version']);
                self::assertSame(30, $parsed['refresh_interval_seconds']);
                self::assertSame(120, $parsed['max_lease_seconds']);
            }
        } finally {
            if ($present) { $_ENV[$name] = $original; }
            else { unset($_ENV[$name]); }
            if ($serverPresent) { $_SERVER[$name] = $serverOriginal; }
            else { unset($_SERVER[$name]); }
        }
    }
}
