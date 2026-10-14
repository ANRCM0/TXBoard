<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Only the named native SQL writers; no global or production authority publication. */
final class AuthorityWriterFence
{
    public const SCOPE = 'admin-user-editor/v1';
    public const VERSION = 'authority-writer-fence/v1';
    private const STATE_COLUMNS = ['id','scope','version','authority','phase','authority_epoch','prepare_key','row_version','updated_at','content_hash','inventory_hash'];
    private const RECEIPT_COLUMNS = ['prepare_key','scope','version','expected_epoch','prepared_epoch','request_hash','prior_state_hash','prepared_state_hash','captured_at','content_hash'];

    public function admit(string $writer): ?AuthorityAdmission
    {
        if (config('authority_writer_fence.enabled') === false) return null;
        return $this->safe(function () use ($writer): ?AuthorityAdmission {
            $this->context();
            $this->require(DB::transactionLevel() > 0, 'AUTHORITY_FENCE_TRANSACTION_REQUIRED');
            if(DB::transactionLevel()>1){
                $proof=app(AuthorityAdmissionRegistry::class)->current();
                $this->worker(['scope'=>$proof->scope,'version'=>$proof->version]);
                $this->require($proof->epoch===$this->epoch(config('authority_writer_fence.worker_epoch')),'AUTHORITY_FENCE_STALE_EPOCH');
                $this->require(in_array($writer,$this->writers(['scope'=>$proof->scope]),true),'AUTHORITY_FENCE_WRITER_INVALID');
                return $proof;
            }
            $state = $this->state(false); $this->evidence($state); $this->worker($state);
            $this->require(in_array($writer, $this->writers($state), true), 'AUTHORITY_FENCE_WRITER_INVALID');
            $this->require(config('authority_writer_fence.worker_role') === 'legacy', 'AUTHORITY_FENCE_ROLE_REJECTED');
            $this->require($state['authority_epoch'] === $this->epoch(config('authority_writer_fence.worker_epoch')), 'AUTHORITY_FENCE_STALE_EPOCH');
            $this->require($state['phase'] === 'open', 'AUTHORITY_FENCE_PREPARED');
            return app(AuthorityAdmissionRegistry::class)->record($state,$writer);
        });
    }

    public function initialize(): array
    {
        return $this->control(function (): array {
            $row = DB::table('tx_authority_writer_fence')->where('id', 1)->lockForUpdate()->first(self::STATE_COLUMNS);
            if ($row === null) {
                $this->require(DB::table('tx_authority_writer_fence')->lockForUpdate()->first(['id']) === null
                    && DB::table('tx_authority_prepare_receipt')->lockForUpdate()->first(['prepare_key']) === null
                    && DB::table('tx_authority_inventory_upgrade')->lockForUpdate()->first(['upgrade_key']) === null
                    && DB::table('tx_authority_finance_upgrade')->lockForUpdate()->first(['upgrade_key']) === null
                    && DB::table('tx_authority_native_upgrade')->lockForUpdate()->first(['upgrade_key']) === null
                    && DB::table('tx_authority_identity_upgrade')->lockForUpdate()->first(['upgrade_key']) === null, 'AUTHORITY_FENCE_STATE_INVALID');
                $state = ['id'=>'1','scope'=>self::SCOPE,'version'=>self::VERSION,'authority'=>'legacy','phase'=>'open',
                    'authority_epoch'=>'1','prepare_key'=>null,'row_version'=>'1','updated_at'=>(string)time()];
                $state['content_hash'] = $this->hash($state); DB::table('tx_authority_writer_fence')->insert($state);
            }
            $state = $this->state(true); $this->evidence($state);
            return $this->result($state) + ['initialized' => true];
        });
    }

