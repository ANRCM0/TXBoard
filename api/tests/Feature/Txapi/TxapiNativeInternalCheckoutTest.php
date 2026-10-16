<?php

namespace Tests\Feature\Txapi;

use App\Domains\Billing\NativeRechargeService;
use App\Domains\InternalApi\ServiceCredentials;
use App\Models\User;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativePaymentTestCase;

final class TxapiNativeInternalCheckoutTest extends NativePaymentTestCase
{
    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    protected string $publicationCurrency = 'CNY';
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://core.example.test',
            'internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test', 'pid' => '42', 'key' => 'checkout-test-secret', 'type' => 'alipay',
        ]]);
        $this->service = app(ServiceCredentials::class)->issue('checkout-gateway', 'user', self::FINGERPRINT,
            ['commerce.orders.checkout', 'billing.recharges.checkout'], time() + 3600);
        Http::preventStrayRequests();
    }

    public static function disabledDependency(): array
    {
        return ['plugin disabled' => ['plugin'], 'payment registration disabled' => ['payment']];
    }

    #[DataProvider('disabledDependency')]
    public function test_purchase_checkout_replay_rechecks_current_channel_gate(string $dependency): void
    {
        [$user, $order] = $this->purchase();
        $this->assertCheckoutGate('commerce.orders.checkout', $user, [
            'trade_no' => $order->trade_no, 'method' => (string) $this->payment->id,
        ], 'data.data', $dependency);
    }

    #[DataProvider('disabledDependency')]
    public function test_recharge_checkout_replay_rechecks_current_channel_gate(string $dependency): void
    {
        [$user] = $this->purchase();
        $recharge = app(NativeRechargeService::class)->create($user->id, '2000', $this->payment->id, 'recharge-intent');
        $this->assertCheckoutGate('billing.recharges.checkout', $user, [
            'trade_no' => $recharge['trade_no'],
        ], 'data.payment.data', $dependency);
    }

    private function assertCheckoutGate(string $operation, User $user, array $input, string $urlPath, string $dependency): void
    {
        $headers = [
            'X-Core-Service-Id' => $this->service['service_id'],
            'X-Core-Service-Credential' => $this->service['credential'],
            'X-Core-Service-Epoch' => $this->service['epoch'],
            'X-Core-Tls-Verified' => 'SUCCESS', 'X-Core-Tls-Certificate-Sha256' => self::FINGERPRINT,
            'Authorization' => 'Bearer '.$user->createToken('checkout-owner', ['*'])->plainTextToken,
            'Idempotency-Key' => (string) Str::uuid(),
        ];
        $invoke = fn () => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
            ->postJson('https://core.example.test/txapi/internal/native/v1/user/operations/'.$operation, [
                'contract_version' => '1', 'request_id' => (string) Str::uuid(),
                'deadline_at' => time() + 15, 'input' => $input,
            ], $headers);
        $first = $invoke()->assertOk();
        $url = $first->json($urlPath);
        $this->assertIsString($url);
        $this->assertStringContainsString('/submit.php?', $url);
        $invoke()->assertOk()->assertJsonPath($urlPath, $url);
        $receipt = DB::table('tx_internal_request')->first();
        $this->assertSame('completed', $receipt->state);
        $stored = Crypt::decryptString($receipt->response_json);
        $this->assertStringNotContainsString('/submit.php?', $stored);
        $this->assertStringNotContainsString($url, $stored);
        if ($dependency === 'plugin') {
            app(PluginManager::class)->disable('epay');
        } else {
            $this->payment->update(['enable' => false]);
        }
        $rejected = $invoke()->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_PROVIDER_UNAVAILABLE');
        $this->assertStringNotContainsString('/submit.php?', $rejected->getContent());
        $this->assertStringNotContainsString($url, $rejected->getContent());
        $this->assertSame(1, DB::table('tx_payment_attempt')->count());
        $this->assertSame(1, DB::table('tx_internal_request')->count());
        Http::assertNothingSent();
    }
}
