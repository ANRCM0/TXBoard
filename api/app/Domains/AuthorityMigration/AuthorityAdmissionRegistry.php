<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use WeakMap;

/** Scoped container lifetime; keys are actual transaction objects, not user IDs or process globals. */
final class AuthorityAdmissionRegistry
{
    private WeakMap $proofs;
    public function __construct(){$this->proofs=new WeakMap();}
    public function record(array $state,string $writer):AuthorityAdmission
    {
        $this->prune();$connection=DB::connection();$outer=$this->outer($connection);
        if($connection->transactionLevel()!==1)throw new AuthorityFenceException('AUTHORITY_NATIVE_NESTED_FIRST_ADMISSION_REFUSED');
        if(isset($this->proofs[$outer])){$this->proofs[$outer]->assertValid();return $this->proofs[$outer];}
        $proof=new AuthorityAdmission($connection,$connection->getPdo(),getmypid(),$outer,$writer,$state['scope'],$state['version'],$state['authority_epoch']);
        $proof->assertValid();$this->proofs[$outer]=$proof;return$proof;
    }
    public function current():AuthorityAdmission
    {
        $proof=$this->find(DB::connection());if(!$proof)throw new AuthorityFenceException('AUTHORITY_ADMISSION_REQUIRED');return$proof;
    }
    public function find(Connection $connection):?AuthorityAdmission
    {
        $this->prune();
        if(!$connection->transactionLevel())return null;
        $outer=$this->outer($connection);$proof=$this->proofs[$outer]??null;
        if($proof)$proof->assertValid();return$proof;
    }
    private function prune():void
    {
        // Each proof retains its own outer record; root admission must release ended records too.
        $pending=app('db.transactions')->getPendingTransactions();
        foreach($this->proofs as$record=>$proof)if(!$pending->contains(fn($active)=>$active===$record))unset($this->proofs[$record]);
    }
    private function outer(Connection $connection):\Illuminate\Database\DatabaseTransactionRecord
    {
        $record=app('db.transactions')->getPendingTransactions()->last(fn($item)=>$item->connection===$connection->getName()&&$item->level===1);
        if(!$record)throw new AuthorityFenceException('AUTHORITY_ADMISSION_TRANSACTION_RECORD_MISSING');return$record;
    }
}
