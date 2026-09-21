<?php

namespace App\Console\Commands;

use App\Services\InstallState;
use Illuminate\Console\Command;

class XboardInstallStatus extends Command
{
    protected $signature = 'xboard:install-status';

    protected $description = 'Check whether TXBoard has a real installed database state';

    public function handle(InstallState $state): int
    {
        if ($state->isInstalled()) {
            $this->line('installed');
            return self::SUCCESS;
        }

        $this->line('not-installed');
        return self::FAILURE;
    }
}
