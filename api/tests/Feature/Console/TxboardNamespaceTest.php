<?php

namespace Tests\Feature\Console;

use App\Services\ThemeService;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
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
            'txboard:admin-path-reset',
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

        $this->assertSame('builtin-spa', $themes->getList()['TXBoard']['runtime']);
    }

    public function test_builtin_inventory_never_opens_a_database_connection_before_installation(): void
    {
        $root = base_path('storage/theme');
        File::partialMock()->shouldReceive('isDirectory')->with($root)->andReturnTrue();
        File::shouldReceive('directories')->with($root)->andReturn([$root.'/uninstalled-cli']);
        File::shouldReceive('isFile')->with($root.'/uninstalled-cli/txboard-theme.json')->andReturnFalse();
        $original = Model::getConnectionResolver();
        $unavailable = Mockery::mock(ConnectionResolverInterface::class);
        $unavailable->shouldReceive('connection')->never();
        Model::setConnectionResolver($unavailable);
        try {
            $inventory = app(ThemeService::class)->getList();
            $this->assertSame(['TXBoard'], array_keys($inventory));
            $this->assertSame('builtin-spa', $inventory['TXBoard']['runtime']);
        } finally {
            Model::setConnectionResolver($original);
        }
    }
}
