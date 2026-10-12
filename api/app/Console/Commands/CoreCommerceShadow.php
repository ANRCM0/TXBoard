<?php

namespace App\Console\Commands;

use App\Services\Subscription\CommerceShadowCodec;
use App\Services\Subscription\CommerceShadowService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class CoreCommerceShadow extends Command
{
    protected $signature = 'core:commerce-shadow {--run=} {--snapshot=} {--currency=} {--limit=100} {--retry-user=} {--retry-order=} {--reconcile=} {--cursor=0} {--isolated-copy}';
    protected $description = 'Capture isolated-copy order evidence and balanced money openings, or reconcile without changing legacy authority';

    public function handle(CommerceShadowService $commerce): int
    {
        try {
            if (!$this->option('isolated-copy')) {
                throw new InvalidArgumentException('ISOLATED_COPY_REQUIRED');
            }
            $limit = (int) CommerceShadowCodec::integer($this->option('limit'), positive: true);
            $userRetry = $this->option('retry-user');
            $orderRetry = $this->option('retry-order');
            if (($userRetry !== null && $orderRetry !== null)
                || ($this->option('reconcile') !== null && ($userRetry !== null || $orderRetry !== null))) {
                throw new InvalidArgumentException('INVALID_COMMERCE_RETRY');
            }
            if ($this->option('reconcile') !== null) {
                $result = $commerce->reconcile((string) $this->option('run'), (string) $this->option('reconcile'), (string) $this->option('cursor'), $limit);
                $ok = $result['difference_count'] === '0';
            } else {
                $type = $userRetry !== null ? 'user' : ($orderRetry !== null ? 'order' : null);
                $result = $commerce->run((string) $this->option('run'), $limit,
                    ['currency' => (string) $this->option('currency'), 'snapshot_key' => (string) $this->option('snapshot')],
                    $type, $userRetry ?? $orderRetry);
                $ok = $result['unresolved_count'] === '0';
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $code = preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage()) ? $error->getMessage() : 'COMMERCE_CAPTURE_FAILED';
            $this->line(json_encode(['error_code' => $code], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }
}
