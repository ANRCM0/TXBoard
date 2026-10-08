<?php

namespace Tests\Feature\Payment;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\Plugin;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetiredCorePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRED = [
        'btcpay' => 'BTCPay',
        'coinbase' => 'Coinbase',
        'coin_payments' => 'CoinPayments',
        'mgate' => 'MGate',
    ];

    public function test_retired_integrations_are_not_bundled_as_core_plugins(): void
    {
        foreach (['Btcpay', 'Coinbase', 'CoinPayments', 'Mgate'] as $directory) {
            $this->assertDirectoryDoesNotExist(base_path("plugins-core/{$directory}"));
        }

        foreach (array_keys(self::RETIRED) as $code) {
            $this->assertNotContains($code, Plugin::PROTECTED_PLUGINS);
        }
        $this->assertDirectoryExists(base_path('plugins-core/Epay'));
        $this->assertDirectoryExists(base_path('plugins-core/AlipayF2f'));
    }

    public function test_upgrade_disables_retired_gateways_without_deleting_configuration_or_orders(): void
    {
        foreach (self::RETIRED as $code => $method) {
            Plugin::create([
                'name' => $method,
                'code' => $code,
                'type' => 'payment',
                'version' => '1.0.0',
                'is_enabled' => true,
                'config' => '{"merchant":"preserve-me"}',
            ]);
            $this->makePayment($method, true);
        }
        $active = $this->makePayment('EPay', true);
        $legacy = Payment::where('payment', 'BTCPay')->firstOrFail();

        $orderId = \Illuminate\Support\Facades\DB::table('v2_order')->insertGetId([
            'user_id' => 1,
            'plan_id' => 1,
            'payment_id' => $legacy->id,
            'type' => 1,
            'period' => 'month_price',
            'trade_no' => 'legacy-retired-order-001',
            'total_amount' => 1000,
            'status' => 3,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $migration = require base_path('database/migrations/2026_10_08_000001_retire_legacy_core_payment_plugins.php');
        $migration->up();

        foreach (self::RETIRED as $code => $method) {
            $plugin = Plugin::where('code', $code)->firstOrFail();
            $payment = Payment::where('payment', $method)->firstOrFail();
            $this->assertFalse($plugin->is_enabled);
            $this->assertFalse($payment->enable);
            $this->assertSame('{"merchant":"preserve-me"}', $plugin->config);
            $this->assertNotEmpty($payment->config);
        }
        $this->assertTrue($active->fresh()->enable);
        $this->assertSame($legacy->id, \Illuminate\Support\Facades\DB::table('v2_order')->where('id', $orderId)->value('payment_id'));

        // Rerunning the operation must not alter already disabled records.
        $migration->up();
        $this->assertSame(4, Plugin::whereIn('code', array_keys(self::RETIRED))->count());
    }

    public function test_missing_package_disables_registration_without_deleting_it(): void
    {
        $plugin = Plugin::create([
            'name' => 'BTCPay',
            'code' => 'btcpay',
            'type' => 'payment',
            'version' => '1.0.0',
            'is_enabled' => true,
            'config' => '{"token":"keep-existing-config"}',
        ]);

        app(PluginManager::class)->getEnabledPlugins();

        $this->assertDatabaseHas('v2_plugins', [
            'id' => $plugin->id,
            'is_enabled' => false,
        ]);
        $this->assertSame('{"token":"keep-existing-config"}', $plugin->fresh()->config);
    }

    public function test_removed_payment_method_throws_domain_error_instead_of_php_error(): void
    {
        $this->expectException(ApiException::class);
        new PaymentService('BTCPay');
    }

    public function test_client_hides_retired_gateway_even_if_old_enable_flag_is_true(): void
    {
        $this->makePayment('BTCPay', true);
        $this->makePayment('EPay', true);
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/v1/user/order/getPaymentMethod');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_admin_cannot_reactivate_gateway_without_a_plugin(): void
    {
        $payment = $this->makePayment('BTCPay', false);
        Sanctum::actingAs($this->makeUser(true));
        $securePath = admin_setting('secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

        $this->postJson("/api/v2/{$securePath}/payment/show", ['id' => $payment->id])
            ->assertStatus(400);
        $this->assertFalse($payment->fresh()->enable);
    }

    private function makePayment(string $method, bool $enabled): Payment
    {
        return Payment::create([
            'uuid' => str_pad((string) (Payment::count() + 1), 32, '0', STR_PAD_LEFT),
            'name' => "{$method} old merchant",
            'payment' => $method,
            'enable' => $enabled,
            'config' => ['merchant' => 'preserve-me'],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function makeUser(bool $admin = false): User
    {
        return User::create([
            'email' => ($admin ? 'admin' : 'customer') . '@retired-payment.test',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000001',
            'token' => '0123456789abcdef0123456789abcdef',
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => $admin,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
