<?php

namespace App\Console\Commands;

/**
 * @deprecated Use txboard:install.
 */
class XboardInstall extends TxboardInstall
{
    protected $signature = 'xboard:install';
    protected $description = 'Deprecated compatibility alias for txboard:install';
}
