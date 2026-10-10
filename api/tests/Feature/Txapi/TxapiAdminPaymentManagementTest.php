<?php

namespace Tests\Feature\Txapi;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminPaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/payment_control_secret/payment-methods';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'secure_path' => 'payment_control_secret',
            'app_url' => 'https://panel.example.test',
        ]);
    }

    public function test_payment_credentials_are_admin_only_and_secure_path_is_enforced(): void
    {
        $payment = $this->method('only-admin');
        $this->getJson(self::ROOT)->assertStatus(403);
        Sanctum::actingAs($this->account('regular-pay@example.test'));
        $this->getJson(self::ROOT)->assertStatus(403);
        $this->postJson(self::ROOT, $this->payload())->assertStatus(403);

        Sanctum::actingAs($this->account('admin-pay@example.test', true));
        $this->getJson('/txapi/admin/guessed/payment-methods')->assertStatus(404);
        $response = $this->getJson(self::ROOT)->assertOk()
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.config.key', 'private-gateway-secret');
        $response->assertJsonPath('data.0.notify_url',
            url('/txapi/payment/webhook/EPay/only-admin'));
        $this->assertNotEmpty($response->json('request_id'));

        // The native callback path remains authoritative with a custom host.
        $payment->notify_domain = 'https://payment-notify.example.test';
        $payment->saveOrFail();
        $this->getJson(self::ROOT)->assertOk()
            ->assertJsonPath('data.0.notify_url',
                'https://payment-notify.example.test/txapi/payment/webhook/EPay/only-admin');
    }

    public function test_native_create_update_preserves_provider_identity_and_validates_fee(): void
    {
        Sanctum::actingAs($this->account('mutation-admin@example.test', true));
        $created = $this->postJson(self::ROOT, $this->payload())
            ->assertStatus(201)->json('data.id');
        $this->assertIsInt($created);
        $this->assertSame('EPay', Payment::findOrFail($created)->payment);

        $this->putJson(self::ROOT . '/' . $created, array_replace(
            $this->payload(), ['payment' => 'ChangedProvider']
        ))->assertStatus(422);
        $this->assertSame('EPay', Payment::findOrFail($created)->payment);

        $this->putJson(self::ROOT . '/' . $created, array_replace(
            $this->payload(), ['name' => 'Updated', 'handling_fee_fixed' => 20]
        ))->assertOk()->assertJsonPath('data.id', $created);
        $this->assertSame('Updated', Payment::findOrFail($created)->name);
        $this->assertSame(20, (int) Payment::findOrFail($created)->handling_fee_fixed);

        $this->postJson(self::ROOT, array_replace(
            $this->payload(), ['handling_fee_percent' => 150]
        ))->assertStatus(422);
        $this->postJson(self::ROOT, array_replace(
            $this->payload(), ['notify_domain' => 'javascript:alert(1)']
        ))->assertStatus(422);
        $this->putJson(self::ROOT . '/999999', $this->payload())->assertStatus(404);
    }

    public function test_toggle_and_sort_are_admin_scoped_and_reject_partial_invalid_updates(): void
    {
        $first = $this->method('sort-a');
        $second = $this->method('sort-b');
        Sanctum::actingAs($this->account('sorting-admin@example.test', true));

        $this->patchJson(self::ROOT . '/' . $first->id . '/toggle')
            ->assertOk()->assertJsonPath('data.enable', false);
        $this->assertFalse((bool) $first->fresh()->enable);

        $this->putJson(self::ROOT . '/sort', ['ids' => [$second->id, $first->id]])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(1, (int) $second->fresh()->sort);
        $this->assertSame(2, (int) $first->fresh()->sort);

        $this->putJson(self::ROOT . '/sort', ['ids' => [$first->id, 999999]])
            ->assertStatus(422);
        $this->putJson(self::ROOT . '/sort', ['ids' => [$first->id, $first->id]])
            ->assertStatus(422);
        $this->assertSame(1, (int) $second->fresh()->sort);
        $this->assertSame(2, (int) $first->fresh()->sort);
    }

    private function account(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'test-only-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin,
            'banned' => 0,
        ]);
    }

    private function method(string $uuid): Payment
    {
        return Payment::create([
            'uuid' => $uuid,
            'payment' => 'EPay',
            'name' => 'Test gateway',
            'config' => ['key' => 'private-gateway-secret'],
            'enable' => true,
            'sort' => 0,
        ]);
    }

    private function payload(): array
    {
        return [
            'name' => 'Test gateway',
            'payment' => 'EPay',
            'config' => ['key' => 'secret'],
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 1.5,
        ];
    }
}
