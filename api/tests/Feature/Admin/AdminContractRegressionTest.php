<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\GiftCardTemplate;
use App\Models\Knowledge;
use App\Models\MailTemplate;
use App\Models\Notice;
use App\Models\User;
use App\Services\AuthService;
use App\Services\StatisticalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Regression cover for the admin SPA <-> API contract defects found while
 * auditing the running backend. Every test here fails against the previous
 * implementation.
 */
class AdminContractRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $securePath;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->makeAdmin());

        $this->securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    /**
     * The admin editor seeds its form from this endpoint and posts the numbers
     * straight back, so cents here meant every edited balance was inflated 100x.
     */
    public function test_user_detail_reports_money_in_the_same_units_as_the_list(): void
    {
        $target = $this->makeUser('detail-target@example.com', 1234, 567);

        $detail = $this->getJson("/api/v2/{$this->securePath}/user/getUserInfoById?id={$target->id}");
        $detail->assertOk();
        $this->assertEqualsWithDelta(12.34, $detail->json('data.balance'), 0.00001);
        $this->assertEqualsWithDelta(5.67, $detail->json('data.commission_balance'), 0.00001);

        $list = $this->getJson("/api/v2/{$this->securePath}/user/fetch?current=1&pageSize=50");
        $list->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $target->id);
        $this->assertNotNull($row, 'the created user should appear in the list');
        $this->assertEqualsWithDelta(12.34, $row['balance'], 0.00001);
    }

    public function test_admin_audit_log_redacts_sensitive_config_values(): void
    {
        $response = $this->postJson("/api/v2/{$this->securePath}/config/save", [
            'email_password' => 'mail-secret',
            'server_token' => '1234567890123456',
            'telegram_bot_token' => 'telegram-secret',
            'turnstile_secret_key' => 'turnstile-secret',
        ]);

        $response->assertOk();

        $log = AdminAuditLog::query()->latest('id')->firstOrFail();
        $payload = json_decode((string) $log->request_data, true);

        $this->assertSame('[REDACTED]', $payload['email_password']);
        $this->assertSame('[REDACTED]', $payload['server_token']);
        $this->assertSame('[REDACTED]', $payload['telegram_bot_token']);
        $this->assertSame('[REDACTED]', $payload['turnstile_secret_key']);
    }

    public function test_regular_user_auth_payload_does_not_disclose_secure_path(): void
    {
        $user = $this->makeUser('auth-user@example.com', 0, 0, false);
        $userPayload = (new AuthService($user))->generateAuthData();

        $this->assertArrayNotHasKey('secure_path', $userPayload);

        $admin = $this->makeUser('auth-admin@example.com', 0, 0, true);
        $adminPayload = (new AuthService($admin))->generateAuthData();

        $this->assertSame($this->securePath, $adminPayload['secure_path']);
    }

    public function test_secure_path_rotation_takes_effect_without_application_restart(): void
    {
        $oldPath = $this->securePath;
        $newPath = 'rotated-admin-path';

        $this->postJson("/api/v2/{$oldPath}/config/save", [
            'secure_path' => $newPath,
        ])->assertOk();

        $this->getJson("/api/v2/{$oldPath}/config/fetch?key=safe")
            ->assertNotFound();

        $this->getJson("/api/v2/{$newPath}/config/fetch?key=safe")
            ->assertOk()
            ->assertJsonPath('data.safe.secure_path', $newPath);

        $this->securePath = $newPath;
    }

    /**
     * last_page is what drives the audit log's next-page control.
     */
    public function test_audit_log_returns_the_standard_paginator(): void
    {
        AdminAuditLog::create([
            'admin_id' => 1,
            'action' => 'user.update',
            'method' => 'POST',
            'uri' => '/api/v2/example/user/update',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $response = $this->getJson("/api/v2/{$this->securePath}/system/getAuditLog?current=1&page_size=10");

        $response->assertOk();
        $response->assertJsonStructure(['total', 'current_page', 'per_page', 'last_page', 'data']);
        $this->assertSame(1, $response->json('total'));
        $this->assertSame(1, $response->json('last_page'));
    }

    public function test_traffic_reset_logs_return_a_top_level_paginator(): void
    {
        $response = $this->getJson("/api/v2/{$this->securePath}/traffic-reset/logs?per_page=10");

        $response->assertOk();
        $response->assertJsonStructure(['total', 'current_page', 'per_page', 'last_page', 'data']);
        $this->assertArrayNotHasKey('pagination', $response->json());
    }

    /**
     * The coupon form renders "不限" when both bounds are empty, so the API has
     * to accept a coupon with no validity window.
     */
    public function test_coupon_can_be_created_without_a_validity_window(): void
    {
        $response = $this->postJson("/api/v2/{$this->securePath}/coupon/generate", [
            'name' => 'no-expiry',
            'type' => 1,
            'value' => 100,
            'limit_use' => 1,
        ]);

        $response->assertOk();

        $coupon = Coupon::where('name', 'no-expiry')->first();
        $this->assertNotNull($coupon);
        $this->assertNull($coupon->started_at);
        $this->assertNull($coupon->ended_at);
    }

    public function test_admin_notice_list_paginates_and_filters_by_title(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $index => $name) {
            Notice::create([
                'title' => "notice-{$name}",
                'content' => '<p>x</p>',
                'show' => 1,
                'sort' => $index,
            ]);
        }

        $page = $this->getJson("/api/v2/{$this->securePath}/notice/fetch?current=1&pageSize=2");
        $page->assertOk();
        $this->assertSame(3, $page->json('total'));
        $this->assertSame(2, $page->json('last_page'));
        $this->assertCount(2, $page->json('data'));

        $filtered = $this->getJson("/api/v2/{$this->securePath}/notice/fetch?current=1&pageSize=20&title=beta");
        $filtered->assertOk();
        $this->assertSame(1, $filtered->json('total'));
    }

    public function test_admin_knowledge_list_paginates_and_filters(): void
    {
        $this->makeKnowledge('kb-one', 'catA');
        $this->makeKnowledge('kb-two', 'catA');
        $this->makeKnowledge('kb-three', 'catB');

        $page = $this->getJson("/api/v2/{$this->securePath}/knowledge/fetch?current=1&pageSize=2");
        $page->assertOk();
        $this->assertSame(3, $page->json('total'));
        $this->assertSame(2, $page->json('last_page'));
        $this->assertCount(2, $page->json('data'));

        $byCategory = $this->getJson("/api/v2/{$this->securePath}/knowledge/fetch?current=1&pageSize=20&category=catB");
        $byCategory->assertOk();
        $this->assertSame(1, $byCategory->json('total'));

        $byTitle = $this->getJson("/api/v2/{$this->securePath}/knowledge/fetch?current=1&pageSize=20&title=kb-two");
        $byTitle->assertOk();
        $this->assertSame(1, $byTitle->json('total'));
    }

    /**
     * The mapper builds type_name/codes_count/used_count and the table renders
     * them; returning the raw paginator discarded all three.
     */
    public function test_gift_card_templates_keep_their_enriched_fields(): void
    {
        GiftCardTemplate::create([
            'name' => 'template-a',
            'type' => 1,
            'rewards' => ['balance' => 100],
            'admin_id' => 1,
            'status' => 1,
            'sort' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $response = $this->getJson("/api/v2/{$this->securePath}/gift-card/templates");

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertIsArray($row);
        $this->assertArrayHasKey('type_name', $row);
        $this->assertArrayHasKey('codes_count', $row);
        $this->assertArrayHasKey('used_count', $row);
        $this->assertSame(0, $row['codes_count']);
        $this->assertSame(0, $row['used_count']);
    }

    /**
     * MailTemplate::getMeta() is typed `string $name`, so an absent query
     * parameter used to raise a TypeError and answer 500 instead of 422.
     */
    public function test_mail_template_get_validates_name_instead_of_failing(): void
    {
        $this->getJson("/api/v2/{$this->securePath}/mail/template/get")
            ->assertStatus(422);

        $name = array_key_first(MailTemplate::TEMPLATES);
        $ok = $this->getJson("/api/v2/{$this->securePath}/mail/template/get?name={$name}");
        $ok->assertOk();
        $this->assertSame($name, $ok->json('data.name'));
    }

    /**
     * AdminRoute.php has always routed /stat/getRanking, but the controller
     * method did not exist, so every call answered 500 (BadMethodCallException).
     *
     * StatisticalService talks to Redis through the raw Redis facade, which the
     * suite does not provide, so it is mocked: what is under test here is that
     * the route resolves to a real method, that a time window is applied (the
     * builders throw "Illegal operator and value combination" without one) and
     * that the result comes back in the standard envelope.
     */
    public function test_stat_ranking_endpoint_is_implemented(): void
    {
        $this->mock(StatisticalService::class, function ($mock) {
            $mock->shouldReceive('setStartAt')->once()->with(Mockery::type('int'));
            $mock->shouldReceive('setEndAt')->once()->with(Mockery::type('int'));
            $mock->shouldReceive('getRanking')
                ->once()
                ->with('server_traffic_rank', 20)
                ->andReturn([['id' => '7', 'value' => 12]]);
        });

        $response = $this->getJson(
            "/api/v2/{$this->securePath}/stat/getRanking?type=server_traffic_rank"
        );

        $response->assertOk();
        $this->assertSame('success', $response->json('status'));
        $this->assertSame([['id' => '7', 'value' => 12]], $response->json('data'));

        $this->getJson("/api/v2/{$this->securePath}/stat/getRanking?type=bogus")
            ->assertStatus(422);
    }

    private function makeKnowledge(string $title, string $category): Knowledge
    {
        return Knowledge::create([
            'title' => $title,
            'category' => $category,
            'language' => 'zh-CN',
            'body' => '<p>x</p>',
            'show' => 1,
            'sort' => 0,
        ]);
    }

    private function makeAdmin(): User
    {
        return $this->makeUser('contract-admin@example.com', 0, 0, true);
    }

    private function makeUser(
        string $email,
        int $balance,
        int $commission,
        bool $isAdmin = false,
    ): User {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => sprintf('00000000-0000-0000-0000-%012d', $sequence),
            'token' => str_pad((string) $sequence, 32, 'a', STR_PAD_LEFT),
            'balance' => $balance,
            'commission_balance' => $commission,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => $isAdmin ? 1 : 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
