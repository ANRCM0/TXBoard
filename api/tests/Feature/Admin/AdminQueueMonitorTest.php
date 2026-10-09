<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\QueueMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminQueueMonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = '/txapi/admin/'.(string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::create([
            'email' => 'queue-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000021',
            'token' => str_repeat('a', 32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 1,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]));
    }

    public function test_admin_can_read_queue_snapshot_without_horizon_running(): void
    {
        $this->admin();
        $mock = $this->mock(QueueMonitorService::class);
        $mock->shouldReceive('snapshot')->once()->andReturn([
            'status' => 'inactive',
            'processes' => 0,
            'recent_jobs' => 0,
            'jobs_per_minute' => 0,
            'pending_jobs' => 0,
            'wait_seconds' => null,
            'longest_wait_queue' => null,
        ]);

        $this->getJson($this->path.'/queue/snapshot')
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.failed_last_7_days', 0);
    }

    public function test_admin_can_read_redacted_failed_job_and_not_serialized_payload(): void
    {
        $this->admin();
        $id = DB::table('failed_jobs')->insertGetId([
            'connection' => 'redis',
            'queue' => 'send_email',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SendEmailJob',
                'data' => ['command' => 'email-password-private'],
            ]),
            'exception' => "RuntimeException: SMTP auth password=private123 Bearer abcdefghi\n#0 trace",
            'failed_at' => now(),
        ]);

        $this->getJson($this->path.'/queue/failures')->assertOk()
            ->assertJsonPath('data.0.job', 'App\\Jobs\\SendEmailJob')
            ->assertDontSee('email-password-private')
            ->assertDontSee('private123');

        $this->getJson($this->path.'/queue/failures/'.$id)->assertOk()
            ->assertJsonPath('data.queue', 'send_email')
            ->assertSee('[REDACTED]', false)
            ->assertDontSee('private123')
            ->assertDontSee('abcdefghi')
            ->assertDontSee('email-password-private');
    }

    public function test_queue_routes_require_admin_authentication(): void
    {
        $this->getJson($this->path.'/queue/snapshot')->assertStatus(403);
        $this->getJson($this->path.'/queue/failures')->assertStatus(403);
        $this->getJson($this->path.'/queue/failures/1')->assertStatus(403);
    }

    public function test_missing_job_returns_404(): void
    {
        $this->admin();
        $this->getJson($this->path.'/queue/failures/999999')->assertNotFound();
    }
}
