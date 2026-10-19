<?php

namespace App\Console\Commands;

use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\UsageV2\NativeRuntimeWorker;
use Illuminate\Console\Command;
use Throwable;

final class CoreNativeWork extends Command
{
    protected $signature = 'core:native-work {--limit=100}';
    protected $description = 'Recover native usage, paid-order fulfillment and credential synchronization';

    public function handle(NativeRuntimeWorker $worker, NativeBillingRecovery $billing,
        \App\Domains\Support\NativeTicketAttachments $attachments): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 1000) { throw new \RuntimeException('NATIVE_WORK_LIMIT_INVALID'); }
            $this->line(json_encode($worker->run($limit) + [
                'attachments_expired' => $attachments->expire(min($limit, 100)),
                'payments' => $billing->recoverPayments($limit), 'billing' => $billing->recover($limit)],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D', $error->getMessage()) ? $error->getMessage() : 'NATIVE_WORK_FAILED';
            $this->line(json_encode(['error_code' => $code]));
            return self::FAILURE;
        }
    }
}
