<?php

namespace App\Console\Commands;

use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityPostcommitHandoff;
use Illuminate\Console\Command;
use Throwable;

final class CoreAuthorityPostcommit extends Command
{
    protected $signature='core:authority-postcommit-handoff {--limit=100} {--isolated-copy}';
    protected $description='Bounded isolated original NodeUserSync queue handoff, never Node delivery proof';
    public function handle(AuthorityPostcommitHandoff $handoff):int
    {
        try{$limit=(string)$this->option('limit');
            if(!$this->option('isolated-copy'))throw new AuthorityFenceException('AUTHORITY_HANDOFF_ISOLATED_REQUIRED');
            if(!preg_match('/\A[1-9][0-9]{0,3}\z/D',$limit))throw new AuthorityFenceException('AUTHORITY_HANDOFF_BOUNDS_INVALID');
            $this->line(json_encode($handoff->run((int)$limit),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));return self::SUCCESS;
        }catch(Throwable $error){$this->line(json_encode(['error_code'=>$error instanceof AuthorityFenceException?$error->getMessage():'AUTHORITY_HANDOFF_STORAGE_FAILED']));return self::FAILURE;}
    }
}
