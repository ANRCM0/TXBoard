<?php

namespace App\Console\Commands;

use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageRegistry;
use App\Domains\UsageV2\UsageSettlement;
use Illuminate\Console\Command;

final class CoreUsageV2 extends Command
{
    protected $signature = 'core:usage-v2 {action : source|tariff|grant|settle|recover|generation}
        {--node=} {--installation=} {--source=} {--subject=} {--terms=} {--period=} {--tariff=}
        {--numerator=1} {--denominator=1} {--from=} {--until=} {--batch=} {--reason=} {--limit=100}';
    protected $description = 'Provision and settle usage-v2 fixtures in an explicitly isolated database';

    public function handle(UsageRegistry $registry, UsageSettlement $worker): int
    {
        try {
            $result = match ($this->argument('action')) {
                'source' => $registry->source($this->id('node'), (string) $this->option('installation'), time()),
                'tariff' => $registry->tariff($this->id('node'), (string) $this->option('numerator'), (string) $this->option('denominator'), $this->timestamp('from'), $this->timestamp('until')),
                'grant' => $registry->grant($this->id('source'), $this->id('subject'), $this->id('terms'), $this->id('period'), $this->id('tariff'), $this->timestamp('from'), $this->timestamp('until')),
                'settle' => $worker->scan($this->id('limit'), time()),
                'recover' => $this->recover($worker),
                'generation' => $registry->advanceGeneration($this->id('node'), (string) $this->option('reason')),
                default => throw new UsageError('USAGE_ACTION_INVALID'),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (UsageError $error) {
            $this->error($error->errorCode); return self::FAILURE;
        }
    }

    private function id(string $option): int
    {
        $value = $this->option($option); UsagePayload::decimal($value, false); return (int) $value;
    }
    private function timestamp(string $option): int
    {
        $value = $this->option($option); UsagePayload::decimal($value, true); UsagePayload::time((int) $value); return (int) $value;
    }
    private function recover(UsageSettlement $worker): array
    {
        $worker->recover($this->id('batch'), (string) $this->option('reason'), time()); return ['status' => 'pending'];
    }
}
