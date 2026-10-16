<?php

namespace App\Console\Commands;

use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Console\Command;
use Throwable;

final class CoreSubscriptionsTick extends Command
{
    protected $signature = 'core:subscriptions-tick {--limit=100}';
    protected $description = 'Advance due native subscription boundaries within a bounded batch';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        try {
            $limit = $this->option('limit');
            if (!is_string($limit) || !preg_match('/\A[1-9][0-9]{0,3}\z/D', $limit) || (int) $limit > 1000) {
                $this->line('{"error_code":"NATIVE_TICK_LIMIT_INVALID"}');
                return self::FAILURE;
            }
            $result = $lifecycle->tick((int) $limit);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $this->line(json_encode(['error_code' => preg_match('/\A(?:NATIVE|INSTALLATION)_[A-Z_]{1,64}\z/D', $error->getMessage())
                ? $error->getMessage() : 'NATIVE_TICK_FAILED']));
            return self::FAILURE;
        }
    }
}
