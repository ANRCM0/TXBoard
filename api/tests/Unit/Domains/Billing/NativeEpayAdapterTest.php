<?php

namespace Tests\Unit\Domains\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\MoneyValue;
use Plugin\Epay\NativeAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NativeEpayAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('plugins-core/Epay/NativeAdapter.php');
        config(['app.url' => 'https://core.example.test']);
        Http::preventStrayRequests();
    }

    public function test_submit_uses_exact_amount_and_operator_owned_urls_without_exposing_key(): void
    {
        $adapter = new NativeAdapter;
        $snapshot = $this->snapshot($adapter);
        $redirect = $adapter->checkout($snapshot);
        parse_str(parse_url($redirect['data'], PHP_URL_QUERY), $params);
        $this->assertSame('12.34', $params['money']);
        $this->assertSame('https://core.example.test/txapi/payment/webhook/EPay/payment-registration', $params['notify_url']);
        $this->assertSame('https://core.example.test/#/order/order-1', $params['return_url']);
        $this->assertSame($snapshot['provider_order_ref'], $params['out_trade_no']);
        $this->assertNotSame('order-1', $params['out_trade_no']);
        $this->assertSame($this->sign($params), $params['sign']);
        $this->assertStringNotContainsString('merchant-secret', $redirect['data']);
        $this->assertSame('92233720368547758.07', $adapter->decimal(MoneyValue::MAX));
        $this->assertSame(MoneyValue::MAX, $adapter->minor('92233720368547758.07'));
        $this->assertSame('1230', $adapter->minor('12.3'));
        $this->assertSame('1200', $adapter->minor('12'));
    }

    #[DataProvider('invalidDecimals')]
    public function test_provider_amounts_reject_lossy_noncanonical_and_overflow_values(mixed $value): void
    {
        $this->expectException(BillingError::class);
        (new NativeAdapter)->minor($value);
    }

    public static function invalidDecimals(): array
    {
        return [[12.34], [12], ['01.00'], ['1e2'], ['1.001'], ['-1.00'], ['0.00'], ['1.'], [' 1.00'],
            ['92233720368547758.08'], [true], [null]];
    }

    #[DataProvider('invalidUrls')]
    public function test_gateway_rejects_unsafe_url_parts(string $url): void
    {
        $adapter = new NativeAdapter;
        $this->expectExceptionMessage('NATIVE_EPAY_URL_INVALID');
        $adapter->accountRef($this->payment(['url' => $url]));
    }

    public static function invalidUrls(): array
    {
        return [['http://epay.example.test'], ['https://u:p@epay.example.test'],
            ['https://epay.example.test?key=secret'], ['https://epay.example.test#fragment'],
            ['https://epay.example.test/../x'], ['https://epay.example.test/%2e%2e/x'],
            ["https://epay.example.test/\n"], ['https://epay.example.test\\evil']];
    }

    public function test_account_identity_canonicalizes_gateway_and_does_not_depend_on_registration_or_key(): void
    {
        $adapter = new NativeAdapter;
        $first = $adapter->accountRef($this->payment(['url' => 'https://EPAY.example.test:443/gateway/']));
        $second = $adapter->accountRef($this->payment(['url' => 'https://epay.example.test/gateway', 'key' => 'rotated-key']));
        $this->assertSame($first, $second);
        $this->assertNotSame($first, $adapter->accountRef($this->payment(['url' => 'https://other.example.test/gateway'])));
        $this->assertNotSame($first, $adapter->accountRef($this->payment(['url' => 'https://epay.example.test/gateway', 'pid' => '43'])));
    }

    public function test_notification_checks_the_entire_signature_and_all_success_bindings(): void
    {
        $adapter = new NativeAdapter;
        $snapshot = $this->snapshot($adapter);
        $params = $this->notification($snapshot);
        $fact = $adapter->verifyNotification($snapshot, $params);
        $this->assertSame('1234', $fact['amount_minor']);
        $this->assertSame('CNY', $fact['currency']);
        $this->assertNull($fact['occurred_at']);
        foreach ([
            ['money' => '12.35'], ['pid' => '43'], ['out_trade_no' => 'other-order'],
            ['trade_status' => 'WAIT_BUYER_PAY'], ['sign_type' => 'RSA'],
        ] as $override) {
            $changed = array_replace($params, $override);
            $changed['sign'] = $this->sign($changed);
            try {
                $adapter->verifyNotification($snapshot, $changed);
                $this->fail('Mismatched signed payment was accepted.');
            } catch (BillingError) {}
        }
        $params['unsigned_extra'] = 'changed';
        $this->expectExceptionMessage('NATIVE_EPAY_SIGNATURE_INVALID');
        $adapter->verifyNotification($snapshot, $params);
    }

    public function test_query_posts_secret_and_validates_tls_order_merchant_and_exact_amount(): void
    {
        $adapter = new NativeAdapter;
        $snapshot = $this->snapshot($adapter);
        Http::fake(function ($request, $options) use ($snapshot) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://epay.example.test/gateway/api.php', $request->url());
            $this->assertSame('merchant-secret', $request['key']);
            $this->assertSame('order', $request['act']);
            $this->assertSame($snapshot['provider_order_ref'], $request['out_trade_no']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertTrue($options['stream']);
            $this->assertSame(10, $options['timeout']);
            return Http::response($this->queryResponse($snapshot));
        });
        $fact = $adapter->query($snapshot);
        $this->assertSame('channel-transaction-1', $fact['transaction_ref']);
        $this->assertSame('1234', $fact['amount_minor']);
        $this->assertNull($fact['occurred_at']);
    }

    public function test_unverifiable_query_results_and_timeouts_remain_unknown(): void
    {
        $adapter = new NativeAdapter;
        $snapshot = $this->snapshot($adapter);
        $responses = [];
        foreach ([
            ['pid' => '43'], ['out_trade_no' => 'other'], ['money' => '12.35'], ['money' => 12.34],
            ['status' => '0'], ['status' => null], ['code' => -1], ['sign' => str_repeat('0', 32)],
        ] as $override) {
            $responses[] = Http::response(array_replace($this->queryResponse($snapshot), $override));
        }
        $responses[] = Http::response(str_repeat('x', 65537));
        $responses[] = Http::response('not-json');
        $responses[] = new ConnectionException('connection timed out');
        Http::fake(static function () use (&$responses) {
            $next = array_shift($responses);
            if ($next instanceof ConnectionException) {
                throw $next;
            }
            return $next;
        });
        for ($index = 0; $index < 11; $index++) {
            $this->assertNull($adapter->query($snapshot));
        }
        $this->assertSame([], $responses);
    }

    private function payment(array $overrides = []): object
    {
        return (object) ['id' => 1, 'uuid' => 'payment-registration', 'payment' => 'EPay', 'enable' => true,
            'handling_fee_fixed' => null, 'handling_fee_percent' => null, 'notify_domain' => null,
            'config' => array_replace(['url' => 'https://epay.example.test/gateway', 'pid' => '42',
                'key' => 'merchant-secret', 'type' => 'alipay'], $overrides)];
    }

    private function snapshot(NativeAdapter $adapter): array
    {
        $payment = $this->payment();
        return $adapter->freeze($payment, ['attempt_id' => '1', 'installation_id' => '33333333-3333-4333-8333-333333333333',
            'user_id' => '2', 'order_id' => '3', 'payment_id' => '1', 'provider' => 'EPay',
            'provider_account_ref' => $adapter->accountRef($payment), 'amount_minor' => '1234',
            'currency' => 'CNY', 'order_trade_no' => 'order-1']);
    }

    private function notification(array $snapshot): array
    {
        $params = ['pid' => '42', 'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => $snapshot['provider_order_ref'],
            'trade_no' => 'channel-transaction-1', 'money' => '12.34', 'sign_type' => 'MD5'];
        return $params + ['sign' => $this->sign($params)];
    }

    private function queryResponse(array $snapshot): array
    {
        return ['code' => 1, 'pid' => '42', 'status' => '1', 'param' => null, 'out_trade_no' => $snapshot['provider_order_ref'],
            'trade_no' => 'channel-transaction-1', 'money' => '12.34'];
    }

    private function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params, SORT_STRING);
        return md5(stripslashes(urldecode(http_build_query($params))).'merchant-secret');
    }
}
