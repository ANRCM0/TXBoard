<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OrderHandleJob;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class OrderHandleJobFailureTest extends TestCase
{
    public function test_failed_job_logs_trade_number_and_exception(): void
    {
        Log::shouldReceive('error')->once()->with(
            'Order fulfillment job exhausted its retries',
            Mockery::on(fn (array $context) =>
                $context['trade_no'] === 'test-trade-123'
                && $context['error'] === 'simulated failure'
            )
        );

        (new OrderHandleJob('test-trade-123'))->failed(new \RuntimeException('simulated failure'));
    }

    public function test_retry_backoff_is_bounded(): void
    {
        $job = new OrderHandleJob('test-trade-123');

        $this->assertSame([10, 30, 60], $job->backoff());
        $this->assertSame(30, $job->timeout);
        $this->assertSame(3, $job->tries);
    }
}
