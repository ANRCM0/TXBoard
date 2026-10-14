<?php

namespace App\Console\Commands;

use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use Illuminate\Console\Command;
use Throwable;

final class CoreAuthorityFence extends Command
{
    protected $signature='core:authority-fence {operation} {--key=} {--expected-epoch=} {--isolated-copy}';
    protected $description='Isolated native SQL writer fence: fixed init/expand/prepare/status/recovery operations';
    public function handle(AuthorityWriterFence $fence):int
    {
        try{
            if(!$this->option('isolated-copy'))throw new AuthorityFenceException('AUTHORITY_FENCE_ISOLATED_REQUIRED');
            $operation=$this->argument('operation');$key=$this->option('key');$epoch=$this->option('expected-epoch');
            if(!in_array($operation,['init','prepare','status','recover','expand','recover-expand','extend-finance','recover-finance','extend-native','recover-native','extend-identity','recover-identity','extend-ticket','recover-ticket'],true)
                || (in_array($operation,['init','status'],true)?($key!==null||$epoch!==null):(!is_string($key)||!is_string($epoch))))
                throw new AuthorityFenceException('AUTHORITY_FENCE_COMMAND_INVALID');
            $report=match($operation){'init'=>$fence->initialize(),'prepare'=>$fence->prepare($key,$epoch),
                'status'=>$fence->status(),'recover'=>$fence->recover($key,$epoch),
                'expand'=>$fence->expand($key,$epoch),'recover-expand'=>$fence->recoverExpand($key,$epoch),
                'extend-finance'=>$fence->extendFinance($key,$epoch),'recover-finance'=>$fence->recoverFinance($key,$epoch),
                'extend-native'=>$fence->extendNative($key,$epoch),'recover-native'=>$fence->recoverNative($key,$epoch),
                'extend-identity'=>$fence->extendIdentity($key,$epoch),'recover-identity'=>$fence->recoverIdentity($key,$epoch),
                'extend-ticket'=>$fence->extendTicket($key,$epoch),'recover-ticket'=>$fence->recoverTicket($key,$epoch)};
            $this->line(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));return self::SUCCESS;
        }catch(Throwable $error){
            $this->line(json_encode(['error_code'=>$error instanceof AuthorityFenceException?$error->getMessage():'AUTHORITY_FENCE_STORAGE_OR_EVIDENCE_FAILED']));
            return self::FAILURE;
        }
    }
}
