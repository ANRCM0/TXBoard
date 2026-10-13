<?php
namespace App\Domains\LegacyLinks;
use App\Models\User;
use Illuminate\Support\Facades\DB;
final class LinkService
{
    public const ATTRIBUTE='legacy_links.association';
    public const RENDER_ATTRIBUTE='legacy_links.render_association';
    public function observe(User $authenticated): LinkAssociation
    {
        return $this->safe(function()use($authenticated):LinkAssociation{
            $key=KeyMaterial::load();Canonical::require(DB::transactionLevel()===0,'LINK_OUTER_TRANSACTION_UNSUPPORTED');
            return DB::transaction(function()use($authenticated,$key):LinkAssociation{
                $source=$this->source((int)$authenticated->id,$key);
                Canonical::require(is_string($authenticated->getRawOriginal('token'))
                    && hash_equals($source['token_digest'],$key->digest($authenticated->getRawOriginal('token'))),'LINK_AUTHENTICATED_SOURCE_CHANGED');
                $row=DB::table('tx_legacy_subscription_link_shadow')->where('legacy_user_id',$authenticated->id)->sharedLock()->first($this->linkColumns());
                Canonical::require($row!==null,'LINK_MAPPING_MISSING');$this->validate($row,$source,$key);
                return new LinkAssociation(Canonical::id($row->id),$source['legacy_user_id'],$source['subscription_id'],$source['map_id'],$key->id,$key->generation);
            });
        });
    }
    public function backfill(string $run,string $mappingRun,int $limit=100,?int $retryUser=null):array
    {
        return $this->safe(function()use($run,$mappingRun,$limit,$retryUser):array{
            $key=KeyMaterial::load();$this->bounds($run,$limit,0);$this->runKey($mappingRun);
            Canonical::require(DB::transactionLevel()===0,'LINK_OUTER_TRANSACTION_UNSUPPORTED');
            return DB::transaction(function()use($run,$mappingRun,$limit,$retryUser,$key):array{
                $mapping=DB::table('tx_migration_checkpoint')->where('run_key',$mappingRun)->sharedLock()->first(['phase','high_water_user_id']);
                Canonical::require($mapping!==null&&$mapping->phase==='backfilled','LINK_MAPPING_RUN_INCOMPLETE');
                $high=Canonical::id($mapping->high_water_user_id,'2147483647',true);$context=$this->checkpointContext($key,$mappingRun,$high);
                DB::table('tx_legacy_link_checkpoint')->insertOrIgnore(['run_key'=>$run,'mapping_run'=>$mappingRun,'high_water_user_id'=>(int)$high,
                    'cursor_user_id'=>0,'processed_count'=>0,'phase'=>'backfilling','context'=>Canonical::json($context),'context_hash'=>Canonical::hash($context)]);
                $checkpoint=DB::table('tx_legacy_link_checkpoint')->where('run_key',$run)->lockForUpdate()->first($this->checkpointColumns());
                $this->checkpoint($checkpoint,$context,$mappingRun,$high);
                if($retryUser!==null){
                    Canonical::id($retryUser,'2147483647');Canonical::require($retryUser<=(int)$high,'LINK_RETRY_OUTSIDE_HIGH_WATER');
                    Canonical::require(DB::table('tx_legacy_link_exception')->where('run_key',$run)->where('legacy_user_id',$retryUser)->where('state','unresolved')->exists(),'LINK_RETRY_EXCEPTION_MISSING');
                    $ids=[$retryUser];
                }else{
                    $ids=$this->ids()->where('id','>',$checkpoint->cursor_user_id)->where('id','<=',(int)$high)->orderBy('id')->limit($limit)->pluck('id')->all();
                }
                $results=[];
                foreach($ids as$id){
                    try{
                        DB::transaction(function()use($id,$key):void{
                            $source=$this->source((int)$id,$key);$existing=DB::table('tx_legacy_subscription_link_shadow')->where('legacy_user_id',$id)->lockForUpdate()->first($this->linkColumns());
                            if($existing){$this->validate($existing,$source,$key);return;}
                            $row=$this->payload($source,$key,(string)time());$row['context']=Canonical::json($row['context']);DB::table('tx_legacy_subscription_link_shadow')->insert($row);
                        });
                        DB::table('tx_legacy_link_exception')->where('run_key',$run)->where('legacy_user_id',$id)->update(['state'=>'resolved']);
                        $results[]=['legacy_user_id'=>(string)$id,'status'=>'mapped'];
                    }catch(\Throwable$error){
                        $code=$error instanceof LinkFailure?$error->getMessage():'LINK_STORAGE_OR_EVIDENCE_FAILED';
                        $old=DB::table('tx_legacy_link_exception')->where('run_key',$run)->where('legacy_user_id',$id)->first(['attempts']);
                        DB::table('tx_legacy_link_exception')->updateOrInsert(['run_key'=>$run,'legacy_user_id'=>$id],
                            ['state'=>'unresolved','error_code'=>$code,'attempts'=>(int)($old?->attempts??0)+1]);
                        $results[]=['legacy_user_id'=>(string)$id,'status'=>'exception','error_code'=>$code];
                    }
                }
                $cursor=$retryUser===null&&$ids?(int)end($ids):(int)$checkpoint->cursor_user_id;
                $more=$this->ids()->where('id','>',$cursor)->where('id','<=',(int)$high)->exists();
                $blocked=DB::table('tx_legacy_link_exception')->where('run_key',$run)->where('state','unresolved')->exists();
                $phase=$more?'backfilling':($blocked?'blocked':'backfilled');
                DB::table('tx_legacy_link_checkpoint')->where('id',$checkpoint->id)->update(['cursor_user_id'=>$cursor,'phase'=>$phase,
                    'processed_count'=>(int)$checkpoint->processed_count+($retryUser===null?count($ids):0)]);
                return array_replace($this->report(),['read_only'=>false])+['run_key'=>$run,'phase'=>$phase,'cursor_user_id'=>(string)$cursor,'high_water_user_id'=>$high,
                    'high_water_changed'=>$this->ids()->where('id','>',(int)$high)->exists(),'results'=>$results];
            });
        });
    }
    public function reconcile(string $run,int $limit=100,int $after=0):array
    {
        return $this->safe(function()use($run,$limit,$after):array{
            $key=KeyMaterial::load();$this->bounds($run,$limit,$after);Canonical::require(DB::transactionLevel()===0,'LINK_OUTER_TRANSACTION_UNSUPPORTED');
            return DB::transaction(function()use($run,$limit,$after,$key):array{
                $cp=DB::table('tx_legacy_link_checkpoint')->where('run_key',$run)->sharedLock()->first($this->checkpointColumns());Canonical::require($cp!==null,'LINK_RUN_MISSING');
                $this->runKey($cp->mapping_run);
                $mapping=DB::table('tx_migration_checkpoint')->where('run_key',$cp->mapping_run)->sharedLock()->first(['phase','high_water_user_id']);
                Canonical::require($mapping!==null&&$mapping->phase==='backfilled','LINK_MAPPING_RUN_INCOMPLETE');
                $high=Canonical::id($mapping->high_water_user_id,'2147483647',true);
                $this->checkpoint($cp,$this->checkpointContext($key,$cp->mapping_run,$high),$cp->mapping_run,$high);
                Canonical::require($after<=(int)$high,'LINK_CURSOR_OUTSIDE_HIGH_WATER');
                $ids=$this->ids()->where('id','>',$after)->where('id','<=',(int)$high)->orderBy('id')->limit($limit+1)->pluck('id')->all();
                $more=count($ids)>$limit;$ids=array_slice($ids,0,$limit);$results=[];$issues=[];
                foreach($ids as$id){
                    try{
                        $source=$this->source((int)$id,$key);$row=DB::table('tx_legacy_subscription_link_shadow')->where('legacy_user_id',$id)->sharedLock()->first($this->linkColumns());
                        Canonical::require($row!==null,'LINK_MAPPING_MISSING');$this->validate($row,$source,$key);
                        $pending=DB::table('tx_legacy_link_exception')->where('run_key',$run)->where('legacy_user_id',$id)->where('state','unresolved')->sharedLock()->first(['id']);
                        Canonical::require($pending===null,'LINK_EXCEPTION_UNRESOLVED');
                        $results[]=['legacy_user_id'=>(string)$id,'status'=>'verified','link_id'=>Canonical::id($row->id),'subscription_id'=>$source['subscription_id']];
                    }catch(\Throwable$error){$code=$error instanceof LinkFailure?$error->getMessage():'LINK_STORAGE_OR_EVIDENCE_FAILED';$issues[]=['legacy_user_id'=>(string)$id,'code'=>$code];}
                }
                $late=$this->ids()->where('id','>',(int)$high)->exists();
                return $this->report()+['run_key'=>$run,'high_water_user_id'=>$high,'next_cursor'=>(string)($ids?end($ids):$after),
                    'complete'=>!$more,'page_consistent'=>$issues===[]&&!$late&&$cp->phase==='backfilled','high_water_changed'=>$late,
                    'results'=>$results,'issues'=>$issues,'checkpoint_phase'=>$cp->phase];
            });
        });
    }
    private function source(int $id,KeyMaterial $key):array
    {
        Canonical::id($id,'2147483647');
        $user=DB::table('tx_user')->where('id',$id)->sharedLock()->first(['id','token']);Canonical::require($user!==null,'LINK_USER_MISSING');
        Canonical::require(is_string($user->token),'LINK_TOKEN_INVALID');$digest=$key->digest($user->token);
        // Equality uses native collation; token bytes are never bound to a new adapter query.
        $collision=DB::table('tx_user as owner')->join('tx_user as duplicate',function($join):void{$join->on('owner.token','=','duplicate.token')->on('owner.id','<>','duplicate.id');})
            ->where('owner.id',$id)->sharedLock()->first(['duplicate.id']);Canonical::require($collision===null,'LINK_TOKEN_AMBIGUOUS');
        $map=DB::table('tx_legacy_subscription_map')->where('legacy_user_id',$id)->sharedLock()->first(['id','subscription_id','state','error_code']);
        Canonical::require($map!==null,'LINK_SUBSCRIPTION_MAP_MISSING');Canonical::require($map->state==='mapped'&&$map->error_code===null,'LINK_SUBSCRIPTION_MAP_UNVERIFIED');
        $mapId=Canonical::id($map->id);$subscriptionId=Canonical::id($map->subscription_id);
        $subs=DB::table('tx_subscription')->where('user_id',$id)->orderBy('id')->sharedLock()->limit(2)->get(['id','user_id']);
        Canonical::require(count($subs)===1&&Canonical::id($subs[0]->id)===$subscriptionId&&(string)$subs[0]->user_id===(string)$id,'LINK_SUBSCRIPTION_AMBIGUOUS');
        Canonical::require(DB::table('tx_legacy_subscription_exception')->where('legacy_user_id',$id)->where('state','unresolved')->sharedLock()->first(['legacy_user_id'])===null,'LINK_SUBSCRIPTION_EXCEPTION');
        return ['version'=>'legacy-link-source/v1','legacy_user_id'=>(string)$id,'subscription_id'=>$subscriptionId,'map_id'=>$mapId,'token_digest'=>$digest];
    }
    private function payload(array $source,KeyMaterial $key,string $captured):array
    {
        $row=['legacy_user_id'=>$source['legacy_user_id'],'subscription_id'=>$source['subscription_id'],'map_id'=>$source['map_id'],
            'key_id'=>$key->id,'key_generation'=>$key->generation,'token_digest'=>$source['token_digest'],'context'=>$key->context(),
            'context_hash'=>Canonical::hash($key->context()),'source_hash'=>Canonical::hash($source),'captured_at'=>$captured];
        return $row+['content_hash'=>Canonical::hash($row)];
    }
    private function validate(object $row,array $source,KeyMaterial $key):void
    {
        Canonical::id($row->id);$time=Canonical::id($row->captured_at);
        $expected=$this->payload($source,$key,$time);
        $actual=(array)$row;unset($actual['id'],$actual['content_hash']);
        foreach(['legacy_user_id','subscription_id','map_id','key_generation','captured_at']as$name)$actual[$name]=Canonical::id($actual[$name]);
        Canonical::require(is_string($actual['context'])&&strlen($actual['context'])<=2048,'LINK_EVIDENCE_INVALID');
        $actual['context']=Canonical::object($actual['context']);
        $expectedPayload=$expected;unset($expectedPayload['content_hash']);
        Canonical::require(Canonical::json($actual)===Canonical::json($expectedPayload)&&$row->content_hash===$expected['content_hash'],'LINK_SOURCE_OR_CONTEXT_CHANGED');
    }
    private function checkpointContext(KeyMaterial $key,string $mappingRun,string $high):array
    {return $key->context()+['mapping_run'=>$mappingRun,'high_water_user_id'=>$high];}
    private function checkpoint(object $row,array $context,string $mappingRun,string $high):void
    {
        Canonical::require($row->mapping_run===$mappingRun&&Canonical::id($row->high_water_user_id,'2147483647',true)===$high,
            'LINK_CHECKPOINT_CHANGED');
        Canonical::id($row->cursor_user_id,'2147483647',true);Canonical::id($row->processed_count,'9223372036854775807',true);
        Canonical::require((int)$row->cursor_user_id<=(int)$high&&in_array($row->phase,['backfilling','blocked','backfilled'],true)
            &&is_string($row->context)&&strlen($row->context)<=2048
            &&Canonical::json(Canonical::object($row->context))===Canonical::json($context)
            &&$row->context_hash===Canonical::hash($context),'LINK_CHECKPOINT_CHANGED');
    }
    private function linkColumns(): array
    {
        return ['id','legacy_user_id','subscription_id','map_id','key_id','key_generation','token_digest','context_hash',
            'source_hash','content_hash','captured_at',DB::raw('substr(context,1,2049) as context')];
    }
    private function checkpointColumns(): array
    {
        return ['id','run_key','mapping_run','high_water_user_id','cursor_user_id','processed_count','phase','context_hash',
            DB::raw('substr(context,1,2049) as context')];
    }
    private function ids():\Illuminate\Database\Query\Builder
    {
        return DB::query()->fromSub(DB::table('tx_user')->select('id')->union(DB::table('tx_legacy_subscription_map')->select('legacy_user_id as id'))
            ->union(DB::table('tx_legacy_subscription_link_shadow')->select('legacy_user_id as id')),'legacy_link_ids');
    }
    private function runKey(string $run):void{Canonical::require((bool)preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/D',$run),'LINK_RUN_INVALID');}
    private function bounds(string $run,int $limit,int $after):void{$this->runKey($run);Canonical::require($limit>=1&&$limit<=1000&&$after>=0&&$after<=2147483647,'LINK_BOUNDS_INVALID');}
    private function report():array{return ['read_only'=>true,'authority'=>'legacy','authorizes'=>false,'global_coverage_complete'=>false,'projection_applied'=>false,'migration_gate_open'=>false];}
    private function safe(callable $fn):mixed{try{return $fn();}catch(LinkFailure$error){throw $error;}catch(\Throwable){throw new LinkFailure('LINK_STORAGE_OR_EVIDENCE_FAILED');}}
}
