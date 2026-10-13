<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;

/** Internal object-lifetime proof, never a client credential or numeric-depth flag. */
final class AuthorityAdmission
{
    public array $intents=[];
    private readonly \WeakReference $pdo;
    public function __construct(public readonly Connection $connection,object $pdo,
        public readonly int $pid,public readonly DatabaseTransactionRecord $outer,
        public readonly string $writer,public readonly string $scope,public readonly string $version,public readonly string $epoch)
    {$this->pdo=\WeakReference::create($pdo);}
    public function assertValid():void
    {
        $records=app('db.transactions')->getPendingTransactions();$pdo=$this->pdo->get();
        if($pdo===null||getmypid()!==$this->pid||DB::connection()!==$this->connection||$this->connection->getPdo()!==$pdo
            ||$this->connection->transactionLevel()<1||!$pdo->inTransaction()||!$records->contains(fn($record)=>$record===$this->outer))
            throw new AuthorityFenceException('AUTHORITY_ADMISSION_LIFETIME_INVALID');
    }
}