    public function prepare(string $key, string $expectedEpoch): array
    {
        $this->key($key); $expectedEpoch = $this->epoch($expectedEpoch);
        return $this->control(function () use ($key, $expectedEpoch): array {
            $state = $this->state(true); $history = $this->evidence($state); $this->worker($state);
            $receipt = $history['prepare'];
            if ($receipt !== null && $receipt['prepare_key'] === $key) {
                $this->require($receipt['expected_epoch'] === $expectedEpoch
                    && $receipt['request_hash'] === $this->requestHash($key, $expectedEpoch, $state['scope'], $state['version']), 'AUTHORITY_FENCE_REQUEST_CONFLICT');
                $this->matchPrepared($state, $receipt);
                return $this->result($state) + ['replayed' => true, 'receipt' => $receipt];
            }
            $this->require($state['phase'] === 'open', 'AUTHORITY_FENCE_PREPARED');
            $this->require($state['authority_epoch'] === $expectedEpoch, 'AUTHORITY_FENCE_STALE_EPOCH');
            $this->require($expectedEpoch !== '9223372036854775807', 'AUTHORITY_FENCE_EPOCH_EXHAUSTED');
            $prepared = $state; unset($prepared['content_hash']);
            $prepared['phase'] = 'prepared'; $prepared['authority_epoch'] = (string)((int)$expectedEpoch+1);
            $prepared['prepare_key'] = $key; $prepared['row_version'] = $this->preparedEpoch($state); $prepared['updated_at'] = (string)time();
            $prepared['content_hash'] = $this->hash($prepared);
            $receipt = ['prepare_key'=>$key,'scope'=>$state['scope'],'version'=>$state['version'],'expected_epoch'=>$expectedEpoch,
                'prepared_epoch'=>$prepared['authority_epoch'],'request_hash'=>$this->requestHash($key,$expectedEpoch,$state['scope'],$state['version']),
                'prior_state_hash'=>$state['content_hash'],'prepared_state_hash'=>$prepared['content_hash'],'captured_at'=>$prepared['updated_at']];
            $receipt['content_hash'] = $this->hash($receipt);
            $this->require(DB::table('tx_authority_writer_fence')->where('id',1)->update($prepared) === 1, 'AUTHORITY_FENCE_STATE_INVALID');
            DB::table('tx_authority_prepare_receipt')->insert($receipt);
            return $this->result($prepared) + ['replayed' => false, 'receipt' => $receipt];
        });
    }

    public function status(): array
    {
        return $this->control(function (): array {
            $state = $this->state(false); $history = $this->evidence($state); $receipt = $history['prepare'];
            return $this->result($state) + ['read_only'=>true,'receipt'=>$receipt];
        });
    }

    public function recover(string $key, string $expectedEpoch): array
    {
        $this->key($key); $expectedEpoch = $this->epoch($expectedEpoch);
        return $this->control(function () use ($key,$expectedEpoch): array {
            $state = $this->state(false); $history = $this->evidence($state); $receipt = $history['prepare'];
            if ($receipt === null) {
                $this->require($state['phase']==='open' && $state['authority_epoch']===$expectedEpoch, 'AUTHORITY_FENCE_REQUEST_CONFLICT');
                return $this->result($state) + ['read_only'=>true,'recovery_status'=>'inconclusive_no_committed_prepare','receipt'=>null];
            }
            $this->require($receipt['prepare_key']===$key && $receipt['expected_epoch']===$expectedEpoch && $receipt['request_hash']===$this->requestHash($key,$expectedEpoch,$state['scope'],$state['version']),
                'AUTHORITY_FENCE_REQUEST_CONFLICT');
            $this->matchPrepared($state,$receipt);
            return $this->result($state) + ['read_only'=>true,'recovery_status'=>'committed_prepare','receipt'=>$receipt];
        });
    }

