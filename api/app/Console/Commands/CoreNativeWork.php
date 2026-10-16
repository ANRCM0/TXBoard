<?php

namespace App\Console\Commands;

use App\Domains\UsageV2\NativeRuntimeWorker;
use Illuminate\Console\Command;
use Throwable;

final class CoreNativeWork extends Command
{
    protected $signature = 'core:native-work {--limit=100}';
    protected $description = 'Recover native usage settlement and durable credential synchronization';

    public function handle(NativeRuntimeWorker $worker): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 1000) { throw new \RuntimeException('NATIVE_WORK_LIMIT_INVALID'); }
            $this->line(json_encode($worker->run($limit), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D', $error->getMessage()) ? $error->getMessage() : 'NATIVE_WORK_FAILED';
            $this->line(json_encode(['error_code' => $code]));
            return self::FAILURE;
        }
    }
}
