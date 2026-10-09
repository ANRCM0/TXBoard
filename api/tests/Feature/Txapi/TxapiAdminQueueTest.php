<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use App\Services\QueueMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminQueueTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/queue_control_secret/queue';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'queue_control_secret']);
    }

    public function test_queue_diagnostics_are_visible_only_to_authorized_admin(): void
    {
        $this->getJson(self::ROOT . '/snapshot')->assertStatus(403);
        Sanctum::actingAs($this->user('basic-queue@example.test'));
        $this->getJson(self::ROOT . '/failures')->assertStatus(403);
        Sanctum::actingAs($this->user('root-queue@example.test', true));

        $this->getJson('/txapi/admin/wrong/queue/snapshot')->assertStatus(404);
        $this->mock(QueueMonitorService::class)->shouldReceive('snapshot')->once()->andReturn([
            'status' => 'inactive', 'processes' => 0,
            'recent_jobs' => 0, 'pending_jobs' => 0,
        ]);
        $response = $this->getJson(self::ROOT . '/snapshot')->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.failed_last_7_days', 0)
            ->assertJsonPath('data.failed_jobs_available', true);
        $this->assertSame('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->json('request_id'));
    }

    public function test_failed_jobs_are_bounded_redacted_and_never_return_payload(): void
    {
        Sanctum::actingAs($this->user('admin-failures@example.test', true));
        $id = DB::table('failed_jobs')->insertGetId([
            'connection' => 'redis',
            'queue' => 'mail',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SendEmailJob',
                'data' => ['command' => 'internal-command-password'],
            ]),
            'exception' => "RuntimeException: password=private123 Bearer abcdefghi\n#0 trace",
            'failed_at' => now(),
        ]);

        $list = $this->getJson(self::ROOT . '/failures?limit=10')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.job', 'App\\Jobs\\SendEmailJob')
            ->assertDontSee('internal-command-password')
            ->assertDontSee('private123')
            ->assertDontSee('abcdefghi');
        $this->assertSame('no-store', $list->headers->get('Cache-Control'));

        $this->getJson(self::ROOT . '/failures/' . $id)->assertOk()
            ->assertSee('[REDACTED]', false)
            ->assertDontSee('internal-command-password')
            ->assertDontSee('private123')
            ->assertDontSee('abcdefghi');
        $this->getJson(self::ROOT . '/failures?limit=31')->assertStatus(422);
        $this->getJson(self::ROOT . '/failures/999999')->assertStatus(404)
            ->assertJsonPath('error.code', 'QUEUE_FAILURE_NOT_FOUND');
        $this->getJson(self::ROOT . '/failures/abc')->assertStatus(404);
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin,
            'banned' => 0,
        ]);
    }
}
