<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use App\Services\OrderService;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order';

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
        // Pending orders only need handling after the two-hour expiry window.
        // Processing orders must remain eligible for recovery on every run.
        Order::where(function ($query) {
                $query->where('status', Order::STATUS_PROCESSING)
                    ->orWhere(function ($pending) {
                        $pending->where('status', Order::STATUS_PENDING)
                            ->where('created_at', '<=', time() - 7200);
                    });
            })
            ->orderBy('id', 'ASC')
            ->lazyById(200)
            ->each(function ($order) {
                OrderHandleJob::dispatch($order->trade_no);
            });
    }
}
