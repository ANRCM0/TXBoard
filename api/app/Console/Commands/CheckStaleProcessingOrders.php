<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStaleProcessingOrders extends Command
{
    protected $signature = 'check:stale-processing-orders {--minutes=15 : Minimum processing age in minutes}';

    protected $description = 'Report orders stuck in processing beyond the configured threshold';

    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 1) {
            $this->error('The minutes option must be a positive integer.');
            return self::FAILURE;
        }

        $cutoff = time() - ($minutes * 60);
        $query = Order::query()
            ->where('status', Order::STATUS_PROCESSING)
            ->where('updated_at', '<=', $cutoff);

        $count = (clone $query)->count();
        if ($count === 0) {
            return self::SUCCESS;
        }

        $sample = $query->orderBy('id')->limit(20)->pluck('trade_no')->all();
        Log::warning('Orders remain in processing beyond threshold', [
            'count' => $count,
            'threshold_minutes' => $minutes,
            'sample_trade_nos' => $sample,
        ]);

        $this->warn("{$count} orders have remained processing for at least {$minutes} minutes.");
        return self::SUCCESS;
    }
}
