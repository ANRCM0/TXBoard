<?php

namespace App\Console\Commands;

use App\Services\Subscription\PlanCatalogShadowService;
use App\Services\Subscription\PlanCatalogShadowReconciler;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class CoreCatalogShadow extends Command
{
    protected $signature = 'core:catalog-shadow {--run=} {--limit=100} {--billing-timezone=} {--currency=} {--retry-plan=} {--isolated-copy} {--reconcile} {--after-id=0}';
    protected $description = 'Capture bounded current catalog shadows on an isolated copy without changing legacy sale authority';

    public function handle(PlanCatalogShadowService $catalog, PlanCatalogShadowReconciler $reconciler): int
    {
        if (!$this->option('isolated-copy')) {
            $this->line(json_encode(['error_code' => 'ISOLATED_COPY_REQUIRED'], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
        try {
            if ($this->option('reconcile')) {
                if ($this->option('retry-plan') !== null || $this->option('billing-timezone') !== null || $this->option('currency') !== null) {
                    throw new InvalidArgumentException('CATALOG_RECONCILE_OPTIONS_CONFLICT');
                }
                $result = $reconciler->reconcile((string) $this->option('run'),
                    $this->positiveInteger($this->option('limit')), $this->nonnegativeInteger($this->option('after-id')));
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                return $result['page_ok'] ? self::SUCCESS : self::FAILURE;
            }
            if ($this->option('after-id') !== '0') { throw new InvalidArgumentException('CATALOG_RECONCILE_OPTIONS_CONFLICT'); }
            $retry = $this->option('retry-plan') === null ? null : $this->positiveInteger($this->option('retry-plan'));
            $result = $catalog->run((string) $this->option('run'), $this->positiveInteger($this->option('limit')), [
                'billing_timezone' => (string) $this->option('billing-timezone'), 'currency' => (string) $this->option('currency'),
            ], $retry);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $result['unresolved_count'] === '0' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $code = preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage()) ? $error->getMessage() : 'CATALOG_CAPTURE_FAILED';
            $this->line(json_encode(['error_code' => $code], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }

    private function positiveInteger(mixed $input): int
    {
        $value = (string) $input;
        if (!preg_match('/^[1-9][0-9]*$/D', $value) || (string) (int) $value !== $value) {
            throw new InvalidArgumentException('INVALID_CATALOG_BOUNDS');
        }
        return (int) $value;
    }

    private function nonnegativeInteger(mixed $input): int
    {
        $value = (string) $input;
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || (string) (int) $value !== $value) {
            throw new InvalidArgumentException('INVALID_CATALOG_BOUNDS');
        }
        return (int) $value;
    }
}
