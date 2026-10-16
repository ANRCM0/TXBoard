<?php

namespace Tests\Feature\Plugin;

use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginPackage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PluginIdentityBoundaryTest extends TestCase
{
    #[DataProvider('invalidPluginCodes')]
    public function test_invalid_identity_is_rejected_before_resolving_or_mutating_a_plugin(string $code): void
    {
        $manager = new PluginManager(new PluginPackage());
        foreach ([
            'getPluginNamespace', 'resolvePluginPath', 'getUserPluginPath', 'isCorePlugin',
            'install', 'enable', 'disable', 'uninstall', 'delete', 'update', 'publishAssets',
        ] as $method) {
            try {
                $manager->$method($code);
                $this->fail("{$method} must reject an invalid plugin identity.");
            } catch (InvalidArgumentException $error) {
                $this->assertSame('Invalid plugin code', $error->getMessage(), $method);
            }
        }
    }

    public static function invalidPluginCodes(): array
    {
        return [
            'empty' => [''],
            'uppercase database alias' => ['EPAY'],
            'trailing space database alias' => ['epay '],
            'trailing newline' => ["epay\n"],
            'null byte' => ["epay\0"],
            'relative path' => ['../epay'],
            'windows path' => ['..\\epay'],
            'namespace separator' => ['epay\\Plugin'],
        ];
    }

    public function test_current_bundled_and_fixture_codes_keep_their_canonical_paths(): void
    {
        $manager = new PluginManager(new PluginPackage());
        foreach ([
            'telegram' => 'Telegram',
            'epay' => 'Epay',
            'alipay_f2f' => 'AlipayF2f',
            'phase3_route_fixture' => 'Phase3RouteFixture',
        ] as $code => $directory) {
            $this->assertSame('Plugin\\' . $directory, $manager->getPluginNamespace($code));
            $this->assertSame(base_path('plugins') . '/' . $directory, $manager->getUserPluginPath($code));
        }
    }
}
