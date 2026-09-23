<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\StatController;
use App\Services\StatisticalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDashboardStatsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_snapshot_uses_bounded_queries_and_cache(): void
    {
        Cache::flush();
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $controller = new StatController($this->createMock(StatisticalService::class));
        $first = $controller->getStats();
        $firstQueryCount = $queryCount;
        $second = $controller->getStats();

        $this->assertLessThanOrEqual(6, $firstQueryCount);
        $this->assertSame($firstQueryCount, $queryCount);
        $this->assertSame($first, $second);
        $this->assertSame(0, $first['data']['totalUsers']);
        $this->assertSame(0, $first['data']['todayTraffic']['total']);
    }
}
