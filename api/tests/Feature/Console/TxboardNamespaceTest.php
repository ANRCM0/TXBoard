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
            $this->assertDoesNotMatchRegularExpression('/^' . preg_quote($legacyCommand, '/') . '(?:\s|$)/m', $commands);
        }
    }

    public function test_txboard_system_theme_is_canonical(): void
    {
        $themes = app(ThemeService::class);

        $this->assertTrue($themes->exists('TXBoard'));
        $this->assertFalse($themes->exists('Xboard'));

        $path = str_replace('\\', '/', (string) $themes->getThemePath('TXBoard'));
        $this->assertStringEndsWith('/theme/TXBoard', $path);
    }
}
