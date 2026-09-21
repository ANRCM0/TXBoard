<?php

namespace App\Console\Commands;

/**
 * @deprecated Use txboard:update.
 */
class XboardUpdate extends TxboardUpdate
{
    protected $signature = 'xboard:update';
    protected $description = 'Deprecated compatibility alias for txboard:update';
}
