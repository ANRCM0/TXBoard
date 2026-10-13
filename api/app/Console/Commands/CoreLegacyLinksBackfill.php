<?php
namespace App\Console\Commands;
use App\Domains\LegacyLinks\LinkService;
use App\Domains\LegacyLinks\LinkFailure;
use Illuminate\Console\Command;
final class CoreLegacyLinksBackfill extends Command
{
    protected $signature='core:legacy-links-backfill {--run=} {--limit=100} {--isolated-copy} {--mapping-run=} {--retry-user=}';
    protected $description='Isolated nonsecret legacy subscription link backfill; no authority switch';
    public function handle(LinkService $service):int
    {
        try{
            if(!$this->option('isolated-copy'))throw new LinkFailure('LINK_ISOLATED_REQUIRED');
            $limit=$this->number('limit');$report=$service->backfill((string)$this->option('run'),(string)$this->option('mapping-run'),$limit,$this->option('retry-user')===null?null:$this->number('retry-user'));
            $this->line(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));return $report['phase']==='backfilled'&&!$report['high_water_changed']?self::SUCCESS:self::FAILURE;
        }catch(\Throwable$error){
            $this->line(json_encode(['error_code'=>$error instanceof LinkFailure?$error->getMessage():'LINK_STORAGE_OR_EVIDENCE_FAILED'],JSON_THROW_ON_ERROR));return self::FAILURE;
        }
    }
    private function number(string $name):int
    {
        $value=(string)$this->option($name);
        if(!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)||(string)(int)$value!==$value)throw new LinkFailure('LINK_BOUNDS_INVALID');
        return (int)$value;
    }
}