    public function expand(string $key,string $expectedEpoch):array
    {
        $this->key($key);$expectedEpoch=$this->epoch($expectedEpoch);
        return $this->control(function()use($key,$expectedEpoch):array{
            $state=$this->state(true);$upgrade=$this->evidence($state)['upgrade'];
            if($upgrade!==null){
                $this->require($upgrade['upgrade_key']===$key && $upgrade['expected_epoch']===$expectedEpoch,'AUTHORITY_INVENTORY_REQUEST_CONFLICT');
                return $this->result($state)+['replayed'=>true,'upgrade_receipt'=>$upgrade];
            }
            $this->require($state['scope']===self::SCOPE && $state['phase']==='open','AUTHORITY_INVENTORY_EXPAND_REFUSED');
            $this->require($expectedEpoch==='1' && $state['authority_epoch']===$expectedEpoch,'AUTHORITY_FENCE_STALE_EPOCH');
            $expanded=$state;unset($expanded['content_hash']);$expanded['scope']=AuthorityInventory::SCOPE;$expanded['version']=AuthorityInventory::VERSION;
            $expanded['inventory_hash']=app(AuthorityInventory::class)->inventoryHash();$expanded['authority_epoch']='2';$expanded['row_version']='2';$expanded['updated_at']=(string)time();
            $expanded['content_hash']=$this->hash($expanded);$receipt=app(AuthorityInventory::class)->make($state,$expanded,$key);
            $this->require(DB::table('tx_authority_writer_fence')->where('id',1)->update($expanded)===1,'AUTHORITY_FENCE_STATE_INVALID');
            DB::table('tx_authority_inventory_upgrade')->insert($receipt);
            return $this->result($expanded)+['replayed'=>false,'upgrade_receipt'=>$receipt];
        });
    }
    public function recoverExpand(string $key,string $expectedEpoch):array
    {
        $this->key($key);$expectedEpoch=$this->epoch($expectedEpoch);
        return $this->control(function()use($key,$expectedEpoch):array{
            $state=$this->state(false);$upgrade=$this->evidence($state)['upgrade'];
            if($upgrade===null){
                $this->require($state['scope']===self::SCOPE && $state['phase']==='open' && $expectedEpoch==='1', 'AUTHORITY_INVENTORY_EXPAND_REFUSED');
                return $this->result($state)+['read_only'=>true,'recovery_status'=>'inconclusive_no_committed_expand','upgrade_receipt'=>null];
            }
            $this->require($upgrade['upgrade_key']===$key && $upgrade['expected_epoch']===$expectedEpoch,'AUTHORITY_INVENTORY_REQUEST_CONFLICT');
            return $this->result($state)+['read_only'=>true,'recovery_status'=>'committed_expand','upgrade_receipt'=>$upgrade];
        });
    }
    public function extendFinance(string $key,string $expectedEpoch):array
    {
        $this->key($key);$expectedEpoch=$this->epoch($expectedEpoch);
        return $this->control(function()use($key,$expectedEpoch):array{
            $state=$this->state(true);$history=$this->evidence($state);$finance=$history['finance'];
            if($finance!==null){
                $this->require($finance['upgrade_key']===$key && $finance['expected_epoch']===$expectedEpoch,'AUTHORITY_FINANCE_REQUEST_CONFLICT');
                return $this->result($state)+['replayed'=>true,'finance_receipt'=>$finance];
            }
            $this->require($state['scope']===AuthorityInventory::SCOPE && $state['phase']==='open','AUTHORITY_FINANCE_EXTEND_REFUSED');
            $this->require($expectedEpoch==='2' && $state['authority_epoch']===$expectedEpoch,'AUTHORITY_FENCE_STALE_EPOCH');
            $expanded=$state;unset($expanded['content_hash']);$expanded['scope']=AuthorityFinanceInventory::SCOPE;$expanded['version']=AuthorityFinanceInventory::VERSION;
            $expanded['inventory_hash']=app(AuthorityFinanceInventory::class)->inventoryHash();$expanded['authority_epoch']='3';$expanded['row_version']='3';$expanded['updated_at']=(string)time();
            $expanded['content_hash']=$this->hash($expanded);$receipt=app(AuthorityFinanceInventory::class)->make($state,$expanded,$history['upgrade'],$key);
            $this->require(DB::table('tx_authority_writer_fence')->where('id',1)->update($expanded)===1,'AUTHORITY_FENCE_STATE_INVALID');
            DB::table('tx_authority_finance_upgrade')->insert($receipt);
            return $this->result($expanded)+['replayed'=>false,'finance_receipt'=>$receipt];
        });
    }
    public function recoverFinance(string $key,string $expectedEpoch):array
    {
        $this->key($key);$expectedEpoch=$this->epoch($expectedEpoch);
        return $this->control(function()use($key,$expectedEpoch):array{
            $state=$this->state(false);$finance=$this->evidence($state)['finance'];
            if($finance===null){
                $this->require($state['scope']===AuthorityInventory::SCOPE && $state['phase']==='open' && $expectedEpoch==='2','AUTHORITY_FINANCE_EXTEND_REFUSED');
                return $this->result($state)+['read_only'=>true,'recovery_status'=>'inconclusive_no_committed_finance','finance_receipt'=>null];
            }
            $this->require($finance['upgrade_key']===$key && $finance['expected_epoch']===$expectedEpoch,'AUTHORITY_FINANCE_REQUEST_CONFLICT');
            return $this->result($state)+['read_only'=>true,'recovery_status'=>'committed_finance','finance_receipt'=>$finance];
        });
    }

