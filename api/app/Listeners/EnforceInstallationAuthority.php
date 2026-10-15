<?php

namespace App\Listeners;

use App\Domains\Installation\InstallationAuthority;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;

final class EnforceInstallationAuthority
{
    // Recovery tools do not authorize business work or native activation.
    private const PREPARATION_COMMANDS = [
        'core:installation', 'core:plan-publish', 'core:provider-register',
        'migrate', 'migrate:status', 'list', 'help', 'about', 'route:list',
        'config:show', 'config:cache', 'config:clear', 'route:cache', 'route:clear',
        'view:cache', 'view:clear', 'event:cache', 'event:clear', 'event:list',
        'package:discover', 'key:generate', 'test',
        'storage:link', 'storage:unlink', 'txboard:assert-native-schema', 'txboard:install-status',
        'octane:start', 'octane:stop', 'octane:reload', 'octane:status',
        'octane:swoole', 'octane:roadrunner', 'octane:frankenphp',
    ];

    public function commandStarting(CommandStarting $event): void
    {
        if (!in_array($event->command, self::PREPARATION_COMMANDS, true)) {
            app(InstallationAuthority::class)->assertRuntime();
        }
    }

    public function jobProcessing(JobProcessing $event): void
    {
        app(InstallationAuthority::class)->assertRuntime();
    }
}
