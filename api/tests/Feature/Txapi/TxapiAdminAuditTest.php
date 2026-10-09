<?php

namespace Tests\Feature\Txapi;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_audit_requires_correct_dynamic_path_and_administrator_authorization(): void
    {
        admin_setting(['secure_path' => 'tx_audit_secure']);
        $this->getJson('/txapi/admin/tx_audit_secure/audit-logs')->assertStatus(403);
        Sanctum::actingAs($this->user('native-audit-user@example.test', false));
        $this->getJson('/txapi/admin/tx_audit_secure/audit-logs')->assertStatus(403);

        Sanctum::actingAs($this->user('native-audit-admin@example.test', true));
        $this->getJson('/txapi/admin/wrong-guess/audit-logs')->assertStatus(404);
        $this->getJson('/txapi/admin/tx_audit_secure/audit-logs')
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);

        admin_setting(['secure_path' => 'tx_audit_rotated']);
        $this->getJson('/txapi/admin/tx_audit_secure/audit-logs')->assertStatus(404);
        $this->getJson('/txapi/admin/tx_audit_rotated/audit-logs')->assertOk();
    }

    public function test_native_audit_is_bounded_paginated_and_redacts_even_historical_secrets(): void
    {
        admin_setting(['secure_path' => 'audit_scoped_path']);
        $admin = $this->user('native-audit-operator@example.test', true);
        $this->user('native-audit-other@example.test', true);
        foreach ([1, 2] as $index) {
            AdminAuditLog::create([
                'admin_id' => $admin->id, 'action' => 'config.save',
                'method' => 'POST',
                'uri' => '/api/v2/example/config/save',
                'request_data' => json_encode([
                    'remark' => 'safe-' . $index,
                    'config' => [
                        'password' => 'should-not-leak',
                        'api_key' => 'should-not-leak-either',
                        'nested' => ['authorization' => 'Bearer private'],
                    ],
                ]),
                'ip' => '127.0.0.1',
                'created_at' => time(), 'updated_at' => time(),
            ]);
        }
        Sanctum::actingAs($admin);
        $url = '/txapi/admin/audit_scoped_path/audit-logs';
        $page = $this->getJson($url . '?page=1&per_page=1&action=config.save');
        $page->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.admin.email', $admin->email);
        $this->assertStringNotContainsString('should-not-leak', $page->getContent());
        $this->assertStringNotContainsString('Bearer private', $page->getContent());
        $this->assertStringContainsString('[REDACTED]', $page->getContent());
        $page->assertJsonPath('data.0.action', 'config.save');
        $this->getJson($url . '?page=2&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($url . '?page=1&per_page=101')->assertStatus(422);
        $this->getJson($url . '?page=-1')->assertStatus(422);
        $this->getJson($url . '?admin_id=foo')->assertStatus(422);
    }


    public function test_legacy_historical_audit_entries_do_not_reveal_queries_admin_paths_or_credential_payloads(): void
    {
        admin_setting(['secure_path' => 'current-safe-admin']);
        $admin = $this->user('audit-historic-reader@example.test', true);
        AdminAuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'txapi_admin_rotated-old-path_content.delete',
            'method' => 'DELETE',
            'uri' => '/txapi/admin/rotated-old-path/content/knowledge/77?token=historic-secret&api_key=unsafe',
            'request_data' => json_encode([
                'remark' => 'public text',
                'payment_config' => ['secret_key' => 'historic-password', 'nested' => ['auth_token' => 'Bearer old-secret']],
            ]),
            'ip' => '127.0.0.1', 'created_at' => time(), 'updated_at' => time(),
        ]);
        Sanctum::actingAs($admin);
        foreach ([
            '/txapi/admin/current-safe-admin/audit-logs',
            '/api/v2/current-safe-admin/system/getAuditLog',
        ] as $endpoint) {
            $res = $this->getJson($endpoint)->assertOk();
            $body = $res->getContent();
            foreach (['rotated-old-path', 'historic-secret', 'historic-password', 'Bearer old-secret', 'api_key=unsafe'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }
            $this->assertStringContainsString('content/knowledge/77', $body);
        }
    }

    public function test_admin_unsafe_request_audit_stores_normalized_action_and_query_free_uri(): void
    {
        admin_setting(['secure_path' => 'rotating-admin-private-path']);
        Sanctum::actingAs($this->user('audit-mutation-operator@example.test', true));
        // Even a rejected/failed valid route must be logged without carrying
        // tokens supplied in unexpected query params.
        $this->postJson(
            '/txapi/admin/rotating-admin-private-path/plans/999999/flags?token=leaky-token&password=bad',
            ['show' => true, 'config' => ['api_key' => 'should-not-persist']]
        )->assertStatus(404);

        $audit = AdminAuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('/txapi/admin/{admin_path}/plans/999999/flags', $audit->uri);
        $this->assertSame('plans_999999.flags', $audit->action);
        $this->assertStringNotContainsString('rotating-admin-private-path', (string) $audit->uri);
        $this->assertStringNotContainsString('leaky-token', (string) $audit->uri);
        $this->assertStringNotContainsString('should-not-persist', (string) $audit->request_data);
        $this->assertStringContainsString('[REDACTED]', (string) $audit->request_data);
    }

    private function user(string $email, bool $admin): User
    {
        return User::create([
            'email' => $email, 'password' => 'test-only-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin,
            'balance' => 0, 'commission_balance' => 0, 'banned' => 0,
        ]);
    }
}