    public function extendNative(string $key,string $expectedEpoch):array
    {
        $this->key($key);$expectedEpoch=$this->epoch($expectedEpoch);
        return $this->control(function()use($key,$expectedEpoch):array{
            $state=$this->state(true);$history=$this->evidence($state);$finance=$history['native'];
            if($finance!==null){
                $this->require($finance['upgrade_key']===$key && $finance['expected_epoch']===$expectedEpoch,'AUTHORITY_NATIVE_REQUEST_CONFLICT');
                return $this->result($state)+['replayed'=>true,'native_receipt'=>$finance];
            }
            $this->require($state['scope']===AuthorityFinanceInventory::SCOPE && $state['phase']==='open','AUTHORITY_NATIVE_EXTEND_REFUSED');
            $this->require($expectedEpoch==='3' && $state['authority_epoch']===$expectedEpoch,'AUTHORITY_FENCE_STALE_EPOCH');
            $expanded=$state;unset($expanded['content_hash']);$expanded['scope']=AuthorityNativeInventory::SCOPE;$expanded['version']=AuthorityNativeInventory::VERSION;
            $expanded['inventory_hash']=app(AuthorityNativeInventory::class)->inventoryHash();$expanded['authority_epoch']='4';$expanded['row_version']='4';$expanded['updated_at']=(string)time();
            $expanded['content_hash']=$this->hash($expanded);$receipt=app(AuthorityNativeInventory::class)->make($state,$expanded,$history['finance'],$key);
            $this->require(DB::table('tx_authority_writer_fence')->where('id',1)->update($expanded)===1,'AUTHORITY_FENCE_STATE_INVALID');
            DB::table('tx_authority_native_upgrade')->insert($receipt);
            return $this->result($expanded)+['replayed'=>false,'native_receipt'=>$receipt];
        });
    }
    public function recoverNative(string $key,string $expectedEpoch):array
    {
        $this->key($key);$expectedEpoch=$this->epoch($expectedEpoch);
        return $this->control(function()use($key,$expectedEpoch):array{
            $state=$this->state(false);$finance=$this->evidence($state)['native'];
            if($finance===null){
                $this->require($state['scope']===AuthorityFinanceInventory::SCOPE && $state['phase']==='open' && $expectedEpoch==='3','AUTHORITY_NATIVE_EXTEND_REFUSED');
                return $this->result($state)+['read_only'=>true,'recovery_status'=>'inconclusive_no_committed_native','native_receipt'=>null];
            }
            $this->require($finance['upgrade_key']===$key && $finance['expected_epoch']===$expectedEpoch,'AUTHORITY_NATIVE_REQUEST_CONFLICT');
            return $this->result($state)+['read_only'=>true,'recovery_status'=>'committed_native','native_receipt'=>$finance];
        });
    }

