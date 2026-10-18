<?php

namespace App\Console\Commands;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use Illuminate\Console\Command;
use Throwable;

final class CoreInstallation extends Command
{
    protected $signature = 'core:installation {operation} {--installation-id=} {--mode=} {--key=} {--expected-version=} {--configuration-digest=}';
    protected $description = 'Inspect, prepare and explicitly activate the verified native runtime';

    public function handle(InstallationAuthority $authority): int
    {
        try {
            $operation = $this->argument('operation');
            $id = $this->option('installation-id');
            $mode = $this->option('mode');
            $key = $this->option('key');
            if ($operation === 'activate' && $id === null && $mode === null && is_string($key)) {
                $result = $authority->activate($key, (string) $this->option('expected-version'), (string) $this->option('configuration-digest'));
            } elseif ($operation === 'runtime' && $id === null && $mode === null && $key === null) {
                $result = $authority->runtimeReport();
            } elseif ($operation === 'preflight' && $id === null && $mode === null && $key === null) {
                $result = $authority->readiness();
            } elseif ($operation === 'status' && $id === null && $mode === null && $key === null) {
                $result = $authority->inspect();
            } elseif ($operation === 'native-preparing' && $id === null && $mode === null && $key === null) {
                $state = $authority->inspect();
                return $state['initialized'] && $state['mode'] === 'native'
                    && $state['phase'] === 'initializing' ? self::SUCCESS : self::FAILURE;
            } elseif ($operation === 'native-managed' && $id === null && $mode === null && $key === null) {
                $state = $authority->inspect();
                return $state['initialized'] && $state['mode'] === 'native' ? self::SUCCESS : self::FAILURE;
            } elseif ($operation === 'init' && is_string($id) && is_string($mode) && is_string($key)) {
                $result = $authority->initialize($id, $mode, $key);
            } else {
                $this->line('{"error_code":"INSTALLATION_AUTHORITY_COMMAND_INVALID"}');
                return self::FAILURE;
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->line(json_encode(['error_code' => $error instanceof InstallationAuthorityException
                ? $error->getMessage() : 'INSTALLATION_AUTHORITY_STORAGE_FAILED']));
            return self::FAILURE;
        }
    }
}
