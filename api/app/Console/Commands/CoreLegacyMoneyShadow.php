<?php

namespace App\Console\Commands;

use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyMoneyShadow;
use Illuminate\Console\Command;

final class CoreLegacyMoneyShadow extends Command
{
    protected $signature = 'core:legacy-money-shadow {--isolated-copy} {--user=} {--limit=100} {--extend} {--verify} {--after=0}';
    protected $description = 'Bounded isolated captured-money evidence projection or SELECT-only reconciliation';

    public function handle(LegacyMoneyShadow $money): int
    {
        try {
            if (!$this->option('isolated-copy')) throw new LegacyCaptureShadowException('MONEY_SHADOW_ISOLATED_REQUIRED');
            foreach (['user', 'limit', 'after'] as $key) {
                $value = $this->option($key);
                if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)
                    || strlen($value) > 19 || strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0) {
                    throw new LegacyCaptureShadowException('MONEY_SHADOW_PAGE_INVALID');
                }
            }
            if ($this->option('verify') && $this->option('extend')
                || !$this->option('verify') && $this->option('after') !== '0') {
                throw new LegacyCaptureShadowException('MONEY_SHADOW_MODE_INVALID');
            }
            $result = $this->option('verify')
                ? $money->verify((int) $this->option('user'), (int) $this->option('after'), (int) $this->option('limit'))
                : $money->project((int) $this->option('user'), (int) $this->option('limit'), (bool) $this->option('extend'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (LegacyCaptureShadowException $e) {
            $this->line(json_encode(['read_only' => (bool) $this->option('verify'), 'page_ok' => false,
                'migration_gate_open' => false, 'global_coverage_complete' => false, 'error_code' => $e->getMessage()]));
            return self::FAILURE;
        }
    }
}