    public function extendIdentity(string $key,string $expectedEpoch):array
    {
        $this->key($key);$expectedEpoch=$this->epoch($expectedEpoch);
        return $this->control(function()use($key,$expectedEpoch):array{
            $state=$this->state(true);$history=$this->evidence($state);$finance=$history['identity'];
            if($finance!==null){
                $this->require($finance['upgrade_key']===$key && $finance['expected_epoch']===$expectedEpoch,'AUTHORITY_IDENTITY_REQUEST_CONFLICT');
                return $this->result($state)+['replayed'=>true,'identity_receipt'=>$finance];
            }
            $this->require($state['scope']===AuthorityNativeInventory::SCOPE && $state['phase']==='open','AUTHORITY_IDENTITY_EXTEND_REFUSED');
            $this->require($expectedEpoch==='4' && $state['authority_epoch']===$expectedEpoch,'AUTHORITY_FENCE_STALE_EPOCH');
            $expanded=$state;unset($expanded['content_hash']);$expanded['scope']=AuthorityIdentityInventory::SCOPE;$expanded['version']=AuthorityIdentityInventory::VERSION;
            $expanded['inventory_hash']=app(AuthorityIdentityInventory::class)->inventoryHash();$expanded['authority_epoch']='5';$expanded['row_version']='5';$expanded['updated_at']=(string)time();
            $expanded['content_hash']=$this->hash($expanded);$receipt=app(AuthorityIdentityInventory::class)->make($state,$expanded,$history['native'],$key);
            $this->require(DB::table('tx_authority_writer_fence')->where('id',1)->update($expanded)===1,'AUTHORITY_FENCE_STATE_INVALID');
            DB::table('tx_authority_identity_upgrade')->insert($receipt);
            return $this->result($expanded)+['replayed'=>false,'identity_receipt'=>$receipt];
        });
    }
    public function recoverIdentity(string $key,string $expectedEpoch):array
    {
        $this->key($key);$expectedEpoch=$this->epoch($expectedEpoch);
        return $this->control(function()use($key,$expectedEpoch):array{
            $state=$this->state(false);$finance=$this->evidence($state)['identity'];
            if($finance===null){
                $this->require($state['scope']===AuthorityNativeInventory::SCOPE && $state['phase']==='open' && $expectedEpoch==='4','AUTHORITY_IDENTITY_EXTEND_REFUSED');
                return $this->result($state)+['read_only'=>true,'recovery_status'=>'inconclusive_no_committed_identity','identity_receipt'=>null];
            }
            $this->require($finance['upgrade_key']===$key && $finance['expected_epoch']===$expectedEpoch,'AUTHORITY_IDENTITY_REQUEST_CONFLICT');
            return $this->result($state)+['read_only'=>true,'recovery_status'=>'committed_identity','identity_receipt'=>$finance];
        });
    }

