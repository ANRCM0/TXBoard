<?php

namespace App\Console\Commands;

use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyUserMutationCapture;
use Illuminate\Console\Command;

final class CoreLegacyCaptureShadow extends Command
{
    protected $signature = 'core:legacy-capture-shadow {--isolated-copy} {--user=} {--after=0} {--limit=100}';
    protected $description = 'SELECT-only bounded recovery of isolated Admin User capture evidence; never applies it';

    public function handle(LegacyUserMutationCapture $capture): int
    {
        try {
            if (!$this->option('isolated-copy')) { throw new LegacyCaptureShadowException('CAPTURE_ISOLATED_COPY_REQUIRED'); }
            foreach (['user', 'after', 'limit'] as $key) {
                $value = $this->option($key);
                if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)
                    || strlen($value) > 19 || strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0) {
                    throw new LegacyCaptureShadowException('CAPTURE_PAGE_INVALID');
                }
            }
            $result = $capture->verify((int) $this->option('user'), (int) $this->option('after'), (int) $this->option('limit'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $result['page_ok'] ? self::SUCCESS : self::FAILURE;
        } catch (LegacyCaptureShadowException $e) {
            $this->line(json_encode(['read_only' => true, 'page_ok' => false, 'projection_applied' => false,
                'global_coverage_complete' => false, 'migration_gate_open' => false, 'error_code' => $e->getMessage()]));
            return self::FAILURE;
        }
    }
}
