<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Only the named native SQL writers; no global or production authority publication. */
final class AuthorityWriterFence
{
    public const SCOPE = 'admin-user-editor/v1';
    public const VERSION = 'authority-writer-fence/v1';
    private const STATE_COLUMNS = ['id','scope','version','authority','phase','authority_epoch','prepare_key','row_version','updated_at','content_hash'];
    private const RECEIPT_COLUMNS = ['prepare_key','scope','version','expected_epoch','prepared_epoch','request_hash','prior_state_hash','prepared_state_hash','captured_at','content_hash'];

    public function admit(string $writer): void
    {
        if (config('authority_writer_fence.enabled') === false) return;
        $this->safe(function () use ($writer): void {
            $this->context();
            $this->require(DB::transactionLevel() > 0, 'AUTHORITY_FENCE_TRANSACTION_REQUIRED');
            $this->require(in_array($writer, ['admin.user.create','admin.user.update'], true), 'AUTHORITY_FENCE_WRITER_INVALID');
            $state = $this->state(false); $this->preparedReceipt($state);
            $this->require(config('authority_writer_fence.worker_role') === 'legacy', 'AUTHORITY_FENCE_ROLE_REJECTED');
            $this->require($state['authority_epoch'] === $this->epoch(config('authority_writer_fence.worker_epoch')), 'AUTHORITY_FENCE_STALE_EPOCH');
            $this->require($state['phase'] === 'open', 'AUTHORITY_FENCE_PREPARED');
        });
    }

    public function initialize(): array
    {
        return $this->control(function (): array {
            $row = DB::table('tx_authority_writer_fence')->where('id', 1)->lockForUpdate()->first(self::STATE_COLUMNS);
            if ($row === null) {
                $this->require(DB::table('tx_authority_writer_fence')->lockForUpdate()->first(['id']) === null
                    && DB::table('tx_authority_prepare_receipt')->lockForUpdate()->first(['prepare_key']) === null, 'AUTHORITY_FENCE_STATE_INVALID');
                $state = ['id'=>'1','scope'=>self::SCOPE,'version'=>self::VERSION,'authority'=>'legacy','phase'=>'open',
                    'authority_epoch'=>'1','prepare_key'=>null,'row_version'=>'1','updated_at'=>(string)time()];
                $state['content_hash'] = $this->hash($state); DB::table('tx_authority_writer_fence')->insert($state);
            }
            $state = $this->state(true); $this->preparedReceipt($state);
            return $this->result($state) + ['initialized' => true];
        });
    }

