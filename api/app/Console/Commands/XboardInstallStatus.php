<?php

namespace App\Console\Commands;

/**
 * @deprecated Use txboard:install-status.
 */
class XboardInstallStatus extends TxboardInstallStatus
{
    protected $signature = 'xboard:install-status';
    protected $description = 'Deprecated compatibility alias for txboard:install-status';
}
