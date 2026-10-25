<?php

namespace App\Listeners;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Domains\Installation\InstallationAuthorityException;
use App\Core\Host\Execution\InvocationActivities;
use App\Core\Host\Execution\InvocationScope;
use App\Core\Kernel\Execution\ActorRef;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;

final class EnforceInstallationAuthority
{
    // Recovery tools do not authorize business work or native activation.
    private const PREPARATION_COMMANDS = [
        'core:installation', 'core:diagnose', 'core:native-prepare', 'core:modules', 'core:module-deploy', 'core:module-release', 'core:plan-publish', 'core:provider-register',
        'migrate', 'migrate:install', 'migrate:status', 'list', 'help', 'about', 'route:list',
        'config:show', 'config:cache', 'config:clear', 'route:cache', 'route:clear',
        'view:cache', 'view:clear', 'event:cache', 'event:clear', 'event:list',
        'package:discover', 'key:generate', 'test',
        'storage:link', 'storage:unlink', 'txboard:assert-native-schema', 'txboard:install-status',
        'octane:start', 'octane:stop', 'octane:reload', 'octane:status',
        'octane:swoole', 'octane:roadrunner', 'octane:frankenphp',
    ];

    public function commandStarting(CommandStarting $event): void
    {
        $scope = app(InvocationScope::class);
        $activities = app(InvocationActivities::class);
        $activities->begin($event->input, $scope,
            in_array($event->command, ['schedule:run', 'schedule:work'], true) ? 'scheduler' : 'cli');
        $scope->actor(new ActorRef('system', 'console'));
        try {
            $this->admitCommand($event, $scope);
        } catch (\Throwable $error) {
            $activities->end($event->input);
            throw $error;
        }
    }

    public function commandFinished(CommandFinished $event): void
    {
        app(InvocationActivities::class)->end($event->input);
    }

    private function admitCommand(CommandStarting $event, InvocationScope $scope): void
    {
        if (!in_array($event->command, self::PREPARATION_COMMANDS, true)) {
            $authority = app(InstallationAuthority::class);
            $state = $authority->inspect();
            if ($state['mode'] !== 'native') {
                $authority->assertRuntime();
            } else {
                $authority->assertNativeRuntime();
                if (!in_array($event->command, NativeRuntimeAdmission::COMMANDS, true)) {
                    throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_NATIVE_COMMAND_UNAVAILABLE');
                }
            }
            $scope->installation($state);
        }
    }

    public function jobProcessing(JobProcessing $event): void
    {
        $scope = app(InvocationScope::class);
        $activities = app(InvocationActivities::class);
        $activities->begin($event->job, $scope, 'worker');
        $scope->actor(new ActorRef('system', 'queue'));
        try {
            $this->admitJob($event, $scope);
        } catch (\Throwable $error) {
            $activities->end($event->job);
            throw $error;
        }
    }

    public function jobAttempted(JobAttempted $event): void
    {
        app(InvocationActivities::class)->end($event->job);
    }

    private function admitJob(JobProcessing $event, InvocationScope $scope): void
    {
        $authority = app(InstallationAuthority::class);
        $state = $authority->inspect();
        if ($state['mode'] !== 'native') {
            $authority->assertRuntime();
            $scope->installation($state);
            return;
        }
        $authority->assertNativeRuntime();
        $payload = $event->job->payload();
        $class = $payload['data']['commandName'] ?? null;
        $serialized = $payload['data']['command'] ?? null;
        if (!is_string($class) || !in_array($class, NativeRuntimeAdmission::JOBS, true)
            || ($payload['job'] ?? null) !== 'Illuminate\Queue\CallQueuedHandler@call' || !is_string($serialized)
            || !str_starts_with($serialized, 'O:'.strlen($class).':"'.$class.'":')) {
            throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_NATIVE_JOB_UNAVAILABLE');
        }
        $scope->installation($state);
    }
}
