<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Support\Facades\DB;

/** Fixed financial owners and immutable extension of the preceding inventory. */
final class AuthorityFinanceInventory
{
    public const SCOPE='native-sql-writers/v3';
    public const VERSION='authority-writer-fence/v3';
    public const WRITERS=[...AuthorityInventory::WRITERS,'order.create','order.paid','order.fulfill','order.cancel','order.checkout',
        'wallet.recharge.create','wallet.recharge.settle','commission.transfer','gift.redeem'];
    public const COLUMNS=['upgrade_key','scope','version','prior_scope','prior_version','expected_epoch','expanded_epoch',
        'prior_updated_at','captured_at','parent_inventory_upgrade_hash','inventory_hash','request_hash','prior_state_hash','expanded_state_hash','content_hash'];
    public function inventoryHash():string
    {return $this->hash(['scope'=>self::SCOPE,'version'=>self::VERSION,'writers'=>self::WRITERS]);}
    public function requestHash(string $key,string $epoch):string
    {return $this->hash(['operation'=>'extend-finance','upgrade_key'=>$key,'expected_epoch'=>$epoch,'scope'=>self::SCOPE,
        'version'=>self::VERSION,'inventory_hash'=>$this->inventoryHash()]);}
    public function make(array $prior,array $expanded,array $parent,string $key):array
    {
        $payload=['upgrade_key'=>$key,'scope'=>self::SCOPE,'version'=>self::VERSION,'prior_scope'=>AuthorityInventory::SCOPE,
            'prior_version'=>AuthorityInventory::VERSION,'expected_epoch'=>'2','expanded_epoch'=>'3',
            'prior_updated_at'=>$prior['updated_at'],'captured_at'=>$expanded['updated_at'],'parent_inventory_upgrade_hash'=>$parent['content_hash'],
            'inventory_hash'=>$this->inventoryHash(),'request_hash'=>$this->requestHash($key,'2'),
            'prior_state_hash'=>$prior['content_hash'],'expanded_state_hash'=>$expanded['content_hash']];
        return $payload+['content_hash'=>$this->hash($payload)];
    }
    /** Returns a verified prior state for the unchanged v2 validator, never rewriting its history. */
    public function inspect(array $state):array
    {
        $rows=DB::table('tx_authority_finance_upgrade')->sharedLock()->limit(2)->get(self::COLUMNS);
        if($state['scope']!==self::SCOPE){$this->require($rows->isEmpty());return['receipt'=>null,'prior_state'=>null];}
        $this->require($rows->count()===1);$receipt=(array)$rows->first();
        foreach(['expected_epoch','expanded_epoch','prior_updated_at','captured_at']as$key){
            $this->require(is_int($receipt[$key])||is_string($receipt[$key]));$receipt[$key]=(string)$receipt[$key];
            $this->require(preg_match('/\A[1-9][0-9]{0,18}\z/D',$receipt[$key])
                &&(strlen($receipt[$key])<19||strcmp($receipt[$key],'9223372036854775807')<=0));
        }
        $this->require(is_string($receipt['upgrade_key'])&&preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}\z/D',$receipt['upgrade_key']));
        foreach(['parent_inventory_upgrade_hash','inventory_hash','request_hash','prior_state_hash','expanded_state_hash','content_hash']as$key)
            $this->require(is_string($receipt[$key])&&preg_match('/\A[0-9a-f]{64}\z/D',$receipt[$key]));
        $this->require($receipt['scope']===self::SCOPE&&$receipt['version']===self::VERSION
            &&$receipt['prior_scope']===AuthorityInventory::SCOPE&&$receipt['prior_version']===AuthorityInventory::VERSION
            &&$receipt['expected_epoch']==='2'&&$receipt['expanded_epoch']==='3'&&$receipt['inventory_hash']===$this->inventoryHash()
            &&$receipt['request_hash']===$this->requestHash($receipt['upgrade_key'],'2'));
        $content=$receipt['content_hash'];unset($receipt['content_hash']);$this->require($content===$this->hash($receipt));
        $prior=['id'=>'1','scope'=>AuthorityInventory::SCOPE,'version'=>AuthorityInventory::VERSION,'authority'=>'legacy','phase'=>'open',
            'authority_epoch'=>'2','prepare_key'=>null,'row_version'=>'2','updated_at'=>$receipt['prior_updated_at'],
            'inventory_hash'=>app(AuthorityInventory::class)->inventoryHash()];
        $this->require($receipt['prior_state_hash']===$this->hash($prior));$prior['content_hash']=$receipt['prior_state_hash'];
        $expanded=$state;unset($expanded['content_hash']);$expanded['phase']='open';$expanded['authority_epoch']='3';
        $expanded['prepare_key']=null;$expanded['row_version']='3';$expanded['updated_at']=$receipt['captured_at'];
        $this->require($receipt['expanded_state_hash']===$this->hash($expanded));
        if($state['phase']==='open')$this->require($state['content_hash']===$receipt['expanded_state_hash']);
        return['receipt'=>$receipt+['content_hash'=>$content],'prior_state'=>$prior];
    }
    public function hash(array $value):string
    {ksort($value,SORT_STRING);return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    private function require(bool|int $valid):void
    {if(!$valid)throw new AuthorityFenceException('AUTHORITY_FINANCE_EVIDENCE_INVALID');}
}
