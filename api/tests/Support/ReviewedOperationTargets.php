<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/** A reviewed snapshot, independent of runtime registry construction and binding helpers. */
final class ReviewedOperationTargets
{
    public static function assertMatches(TestCase $test, array $registry): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/native-operation-targets.v1.json'), true, 32, JSON_THROW_ON_ERROR);
        $test->assertSame('reviewed-native-operation-targets/v1', $fixture['schema']);
        $actual = [];
        foreach ($registry as $id => $spec) {
            $kind = $spec['handler'] !== null ? 'handler' : ($spec['command'] !== null ? 'command' : null);
            if ($kind === null) continue;
            $test->assertNull($spec['controller'], $id);
            $test->assertNull($spec[$kind === 'handler' ? 'command' : 'handler'], $id);
            $test->assertTrue((new \ReflectionMethod($spec[$kind], $spec['method']))->isPublic(), $id);
            $actual[$id] = [$kind, $spec[$kind], $spec['method'], $spec['actor'], $spec['domains'], $spec['mode'], $spec['permission']];
        }
        $expected = $fixture['targets']; ksort($actual); ksort($expected);
        $test->assertSame($expected, $actual, 'Review any owner target, actor, domain, mode or permission change explicitly.');
    }
}
