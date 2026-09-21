<?php

namespace App\Console\Commands;

/**
 * @deprecated Use txboard:rollback.
 */
class XboardRollback extends TxboardRollback
{
    protected $signature = 'xboard:rollback';
    protected $description = 'Deprecated compatibility alias for txboard:rollback';
}
