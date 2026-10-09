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
