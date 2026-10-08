<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $tradeNo;

    public $tries = 3;
    public $timeout = 30;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Order fulfillment job exhausted its retries', [
            'trade_no' => $this->tradeNo,
            'error' => $exception?->getMessage(),
        ]);
    }
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->tradeNo = $tradeNo;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $order = Order::where('trade_no', $this->tradeNo)->first();
        if (!$order) return;
        $orderService = new OrderService($order);
        switch ($order->status) {
            // cancel
            case Order::STATUS_PENDING:
                if ($order->created_at <= (time() - 3600 * 2)) {
                    $orderService->cancel();
                }
                break;
            case Order::STATUS_PROCESSING:
                try {
                    $orderService->open();
                } catch (\Throwable $exception) {
                    Log::error('Order fulfillment attempt failed', [
                        'trade_no' => $this->tradeNo,
                        'order_id' => $order->id,
                        'error' => $exception->getMessage(),
                    ]);
                    throw $exception;
                }
                break;
        }
    }
}
