<?php

namespace Tests\Feature\Console;

use App\Services\ThemeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TxboardNamespaceTest extends TestCase
{
    public function test_only_txboard_commands_are_registered(): void
    {
        Artisan::call('list', ['--raw' => true]);
        $commands = Artisan::output();

        foreach ([
            'txboard:install',
            'txboard:install-status',
            'txboard:update',
            'txboard:rollback',
            'txboard:statistics',
        ] as $command) {
            $this->assertStringContainsString($command, $commands);
        }

        foreach ([
            'xboard:install',
            'xboard:install-status',
            'xboard:update',
            'xboard:rollback',
            'xboard:statistics',
        ] as $legacyCommand) {
            $this->assertStringNotContainsString($legacyCommand, $commands);
        }
    }

    public function test_legacy_xboard_theme_name_resolves_to_txboard_system_theme(): void
    {
        $themes = app(ThemeService::class);

        $this->assertSame('TXBoard', $themes->normalizeThemeName('Xboard'));
        $this->assertTrue($themes->exists('Xboard'));

        $path = str_replace('\\', '/', (string) $themes->getThemePath('Xboard'));
        $this->assertStringEndsWith('/theme/TXBoard', $path);
    }
}