    public function prepare(string $key, string $expectedEpoch): array
    {
        $this->key($key); $expectedEpoch = $this->epoch($expectedEpoch);
        return $this->control(function () use ($key, $expectedEpoch): array {
            $state = $this->state(true); $this->preparedReceipt($state);
            $receipt = DB::table('tx_authority_prepare_receipt')->where('prepare_key', $key)->lockForUpdate()->first(self::RECEIPT_COLUMNS);
            if ($receipt !== null) {
                $receipt = $this->receipt($receipt);
                $this->require($receipt['expected_epoch'] === $expectedEpoch
                    && $receipt['request_hash'] === $this->requestHash($key, $expectedEpoch), 'AUTHORITY_FENCE_REQUEST_CONFLICT');
                $this->matchPrepared($state, $receipt);
                return $this->result($state) + ['replayed' => true, 'receipt' => $receipt];
            }
            $this->require($state['phase'] === 'open', 'AUTHORITY_FENCE_PREPARED');
            $this->require($state['authority_epoch'] === $expectedEpoch, 'AUTHORITY_FENCE_STALE_EPOCH');
            $this->require($expectedEpoch !== '9223372036854775807', 'AUTHORITY_FENCE_EPOCH_EXHAUSTED');
            $prepared = $state; unset($prepared['content_hash']);
            $prepared['phase'] = 'prepared'; $prepared['authority_epoch'] = (string)((int)$expectedEpoch+1);
            $prepared['prepare_key'] = $key; $prepared['row_version'] = '2'; $prepared['updated_at'] = (string)time();
            $prepared['content_hash'] = $this->hash($prepared);
            $receipt = ['prepare_key'=>$key,'scope'=>self::SCOPE,'version'=>self::VERSION,'expected_epoch'=>$expectedEpoch,
                'prepared_epoch'=>$prepared['authority_epoch'],'request_hash'=>$this->requestHash($key,$expectedEpoch),
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
            $state = $this->state(false); $receipt = $this->preparedReceipt($state);
            return $this->result($state) + ['read_only'=>true,'receipt'=>$receipt];
        });
    }

    public function recover(string $key, string $expectedEpoch): array
    {
        $this->key($key); $expectedEpoch = $this->epoch($expectedEpoch);
        return $this->control(function () use ($key,$expectedEpoch): array {
            $state = $this->state(false); $receipt = $this->preparedReceipt($state);
            if ($receipt === null) {
                $this->require($state['phase']==='open' && $state['authority_epoch']===$expectedEpoch, 'AUTHORITY_FENCE_REQUEST_CONFLICT');
                return $this->result($state) + ['read_only'=>true,'recovery_status'=>'inconclusive_no_committed_prepare','receipt'=>null];
            }
            $this->require($receipt['prepare_key']===$key && $receipt['expected_epoch']===$expectedEpoch && $receipt['request_hash']===$this->requestHash($key,$expectedEpoch),
                'AUTHORITY_FENCE_REQUEST_CONFLICT');
            $this->matchPrepared($state,$receipt);
            return $this->result($state) + ['read_only'=>true,'recovery_status'=>'committed_prepare','receipt'=>$receipt];
        });
    }

    private function state(bool $exclusive): array
    {
        $query = DB::table('tx_authority_writer_fence')->where('id',1);
        $row = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->first(self::STATE_COLUMNS);
        $this->require($row !== null, 'AUTHORITY_FENCE_STATE_MISSING');
        $this->require(DB::table('tx_authority_writer_fence')->where('id','<>',1)->sharedLock()->first(['id'])===null, 'AUTHORITY_FENCE_STATE_INVALID');
        $state=(array)$row;
        foreach(['id','authority_epoch','row_version','updated_at']as$key)$state[$key]=$this->epoch($state[$key]);
        $hash=$state['content_hash'];unset($state['content_hash']);
        $this->require($state['id']==='1' && $state['scope']===self::SCOPE && $state['version']===self::VERSION && $state['authority']==='legacy'
            && in_array($state['phase'],['open','prepared'],true) && is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/D',$hash)
            && $hash===$this->hash($state), 'AUTHORITY_FENCE_STATE_INVALID');
        if($state['phase']==='open')$this->require($state['authority_epoch']==='1' && $state['row_version']==='1' && $state['prepare_key']===null, 'AUTHORITY_FENCE_STATE_INVALID');
        else {$this->key($state['prepare_key']);$this->require($state['authority_epoch']==='2' && $state['row_version']==='2', 'AUTHORITY_FENCE_STATE_INVALID');}
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
        $this->require($receipt['scope']===self::SCOPE && $receipt['version']===self::VERSION && $receipt['expected_epoch']==='1'
            && $receipt['prepared_epoch']==='2' && $receipt['request_hash']===$this->requestHash($receipt['prepare_key'],$receipt['expected_epoch'])
            && $hash===$this->hash($receipt),'AUTHORITY_FENCE_RECEIPT_INVALID');
        return $receipt+['content_hash'=>$hash];
    }
    private function matchPrepared(array $state,array $receipt): void
    {
        $this->require($state['phase']==='prepared' && $state['prepare_key']===$receipt['prepare_key']
            && $state['authority_epoch']===$receipt['prepared_epoch'] && $state['updated_at']===$receipt['captured_at']
            && $state['content_hash']===$receipt['prepared_state_hash'],'AUTHORITY_FENCE_RECEIPT_INVALID');
    }
    private function context(): void
    {
        $settings=config('authority_writer_fence');
        $this->require(is_array($settings) && count($settings)===3
            && !array_diff(array_keys($settings),['enabled','worker_epoch','worker_role']) && $settings['enabled']===true
            && app()->environment(['local','testing']) && config('core_shadow.isolated_copy')===true,'AUTHORITY_FENCE_ISOLATED_REQUIRED');
        $this->epoch($settings['worker_epoch']);
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
        return ['scope'=>self::SCOPE,'authority'=>'legacy','phase'=>$state['phase'],'authority_epoch'=>$state['authority_epoch'],
            'state_hash'=>$state['content_hash'],'covered_writers'=>['admin.user.create','admin.user.update'],
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
    private function requestHash(string $key,string $epoch):string
    {return $this->hash(['scope'=>self::SCOPE,'version'=>self::VERSION,'operation'=>'prepare','prepare_key'=>$key,'expected_epoch'=>$epoch]);}
    private function hash(array $value):string
    {ksort($value,SORT_STRING);return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    private function require(bool $ok,string $code):void{if(!$ok)throw new AuthorityFenceException($code);}
    private function safe(callable $operation):mixed
    {try{return $operation();}catch(AuthorityFenceException $error){throw $error;}catch(Throwable){throw new AuthorityFenceException('AUTHORITY_FENCE_STORAGE_OR_EVIDENCE_FAILED');}}
}
