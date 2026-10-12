<?php

namespace App\Console\Commands;

use App\Services\Subscription\SubscriptionShadowReconcile;
use Illuminate\Console\Command;
use Throwable;

final class CoreSubscriptionsReconcile extends Command
{
    protected $signature = 'core:subscriptions-reconcile {--run=} {--limit=100} {--after-id=0} {--json}';

    protected $description = 'Read-only per-user subscription shadow reconciliation (field names only)';

    public function handle(SubscriptionShadowReconcile $reconcile): int
    {
        try {
            foreach (['limit', 'after-id'] as $key) {
                $value = (string) $this->option($key);
                if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || (string) (int) $value !== $value) {
                    throw new \InvalidArgumentException('INVALID_RECONCILE_BOUNDS');
                }
            }
            $result = $reconcile->run((string) $this->option('run'), (int) $this->option('limit'), (int) $this->option('after-id'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $result['passed'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $code = preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage()) ? $error->getMessage() : 'RECONCILE_FAILED';
            $this->line(json_encode(['error_code' => $code], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }
}
