<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Support\Facades\DB;

/** Closed code inventory and immutable expansion receipt, never arbitrary writer names. */
final class AuthorityInventory
{
    public const SCOPE='native-sql-writers/v2';
    public const VERSION='authority-writer-fence/v2';
    public const WRITERS=['admin.user.create','admin.user.update','admin.user.delete','admin.user.credentials.rotate',
        'admin.user.bulk-ban','admin.plan.save','traffic.batch.settle'];
    public const COLUMNS=['upgrade_key','scope','version','prior_scope','prior_version','expected_epoch','expanded_epoch',
        'prior_updated_at','captured_at','inventory_hash','request_hash','prior_state_hash','expanded_state_hash','content_hash'];

    public function inventoryHash():string
    {return $this->hash(['scope'=>self::SCOPE,'version'=>self::VERSION,'writers'=>self::WRITERS]);}
    public function requestHash(string $key,string $epoch):string
    {return $this->hash(['operation'=>'expand','upgrade_key'=>$key,'expected_epoch'=>$epoch,'scope'=>self::SCOPE,
        'version'=>self::VERSION,'inventory_hash'=>$this->inventoryHash()]);}
    public function make(array $prior,array $expanded,string $key):array
    {
        $payload=['upgrade_key'=>$key,'scope'=>self::SCOPE,'version'=>self::VERSION,'prior_scope'=>AuthorityWriterFence::SCOPE,
            'prior_version'=>AuthorityWriterFence::VERSION,'expected_epoch'=>'1','expanded_epoch'=>'2',
            'prior_updated_at'=>$prior['updated_at'],'captured_at'=>$expanded['updated_at'],'inventory_hash'=>$this->inventoryHash(),
            'request_hash'=>$this->requestHash($key,'1'),'prior_state_hash'=>$prior['content_hash'],'expanded_state_hash'=>$expanded['content_hash']];
        return $payload+['content_hash'=>$this->hash($payload)];
    }
    public function verified(array $state):?array
    {
        $rows=DB::table('tx_authority_inventory_upgrade')->sharedLock()->limit(2)->get(self::COLUMNS);
        if($state['scope']===AuthorityWriterFence::SCOPE){$this->require($rows->isEmpty());return null;}
        $this->require($rows->count()===1);$receipt=(array)$rows->first();
        foreach(['expected_epoch','expanded_epoch','prior_updated_at','captured_at']as$key){
            $this->require(is_int($receipt[$key])||is_string($receipt[$key]));$receipt[$key]=(string)$receipt[$key];
            $this->require(preg_match('/\A[1-9][0-9]{0,18}\z/D',$receipt[$key])
                && (strlen($receipt[$key])<19||strcmp($receipt[$key],'9223372036854775807')<=0));
        }
        $this->require(is_string($receipt['upgrade_key'])&&preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}\z/D',$receipt['upgrade_key']));
        $this->require($receipt['scope']===self::SCOPE&&$receipt['version']===self::VERSION
            &&$receipt['prior_scope']===AuthorityWriterFence::SCOPE&&$receipt['prior_version']===AuthorityWriterFence::VERSION
            &&$receipt['expected_epoch']==='1'&&$receipt['expanded_epoch']==='2'&&$receipt['inventory_hash']===$this->inventoryHash()
            &&$receipt['request_hash']===$this->requestHash($receipt['upgrade_key'],'1'));
        $content=$receipt['content_hash'];unset($receipt['content_hash']);$this->require($content===$this->hash($receipt));
        $prior=['id'=>'1','scope'=>AuthorityWriterFence::SCOPE,'version'=>AuthorityWriterFence::VERSION,'authority'=>'legacy','phase'=>'open',
            'authority_epoch'=>'1','prepare_key'=>null,'row_version'=>'1','updated_at'=>$receipt['prior_updated_at']];
        $this->require($receipt['prior_state_hash']===$this->hash($prior));
        $expanded=$state;unset($expanded['content_hash']);$expanded['phase']='open';$expanded['authority_epoch']='2';
        $expanded['prepare_key']=null;$expanded['row_version']='2';$expanded['updated_at']=$receipt['captured_at'];
        $this->require($receipt['expanded_state_hash']===$this->hash($expanded));
        if($state['phase']==='open')$this->require($state['content_hash']===$receipt['expanded_state_hash']);
        return $receipt+['content_hash'=>$content];
    }
    public function hash(array $value):string
    {ksort($value,SORT_STRING);return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    private function require(bool|int $valid):void
    {if(!$valid)throw new AuthorityFenceException('AUTHORITY_INVENTORY_EVIDENCE_INVALID');}
}
