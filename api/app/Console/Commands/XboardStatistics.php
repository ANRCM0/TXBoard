<?php

namespace App\Console\Commands;

/**
 * @deprecated Use txboard:statistics.
 */
class XboardStatistics extends TxboardStatistics
{
    protected $signature = 'xboard:statistics';
    protected $description = 'Deprecated compatibility alias for txboard:statistics';
}
