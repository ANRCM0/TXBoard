<?php

namespace App\Console\Commands;

use App\Core\Host\Modules\ModuleDeployment;
use Illuminate\Console\Command;

final class CoreModuleDeploy extends Command
{
    protected $signature = 'core:module-deploy {code} {action} {deployment_id} {--config-file=}';
    protected $description = 'Apply or resume the exact locked module deployment; retain the original deployment ID for recovery';

    public function handle(ModuleDeployment $deployment): int
    {
        try {
            $file = $this->option('config-file');
            $configuration = null;
            if ($file !== null) {
                if (!is_file($file) || filesize($file) > 32768) throw new \RuntimeException('MODULE_CONFIGURATION_INVALID');
                $configuration = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($configuration)) throw new \RuntimeException('MODULE_CONFIGURATION_INVALID');
            }
            $result = match ($this->argument('action')) {
                'inspect' => $deployment->inspect($this->argument('deployment_id')),
                'resume' => $deployment->resume($this->argument('code'), $this->argument('deployment_id')),
                default => $deployment->execute($this->argument('code'), $this->argument('action'), $this->argument('deployment_id'), $configuration),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('MODULE_DEPLOYMENT_INCOMPLETE: inspect the durable intent and resume with the same ID, package and configuration.');
            return self::FAILURE;
        }
    }
}
