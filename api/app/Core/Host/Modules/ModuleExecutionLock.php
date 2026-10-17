<?php

namespace App\Core\Host\Modules;

use App\Extensions\Contracts\ExtensionFailure;
use Closure;
use Illuminate\Support\Facades\DB;

/** Serializes recoverable DDL/filesystem steps without holding a SQL transaction across them. */
final class ModuleExecutionLock
{
    public function run(string $code, Closure $work): mixed
    {
        if (DB::transactionLevel() !== 0) throw new ExtensionFailure('MODULE_TRANSACTION_BOUNDARY_REQUIRED');
        $connection = DB::connection();
        $key = 'tx-module-'.substr(hash('sha256', $connection->getDatabaseName().':composition'), 0, 48);
        if ($connection->getDriverName() === 'mysql') {
            $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key], false);
            if ((int) ($acquired->acquired ?? 0) !== 1) throw new ExtensionFailure('MODULE_DEPLOYMENT_BUSY');
            try { return $work(); }
            finally { $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key], false); }
        }
        if ($connection->getDriverName() !== 'sqlite') throw new ExtensionFailure('MODULE_DATABASE_UNSUPPORTED');
        $file = fopen(storage_path('framework/'.$key.'.lock'), 'c');
        if ($file === false) throw new ExtensionFailure('MODULE_DEPLOYMENT_BUSY');
        try {
            if (!flock($file, LOCK_EX | LOCK_NB)) throw new ExtensionFailure('MODULE_DEPLOYMENT_BUSY');
            return $work();
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