    private function evidence(array $state):array
    {
        $prepare=$this->preparedReceipt($state); $identity=app(AuthorityIdentityInventory::class)->inspect($state);
        $native=app(AuthorityNativeInventory::class)->inspect($identity['prior_state']??$state);
        $finance=app(AuthorityFinanceInventory::class)->inspect($native['prior_state']??$state);
        $upgrade=app(AuthorityInventory::class)->verified($finance['prior_state']??$state);
        if($finance['receipt']!==null) $this->require($upgrade!==null && $finance['receipt']['parent_inventory_upgrade_hash']===$upgrade['content_hash'],'AUTHORITY_FINANCE_EVIDENCE_INVALID');
        if($native['receipt']!==null) $this->require($finance['receipt']!==null && $native['receipt']['parent_finance_upgrade_hash']===$finance['receipt']['content_hash'],'AUTHORITY_NATIVE_EVIDENCE_INVALID');
        if($identity['receipt']!==null) $this->require($native['receipt']!==null && $identity['receipt']['parent_native_upgrade_hash']===$native['receipt']['content_hash'],'AUTHORITY_IDENTITY_EVIDENCE_INVALID');
        if($upgrade!==null && $prepare!==null) $this->require($prepare['prior_state_hash']===($identity['receipt']['expanded_state_hash']??$native['receipt']['expanded_state_hash']??$finance['receipt']['expanded_state_hash']??$upgrade['expanded_state_hash']),'AUTHORITY_FENCE_RECEIPT_INVALID');
        return ['prepare'=>$prepare,'upgrade'=>$upgrade,'finance'=>$finance['receipt'],'native'=>$native['receipt'],'identity'=>$identity['receipt']];
    }
    private function declaration():array
    {
        $settings=config('authority_writer_fence');
        return count($settings)===3?['scope'=>self::SCOPE,'version'=>self::VERSION]
            :['scope'=>$settings['worker_scope'],'version'=>$settings['worker_version']];
    }
    private function worker(array $state):void
    {
        $declaration=$this->declaration();
        $this->require($state['scope']===$declaration['scope'] && $state['version']===$declaration['version'],'AUTHORITY_FENCE_SCOPE_REJECTED');
    }
    private function writers(array $state):array
    {return match($state['scope']){self::SCOPE=>['admin.user.create','admin.user.update'],AuthorityInventory::SCOPE=>AuthorityInventory::WRITERS,AuthorityFinanceInventory::SCOPE=>AuthorityFinanceInventory::WRITERS,AuthorityNativeInventory::SCOPE=>AuthorityNativeInventory::WRITERS,AuthorityIdentityInventory::SCOPE=>AuthorityIdentityInventory::WRITERS};}
    private function openEpoch(array $state):string
    {return match($state['scope']){self::SCOPE=>'1',AuthorityInventory::SCOPE=>'2',AuthorityFinanceInventory::SCOPE=>'3',AuthorityNativeInventory::SCOPE=>'4',AuthorityIdentityInventory::SCOPE=>'5'};}
    private function preparedEpoch(array $state):string{return (string)((int)$this->openEpoch($state)+1);}

