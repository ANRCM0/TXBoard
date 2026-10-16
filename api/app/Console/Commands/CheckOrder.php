<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\PublishedOrderService;
use Illuminate\Console\Command;
use App\Models\Order;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order {--limit=100 : Maximum native recovery candidates per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '订单检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $authority = app(InstallationAuthority::class)->inspect();
        $native = $authority['mode'] === 'native';
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($native && ($limit === false || $limit < 1 || $limit > 1000)) {
            $this->error('NATIVE_ORDER_RECOVERY_LIMIT_INVALID');
            return self::FAILURE;
        }
        // Pending orders only need handling after the two-hour expiry window.
        // Processing orders must remain eligible for recovery on every run.
        $query = Order::query()->useWritePdo()->where(function ($query) {
                $query->where('status', Order::STATUS_PROCESSING)
                    ->orWhere(function ($pending) {
                        $pending->where('status', Order::STATUS_PENDING)
                            ->where('created_at', '<=', time() - 7200);
                    });
            })
            ->orderBy('id', 'ASC');
        $cursorKey = $native ? 'core:native-order-recovery:'.$authority['installation_id'] : null;
        if ($native) {
            $query->where('purchase_terms_version', PublishedOrderService::VERSION);
            $cursor = filter_var(Cache::get($cursorKey, 0), FILTER_VALIDATE_INT);
            $orders = (clone $query)->where('id', '>', max(0, $cursor ?: 0))->limit($limit)->get();
            if ($orders->isEmpty()) {
                $orders = $query->limit($limit)->get();
            }
        } else {
            $orders = $query->lazyById(200);
        }
        $orders->each(function ($order) use ($cursorKey) {
            Bus::dispatch((new OrderHandleJob($order->trade_no))->onConnection('redis'));
            if ($cursorKey !== null) {
                // Rotate across runs so a damaged low-ID order cannot starve later work.
                // Losing this cursor only causes safe re-dispatch of idempotent jobs.
                Cache::put($cursorKey, (int) $order->id, 86400);
            }
        });
        return self::SUCCESS;
    }
}
