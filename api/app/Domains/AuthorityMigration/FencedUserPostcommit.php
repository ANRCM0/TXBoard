<?php

namespace App\Domains\AuthorityMigration;

use App\Models\User;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\TrafficResetService;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;

/** Concrete model events stage independently; producer capture finishes before commit-time schedule SQL. */
final class FencedUserPostcommit
{
    public const COLUMNS=['intent_id','scope','version','authority_epoch','writer','event_sequence','legacy_user_id','action',
        'old_group_id','schedule_required','source_hash','captured_at','content_hash'];
    public function stage(User $user,string $action,?int $oldGroup,bool $schedule):void
    {
        $proof=app(AuthorityAdmissionRegistry::class)->current();$proof->assertValid();
        $this->require(count($proof->intents)<1000,'AUTHORITY_POSTCOMMIT_EVENT_LIMIT');
        $id=$this->integer($user->id,'2147483647');
        $this->require(in_array($action,['created','updated','deleted'],true),'AUTHORITY_POSTCOMMIT_INTENT_INVALID');
        if($oldGroup!==null)$this->integer($oldGroup,'2147483647',true);
        $row=['intent_id'=>bin2hex(random_bytes(16)),'scope'=>$proof->scope,'version'=>$proof->version,'authority_epoch'=>$proof->epoch,
            'writer'=>$proof->writer,'event_sequence'=>(string)(count($proof->intents)+1),'legacy_user_id'=>$id,'action'=>$action,
            'old_group_id'=>$oldGroup===null?null:(string)$oldGroup,'schedule_required'=>$schedule,
            'source_hash'=>$this->sourceHash($action==='deleted'?$user:User::query()->sharedLock()->findOrFail($id)),'captured_at'=>(string)time()];$row['content_hash']=$this->hash($row);
        DB::table('tx_authority_postcommit_intent')->insert($row);
        DB::table('tx_authority_postcommit_delivery')->insert(['intent_id'=>$row['intent_id'],'intent_hash'=>$row['content_hash'],
            'schedule_state'=>$schedule?'pending':'not_required','schedule_hash'=>null,'handoff_state'=>'pending','attempts'=>0,
            'lease_token'=>null,'lease_expires_at'=>null,'updated_at'=>time()]);
        $eventRecord=app('db.transactions')->getPendingTransactions()->last(fn($record)=>$record->connection===$proof->connection->getName());
        $this->require($eventRecord!==null,'AUTHORITY_ADMISSION_TRANSACTION_RECORD_MISSING');
        $proof->intents[$row['intent_id']]=$eventRecord;
    }
    public function handle(TransactionCommitting $event):void
    {
        try { $this->flush($event); }
        catch (\Throwable $error) {
            // Locked Laravel does not universally rollback failures raised in a committing listener.
            if ($event->connection->transactionLevel()>0 && $event->connection->getPdo()->inTransaction()) $event->connection->rollBack(0);
            throw $error;
        }
    }
    private function flush(TransactionCommitting $event):void
    {
        if(!AuthorityPostcommitVersionPolicy::declared()||$event->connection->transactionLevel()!==1)return;
        $proof=app(AuthorityAdmissionRegistry::class)->find($event->connection);if(!$proof)return;$proof->assertValid();
        foreach($proof->intents as$id=>$record){
            $row=DB::table('tx_authority_postcommit_intent')->where('intent_id',$id)->sharedLock()->first(self::COLUMNS);
            if(!$row){
                $active=app('db.transactions')->getPendingTransactions()->merge(app('db.transactions')->getCommittedTransactions());
                $this->require(!$active->contains(fn($item)=>$item===$record),'AUTHORITY_POSTCOMMIT_INTENT_MISSING');
                continue; // Its actual savepoint rolled back; it has no committed intent.
            }
            $intent=$this->decode($row);$delivery=DB::table('tx_authority_postcommit_delivery')->where('intent_id',$id)->lockForUpdate()->first();
            $this->require($delivery!==null&&$delivery->intent_hash===$intent['content_hash'],'AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
            if($intent['schedule_required']){
                $this->require($delivery->schedule_state==='pending','AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
                $current=User::query()->lockForUpdate()->find($intent['legacy_user_id']);
                if($current){
                    $capture=config('legacy_observer_capture.enabled')===false?null:app(LegacyUserMutationCapture::class);
                    $before=$capture?->beginObserverSchedule((int)$current->id);
                    User::withoutEvents(function()use($current):void{
                        $next=app(TrafficResetService::class)->calculateNextResetTime($current);
                        $current->next_reset_at=$next?->timestamp;$current->saveOrFail();
                    });$capture?->appendObserverSchedule($before);
                    $scheduleHash=$this->sourceHash($current);$scheduleState='applied';
                }else{$scheduleHash=$this->hash(['legacy_user_id'=>$intent['legacy_user_id'],'state'=>'removed_before_commit']);$scheduleState='not_required';}
                DB::table('tx_authority_postcommit_delivery')->where('intent_id',$id)->update(['schedule_state'=>$scheduleState,'schedule_hash'=>$scheduleHash,'updated_at'=>time()]);
            }else $this->require($delivery->schedule_state==='not_required','AUTHORITY_POSTCOMMIT_DELIVERY_INVALID');
            DB::afterCommit(function()use($id):void{
                try{app(AuthorityPostcommitHandoff::class)->handoff($id);}catch(\Throwable){\Illuminate\Support\Facades\Log::error('AUTHORITY_POSTCOMMIT_HANDOFF_UNKNOWN');}
            });
        }
    }
    public function decode(object $row):array
    {
        $payload=(array)$row;$hash=$payload['content_hash'];unset($payload['content_hash']);
        foreach(['authority_epoch','event_sequence','legacy_user_id','captured_at']as$key)$payload[$key]=$this->integer($payload[$key],$key==='legacy_user_id'?'2147483647':'9223372036854775807');
        if($payload['old_group_id']!==null)$payload['old_group_id']=$this->integer($payload['old_group_id'],'2147483647',true);
        $this->require(in_array($payload['schedule_required'],[true,false,0,1,'0','1'],true),'AUTHORITY_POSTCOMMIT_INTENT_INVALID');
        $payload['schedule_required']=(bool)$payload['schedule_required'];
        $this->require(AuthorityPostcommitVersionPolicy::valid($payload['scope'],$payload['version'],$payload['authority_epoch'],$payload['writer'])
            &&(int)$payload['event_sequence']<=1000
            &&in_array($payload['action'],['created','updated','deleted'],true)
            &&is_string($payload['intent_id'])&&preg_match('/\A[0-9a-f]{32}\z/D',$payload['intent_id'])
            &&is_string($payload['source_hash'])&&preg_match('/\A[0-9a-f]{64}\z/D',$payload['source_hash'])
            &&$hash===$this->hash($payload),'AUTHORITY_POSTCOMMIT_INTENT_INVALID');
        return$payload+['content_hash'=>$hash];
    }
    public function sourceHash(User $user):string
    {
        $source=[];$attributes=$user->getAttributes();
        foreach(LegacyUserMutationCapture::FIELDS as$key){$value=$attributes[$key]??null;$source[$key]=$key==='banned'?(bool)$value:($value===null?null:(string)$value);}
        return$this->hash($source);
    }
    public function hash(array $value):string{ksort($value,SORT_STRING);return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    private function integer(mixed $value,string $max,bool $zero=false):string
    {$this->require(is_int($value)||is_string($value),'AUTHORITY_POSTCOMMIT_INTENT_INVALID');$value=(string)$value;
        $this->require(preg_match($zero?'/\A(?:0|[1-9][0-9]*)\z/D':'/\A[1-9][0-9]*\z/D',$value)&&(strlen($value)<strlen($max)||strlen($value)===strlen($max)&&strcmp($value,$max)<=0),'AUTHORITY_POSTCOMMIT_INTENT_INVALID');return$value;}
    private function require(bool|int $valid,string $code):void{if(!$valid)throw new AuthorityFenceException($code);}
}