    private function state(bool $exclusive): array
    {
        $query = DB::table('tx_authority_writer_fence')->where('id',1);
        $row = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->first(self::STATE_COLUMNS);
        $this->require($row !== null, 'AUTHORITY_FENCE_STATE_MISSING');
        $this->require(DB::table('tx_authority_writer_fence')->where('id','<>',1)->sharedLock()->first(['id'])===null, 'AUTHORITY_FENCE_STATE_INVALID');
        $state=(array)$row;
        foreach(['id','authority_epoch','row_version','updated_at']as$key)$state[$key]=$this->epoch($state[$key]);
        $inventory=$state['inventory_hash'];
        if($state['scope']===self::SCOPE){$this->require($inventory===null,'AUTHORITY_FENCE_STATE_INVALID');unset($state['inventory_hash']);}
        else {
            $this->require(($state['scope']===AuthorityInventory::SCOPE && $state['version']===AuthorityInventory::VERSION)
                ||($state['scope']===AuthorityFinanceInventory::SCOPE && $state['version']===AuthorityFinanceInventory::VERSION)
                ||($state['scope']===AuthorityNativeInventory::SCOPE && $state['version']===AuthorityNativeInventory::VERSION)
                ||($state['scope']===AuthorityIdentityInventory::SCOPE && $state['version']===AuthorityIdentityInventory::VERSION),'AUTHORITY_FENCE_STATE_INVALID');
            $this->require($inventory===match($state['scope']){AuthorityInventory::SCOPE=>app(AuthorityInventory::class)->inventoryHash(),AuthorityFinanceInventory::SCOPE=>app(AuthorityFinanceInventory::class)->inventoryHash(),AuthorityNativeInventory::SCOPE=>app(AuthorityNativeInventory::class)->inventoryHash(),AuthorityIdentityInventory::SCOPE=>app(AuthorityIdentityInventory::class)->inventoryHash()},'AUTHORITY_FENCE_STATE_INVALID');
        }
        $hash=$state['content_hash'];unset($state['content_hash']);
        $this->require($state['id']==='1' && (($state['scope']===self::SCOPE && $state['version']===self::VERSION)
                || ($state['scope']===AuthorityInventory::SCOPE && $state['version']===AuthorityInventory::VERSION)
                || ($state['scope']===AuthorityFinanceInventory::SCOPE && $state['version']===AuthorityFinanceInventory::VERSION)
                || ($state['scope']===AuthorityNativeInventory::SCOPE && $state['version']===AuthorityNativeInventory::VERSION)
                || ($state['scope']===AuthorityIdentityInventory::SCOPE && $state['version']===AuthorityIdentityInventory::VERSION)) && $state['authority']==='legacy'
            && in_array($state['phase'],['open','prepared'],true) && is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/D',$hash)
            && $hash===$this->hash($state), 'AUTHORITY_FENCE_STATE_INVALID');
        $openEpoch=$this->openEpoch($state); $preparedEpoch=$this->preparedEpoch($state);
        if($state['phase']==='open')$this->require($state['authority_epoch']===$openEpoch && $state['row_version']===$openEpoch && $state['prepare_key']===null, 'AUTHORITY_FENCE_STATE_INVALID');
        else {$this->key($state['prepare_key']);$this->require($state['authority_epoch']===$preparedEpoch && $state['row_version']===$preparedEpoch, 'AUTHORITY_FENCE_STATE_INVALID');}
        return $state+['content_hash'=>$hash];
    }
    private function preparedReceipt(array $state): ?array
    {
        $rows=DB::table('tx_authority_prepare_receipt')->sharedLock()->limit(2)->get(self::RECEIPT_COLUMNS);
        if($state['phase']==='open'){$this->require($rows->isEmpty(),'AUTHORITY_FENCE_RECEIPT_INVALID');return null;}
        $this->require($rows->count()===1,'AUTHORITY_FENCE_RECEIPT_INVALID');
        $receipt=$this->receipt($rows->first());$this->matchPrepared($state,$receipt);return $receipt;
    }
    private function receipt(object $row): array
    {
        $receipt=(array)$row;
        foreach(['expected_epoch','prepared_epoch','captured_at']as$key)$receipt[$key]=$this->epoch($receipt[$key]);
        $this->key($receipt['prepare_key']);$hash=$receipt['content_hash'];unset($receipt['content_hash']);
        foreach(['request_hash','prior_state_hash','prepared_state_hash']as$key)
            $this->require(is_string($receipt[$key]) && preg_match('/\A[0-9a-f]{64}\z/D',$receipt[$key]),'AUTHORITY_FENCE_RECEIPT_INVALID');
        $old=$receipt['scope']===self::SCOPE && $receipt['version']===self::VERSION;
        $new=$receipt['scope']===AuthorityInventory::SCOPE && $receipt['version']===AuthorityInventory::VERSION;
        $finance=$receipt['scope']===AuthorityFinanceInventory::SCOPE && $receipt['version']===AuthorityFinanceInventory::VERSION;
        $native=$receipt['scope']===AuthorityNativeInventory::SCOPE && $receipt['version']===AuthorityNativeInventory::VERSION;
        $identity=$receipt['scope']===AuthorityIdentityInventory::SCOPE && $receipt['version']===AuthorityIdentityInventory::VERSION;
        $this->require(($old||$new||$finance||$native||$identity) && $receipt['expected_epoch']===($old?'1':($new?'2':($finance?'3':($native?'4':'5'))))
            && $receipt['prepared_epoch']===($old?'2':($new?'3':($finance?'4':($native?'5':'6')))) && $receipt['request_hash']===$this->requestHash($receipt['prepare_key'],$receipt['expected_epoch'],$receipt['scope'],$receipt['version'])
            && $hash===$this->hash($receipt),'AUTHORITY_FENCE_RECEIPT_INVALID');
        return $receipt+['content_hash'=>$hash];
    }
    private function matchPrepared(array $state,array $receipt): void
    {
        $this->require($state['phase']==='prepared' && $state['scope']===$receipt['scope'] && $state['version']===$receipt['version'] && $state['prepare_key']===$receipt['prepare_key']
            && $state['authority_epoch']===$receipt['prepared_epoch'] && $state['updated_at']===$receipt['captured_at']
            && $state['content_hash']===$receipt['prepared_state_hash'],'AUTHORITY_FENCE_RECEIPT_INVALID');
    }
    private function context(): void
    {
        $settings=config('authority_writer_fence');
        $this->require(is_array($settings) && in_array(count($settings),[3,5],true)
            && !array_diff(array_keys($settings),count($settings)===3?['enabled','worker_epoch','worker_role']:['enabled','worker_epoch','worker_role','worker_scope','worker_version']) && $settings['enabled']===true
            && app()->environment(['local','testing']) && config('core_shadow.isolated_copy')===true,'AUTHORITY_FENCE_ISOLATED_REQUIRED');
        $this->epoch($settings['worker_epoch']);
        $declaration=$this->declaration();
        $this->require(($declaration['scope']===self::SCOPE&&$declaration['version']===self::VERSION)
            ||($declaration['scope']===AuthorityInventory::SCOPE&&$declaration['version']===AuthorityInventory::VERSION)
            ||($declaration['scope']===AuthorityFinanceInventory::SCOPE&&$declaration['version']===AuthorityFinanceInventory::VERSION)
            ||($declaration['scope']===AuthorityNativeInventory::SCOPE&&$declaration['version']===AuthorityNativeInventory::VERSION)
            ||($declaration['scope']===AuthorityIdentityInventory::SCOPE&&$declaration['version']===AuthorityIdentityInventory::VERSION),'AUTHORITY_FENCE_SCOPE_INVALID');
        $this->require($settings['worker_role']==='legacy','AUTHORITY_FENCE_ROLE_REJECTED');
    }
    private function control(callable $operation): array
    {
        return $this->safe(function()use($operation):array{
            $this->require(DB::transactionLevel()===0,'AUTHORITY_FENCE_OUTER_TRANSACTION_UNSUPPORTED');$this->context();
            return DB::transaction($operation);
        });
    }
    private function result(array $state): array
    {
        return ['scope'=>$state['scope'],'version'=>$state['version'],'authority'=>'legacy','phase'=>$state['phase'],'authority_epoch'=>$state['authority_epoch'],
            'state_hash'=>$state['content_hash'],'covered_writers'=>$this->writers($state),
            'global_writer_coverage_complete'=>false,'global_drain_complete'=>false,'target_authority_active'=>false,'migration_gate_open'=>false];
    }
    private function epoch(mixed $value): string
    {
        $this->require(is_string($value)||is_int($value),'AUTHORITY_FENCE_EPOCH_INVALID');$value=(string)$value;
        $this->require(preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)
            && (strlen($value)<19||strcmp($value,'9223372036854775807')<=0),'AUTHORITY_FENCE_EPOCH_INVALID');return $value;
    }
    private function key(mixed $key): void
    {$this->require(is_string($key)&&preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}\z/D',$key),'AUTHORITY_FENCE_KEY_INVALID');}
    private function requestHash(string $key,string $epoch,string $scope=self::SCOPE,string $version=self::VERSION):string
    {return $this->hash(['scope'=>$scope,'version'=>$version,'operation'=>'prepare','prepare_key'=>$key,'expected_epoch'=>$epoch]);}
    private function hash(array $value):string
    {ksort($value,SORT_STRING);return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    private function require(bool $ok,string $code):void{if(!$ok)throw new AuthorityFenceException($code);}
    private function safe(callable $operation):mixed
    {try{return $operation();}catch(AuthorityFenceException $error){throw $error;}catch(Throwable){throw new AuthorityFenceException('AUTHORITY_FENCE_STORAGE_OR_EVIDENCE_FAILED');}}
}
