<?php

namespace Tests\Feature\Txapi;

use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/native_analytics_secret/analytics';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'native_analytics_secret']);
    }

    public function test_all_dashboard_reads_enforce_admin_identity_and_rotating_path(): void
    {
        $this->getJson(self::ROOT . '/dashboard')->assertStatus(403);
        $this->getJson(self::ROOT . '/orders/chart')->assertStatus(403);
        $this->getJson(self::ROOT . '/traffic/rank?type=user')->assertStatus(403);

        Sanctum::actingAs($this->user('stats-ordinary@example.test'));
        $this->getJson(self::ROOT . '/dashboard')->assertStatus(403);
        $this->getJson(self::ROOT . '/rankings?type=invite_rank')->assertStatus(403);

        Sanctum::actingAs($this->user('stats-admin@example.test', true));
        $this->getJson('/txapi/admin/incorrect/analytics/dashboard')->assertStatus(404);
        $dashboard = $this->getJson(self::ROOT . '/dashboard')->assertOk()
            ->assertJsonPath('data.totalUsers', 2)
            ->assertJsonPath('data.todayTraffic.total', 0)
            ->assertJsonStructure(['request_id', 'data' => ['todayIncome', 'currentMonthIncome', 'totalUsers']]);
        $this->assertStringContainsString('no-store', (string) $dashboard->headers->get('Cache-Control'));
        $this->getJson(self::ROOT . '/overview')->assertOk()
            ->assertJsonPath('data.ticket_pending_total', 0);
    }

    public function test_order_chart_preserves_daily_paid_totals_and_rejects_unbounded_windows(): void
    {
        Sanctum::actingAs($this->user('chart-admin@example.test', true));
        $day = strtotime('today');
        Stat::create([
            'record_at' => $day, 'record_type' => 'd',
            'paid_total' => 1500, 'paid_count' => 2,
            'commission_total' => 300, 'commission_count' => 1,
            'order_total' => 1500, 'order_count' => 2,
            'register_count' => 1, 'invite_count' => 0,
            'transfer_used_total' => '0',
        ]);

        $chart = $this->getJson(self::ROOT . '/orders/chart?start_date=' .
            date('Y-m-d', $day) . '&end_date=' . date('Y-m-d', $day))
            ->assertOk();
        $chart->assertJsonPath('data.summary.paid_total', 1500)
            ->assertJsonPath('data.list.0.paid_total', 1500);
        $this->getJson(self::ROOT . '/orders/chart?start_date=2001-01-01&end_date=2026-01-01')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->getJson(self::ROOT . '/orders/chart?start_date=2026-05-01&end_date=2026-04-01')
            ->assertStatus(422);
        $this->getJson(self::ROOT . '/records?type=register_count&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.value', 1);
        $this->getJson(self::ROOT . '/records?type=invalid')->assertStatus(422);
    }

    public function test_ranking_windows_and_traffic_history_are_bounded(): void
    {
        Sanctum::actingAs($this->user('rank-admin@example.test', true));
        $this->getJson(self::ROOT . '/traffic/rank?type=invalid')->assertStatus(422);
        $this->getJson(self::ROOT . '/rankings?type=invalid')->assertStatus(422);
        $this->getJson(self::ROOT . '/traffic/rank?type=node&start_time=1000000000&end_time=1600000000')
            ->assertStatus(422);
        $this->getJson(self::ROOT . '/rankings?type=invite_rank&limit=101')
            ->assertStatus(422);
        $this->getJson(self::ROOT . '/users/4/traffic?per_page=101')->assertStatus(422);
        $this->getJson(self::ROOT . '/traffic/rank?type=node')
            ->assertOk()->assertJsonPath('data', []);

        $day = strtotime('today');
        StatUser::create([
            'user_id' => 5, 'server_rate' => 1, 'u' => 42, 'd' => 10,
            'record_type' => 'd', 'record_at' => $day,
        ]);
        $this->getJson(self::ROOT . '/users/5/traffic?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user_id', 5);
        $this->getJson(self::ROOT . '/users/6/traffic')
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_legacy_tx_admin_statistics_routes_are_retired(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route) => $route->uri())->all();
        foreach (['getOverride', 'getStats', 'getServerLastRank', 'getServerYesterdayRank',
            'getOrder', 'getStatUser', 'getRanking', 'getStatRecord', 'getTrafficRank',
            'queue/snapshot', 'queue/failures', 'queue/failure'] as $path) {
            $this->assertNotContains('api/v2/{admin_path}/stat/' . $path, $uris);
        }
        // The user-facing /txapi/me/dashboard-stats is separate and remains available.
        $this->assertContains('txapi/me/dashboard-stats', $uris);
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email, 'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin,
            'banned' => 0,
        ]);
    }
}
