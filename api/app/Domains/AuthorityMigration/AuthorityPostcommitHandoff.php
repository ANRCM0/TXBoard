<?php

namespace App\Domains\AuthorityMigration;

use App\Jobs\NodeUserSyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Queue handoff only; fixed retries may enqueue duplicates and never attest Node delivery. */
final class AuthorityPostcommitHandoff
{
    public const MAX_ATTEMPTS=3;
    public const LEASE_SECONDS=15;
    public function handoff(string $id):array
    {
        $this->require(DB::transactionLevel()===0,'AUTHORITY_HANDOFF_OUTER_TRANSACTION_UNSUPPORTED');$this->context();
        $this->require(preg_match('/\A[0-9a-f]{32}\z/D',$id),'AUTHORITY_POSTCOMMIT_INTENT_INVALID');
        $claim=DB::transaction(function()use($id):array{
            $row=DB::table('tx_authority_postcommit_intent')->where('intent_id',$id)->sharedLock()->first(FencedUserPostcommit::COLUMNS);
            $this->require($row!==null,'AUTHORITY_POSTCOMMIT_INTENT_MISSING');$intent=app(FencedUserPostcommit::class)->decode($row);
            $delivery=DB::table('tx_authority_postcommit_delivery')->where('intent_id',$id)->lockForUpdate()->first();
            $this->require($delivery!==null&&$delivery->intent_hash===$intent['content_hash']
                &&in_array($delivery->schedule_state,['applied','not_required'],true)
                &&in_array($delivery->handoff_state,['pending','processing','handed_off','unknown'],true)
                &&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$delivery->attempts)&&(int)$delivery->attempts<=self::MAX_ATTEMPTS,'AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
            $this->require($delivery->schedule_hash===null||is_string($delivery->schedule_hash)&&preg_match('/\A[0-9a-f]{64}\z/D',$delivery->schedule_hash),'AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
            if($delivery->schedule_state==='applied')$this->require($delivery->schedule_hash!==null,'AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
            if($delivery->handoff_state==='processing')$this->require(is_string($delivery->lease_token)&&preg_match('/\A[0-9a-f]{32}\z/D',$delivery->lease_token)
                &&preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$delivery->lease_expires_at)&&(int)$delivery->attempts>0,'AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
            else $this->require($delivery->lease_token===null&&$delivery->lease_expires_at===null,'AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
            if($delivery->handoff_state==='pending')$this->require((int)$delivery->attempts===0,'AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
            if($delivery->handoff_state==='handed_off')return['status'=>'handed_off'];
            if($delivery->handoff_state==='processing'&&(int)$delivery->lease_expires_at>time())return['status'=>'processing'];
            if($delivery->handoff_state==='processing')DB::table('tx_authority_postcommit_delivery')->where('intent_id',$id)->update(['handoff_state'=>'unknown','lease_token'=>null,'lease_expires_at'=>null,'updated_at'=>time()]);
            if((int)$delivery->attempts>=self::MAX_ATTEMPTS)return['status'=>'unknown_attempt_limit'];
            $token=bin2hex(random_bytes(16));
            DB::table('tx_authority_postcommit_delivery')->where('intent_id',$id)->update(['handoff_state'=>'processing',
                'attempts'=>(int)$delivery->attempts+1,'lease_token'=>$token,'lease_expires_at'=>time()+self::LEASE_SECONDS,'updated_at'=>time()]);
            return['status'=>'claimed','token'=>$token,'intent'=>$intent];
        });
        if($claim['status']!=='claimed')return$this->result($id,$claim['status']);
        $intent=$claim['intent'];$status='handed_off';
        try{Bus::dispatch(new NodeUserSyncJob((int)$intent['legacy_user_id'],$intent['action'],$intent['old_group_id']===null?null:(int)$intent['old_group_id']));}
        catch(Throwable){$status='unknown';}
        // If dispatch committed then output/storage is lost, processing remains lease-bounded and becomes unknown/retryable.
        $updated=DB::table('tx_authority_postcommit_delivery')->where('intent_id',$id)->where('lease_token',$claim['token'])
            ->where('handoff_state','processing')->update(['handoff_state'=>$status,'lease_token'=>null,'lease_expires_at'=>null,'updated_at'=>time()]);
        $this->require($updated===1,'AUTHORITY_HANDOFF_LEASE_LOST');return$this->result($id,$status);
    }
    public function run(int $limit=100):array
    {
        $this->context();$this->require(DB::transactionLevel()===0&&$limit>=1&&$limit<=1000,'AUTHORITY_HANDOFF_BOUNDS_INVALID');
        $ids=DB::table('tx_authority_postcommit_delivery')->whereIn('schedule_state',['applied','not_required'])
            ->where(function($q){$q->whereIn('handoff_state',['pending','unknown'])->orWhere(function($q){$q->where('handoff_state','processing')->where('lease_expires_at','<=',time());});})
            ->orderBy('intent_id')->limit($limit+1)->pluck('intent_id')->all();$more=count($ids)>$limit;$ids=array_slice($ids,0,$limit);$results=[];
        foreach($ids as$id)$results[]=$this->handoff($id);
        return['results'=>$results,'complete'=>!$more,'queue_handoff_only'=>true,'node_delivery_verified'=>false,'global_drain_complete'=>false,'migration_gate_open'=>false];
    }
    private function context():void
    {
        $this->require(AuthorityPostcommitVersionPolicy::declared()&&app()->environment(['local','testing'])&&config('core_shadow.isolated_copy')===true,'AUTHORITY_HANDOFF_ISOLATED_REQUIRED');
        $state=app(AuthorityWriterFence::class)->status();
        $this->require(in_array($state['scope'],[AuthorityNativeInventory::SCOPE,AuthorityIdentityInventory::SCOPE,AuthorityTicketInventory::SCOPE],true),'AUTHORITY_HANDOFF_ISOLATED_REQUIRED');
    }
    private function result(string $id,string $status):array
    {return['intent_id'=>$id,'handoff_status'=>$status,'node_delivery_verified'=>false,'global_drain_complete'=>false,'migration_gate_open'=>false];}
    private function require(bool|int $valid,string $code):void{if(!$valid)throw new AuthorityFenceException($code);}
}
