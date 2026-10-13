<?php

namespace App\Console\Commands;

use App\Services\Subscription\LegacyLifecycleShadowReconcile;
use App\Services\Subscription\LegacyCaptureShadowException;
use Illuminate\Console\Command;
use Throwable;

final class CoreLifecycleShadowReconcile extends Command
{
    protected $signature = 'core:lifecycle-shadow-reconcile {--run=} {--limit=100} {--after-id=0} {--isolated-copy}';
    protected $description = 'Bounded SELECT-only lifecycle evidence association, never a migration gate';
    public function handle(LegacyLifecycleShadowReconcile $service): int
    {
        try {
            if (!$this->option('isolated-copy')) throw new LegacyCaptureShadowException('LIFECYCLE_ISOLATED_REQUIRED');
            foreach (['limit','after-id'] as $key) {
                $value = (string) $this->option($key);
                if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) || (string) (int) $value !== $value)
                    throw new LegacyCaptureShadowException('LIFECYCLE_BOUNDS_INVALID');
            }
            $report = $service->run((string) $this->option('run'), (int) $this->option('limit'), (int) $this->option('after-id'));
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $report['page_consistent'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $this->line(json_encode(['error_code' => $error instanceof LegacyCaptureShadowException
                ? $error->getMessage() : 'LIFECYCLE_STORAGE_OR_EVIDENCE_FAILED'], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }
}
