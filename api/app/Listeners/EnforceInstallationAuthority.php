<?php

namespace App\Listeners;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Domains\Installation\InstallationAuthorityException;
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
            $authority = app(InstallationAuthority::class);
            if ($authority->inspect()['mode'] !== 'native') { $authority->assertRuntime(); return; }
            $authority->assertNativeRuntime();
            if (!in_array($event->command, NativeRuntimeAdmission::COMMANDS, true)) {
                throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_NATIVE_COMMAND_UNAVAILABLE');
            }
        }
    }

    public function jobProcessing(JobProcessing $event): void
    {
        $authority = app(InstallationAuthority::class);
        if ($authority->inspect()['mode'] !== 'native') { $authority->assertRuntime(); return; }
        $authority->assertNativeRuntime();
        $payload = $event->job->payload();
        $class = $payload['data']['commandName'] ?? null;
        $serialized = $payload['data']['command'] ?? null;
        if (!is_string($class) || !in_array($class, NativeRuntimeAdmission::JOBS, true)
            || ($payload['job'] ?? null) !== 'Illuminate\Queue\CallQueuedHandler@call' || !is_string($serialized)
            || !str_starts_with($serialized, 'O:'.strlen($class).':"'.$class.'":')) {
            throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_NATIVE_JOB_UNAVAILABLE');
        }
    }
}
