<?php

namespace Tests\Unit\Services\Plugin;

use App\Services\Plugin\PluginVersionConstraint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PluginVersionConstraintTest extends TestCase
{
    #[DataProvider('requirements')]
    public function test_version_requirements(string $installed, string $requirement, bool $expected): void
    {
        $this->assertSame($expected, PluginVersionConstraint::matches($installed, $requirement));
    }

    public static function requirements(): array
    {
        return [
            ['1.0.0', '>=1.0.0', true],
            ['1.0.0', '>1.0.0', false],
            ['2.0.0', '<2.0.0', false],
            ['1.0.0', '=1.0.0', true],
            ['1.0.1', '1.0.0', false],
            ['1.2.5', '^1.2.0', true],
            ['2.0.0', '^1.2.0', false],
            ['0.2.9', '^0.2.1', true],
            ['0.3.0', '^0.2.1', false],
            ['1.4.9', '~1.4.0', true],
            ['1.5.0', '~1.4.0', false],
            ['1.0.0', '*', true],
        ];
    }

    public function test_unknown_version_syntax_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginVersionConstraint::matches('1.0.0', '>=tomorrow');
    }
}
