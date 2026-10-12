<?php

namespace App\Console\Commands;

use App\Services\Subscription\SubscriptionShadowBackfill;
use Illuminate\Console\Command;
use Throwable;

final class CoreSubscriptionsBackfill extends Command
{
    protected $signature = 'core:subscriptions-backfill {--run=} {--limit=100} {--billing-timezone=} {--currency=} {--retry-user=} {--isolated-copy}';

    protected $description = 'Backfill subscription shadows on an explicitly isolated copy (legacy authority remains)';

    public function handle(SubscriptionShadowBackfill $backfill): int
    {
        if (!$this->option('isolated-copy')) {
            $this->line(json_encode(['error_code' => 'ISOLATED_COPY_REQUIRED'], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
        try {
            $limit = $this->positiveInteger($this->option('limit'));
            $retry = $this->option('retry-user') === null ? null : $this->positiveInteger($this->option('retry-user'));
            $result = $backfill->run((string) $this->option('run'), $limit, [
                'billing_timezone' => (string) $this->option('billing-timezone'),
                'currency' => (string) $this->option('currency'),
            ], $retry);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            foreach ($result['results'] as $row) {
                if (in_array($row['status'], ['exception', 'source_changed'], true)) {
                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage()) ? $error->getMessage() : 'BACKFILL_FAILED';
            $this->line(json_encode(['error_code' => $code], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }

    private function positiveInteger(mixed $input): int
    {
        $value = (string) $input;
        if (!preg_match('/^[1-9][0-9]*$/D', $value) || (string) (int) $value !== $value) {
            throw new \InvalidArgumentException('INVALID_BACKFILL_BOUNDS');
        }

        return (int) $value;
    }
}
