<?php

namespace Plugin\Epay;

use App\Contracts\NativePaymentProviderInterface;
use App\Domains\Billing\BillingError;
use App\Domains\Billing\MoneyValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Verifies the EPay wire protocol. Financial writes belong to settlement. */
final class NativeAdapter implements NativePaymentProviderInterface
{
    public const PROVIDER = 'EPay';
    private const RESPONSE_LIMIT = 65536;

    public function identity(): array
    {
        return ['provider_id' => self::PROVIDER, 'contract_version' => 'native-payment-provider/1',
            'snapshot_schema' => 'epay-md5-dispatch/1'];
    }

    public function capabilities(): array
    {
        return ['checkout' => true, 'verify_notification' => true, 'query' => true,
            'external_refund' => false, 'query_refund' => false, 'currencies' => ['CNY'],
            'currency_minor_exponent' => '2'];
    }

    public function accountRef(object $payment): string
    {
        $config = $this->configuration($payment);
        return hash('sha256', $config['gateway']."\n".$config['pid']);
    }

    public function freeze(object $payment, array $attempt): array
    {
        $config = $this->configuration($payment);
        if ($attempt['provider'] !== self::PROVIDER || $attempt['currency'] !== 'CNY'
            || $attempt['provider_account_ref'] !== $this->accountRef($payment)) {
            throw new BillingError('NATIVE_EPAY_BINDING_INVALID');
        }
        $uuid = MoneyValue::key($payment->uuid, 128);
        $base = $this->httpsBase(config('app.url'));
        $notifyBase = empty($payment->notify_domain) ? $base : $this->httpsBase($payment->notify_domain);
        $snapshot = [
            'schema' => 'epay-md5-dispatch/1',
            'installation_id' => $attempt['installation_id'],
            'user_id' => $attempt['user_id'], 'order_id' => $attempt['order_id'],
            'intent_type' => $attempt['intent_type'] ?? 'purchase', 'recharge_id' => $attempt['recharge_id'] ?? null,
            'payment_id' => $attempt['payment_id'], 'attempt_id' => $attempt['attempt_id'],
            'provider' => self::PROVIDER, 'provider_account_ref' => $attempt['provider_account_ref'],
            'provider_order_ref' => 'tx'.substr(hash('sha256', $attempt['installation_id']."\n".$attempt['attempt_id']), 0, 34),
            'order_trade_no' => $attempt['order_trade_no'], 'amount_minor' => $attempt['amount_minor'],
            'currency' => 'CNY', 'currency_minor_exponent' => '2', 'callback_uuid' => $uuid,
            'notify_url' => $notifyBase.'/txapi/payment/webhook/EPay/'.$uuid,
            'return_url' => $base.((($attempt['intent_type'] ?? 'purchase') === 'recharge')
                ? '/#/wallet' : '/#/order/'.$attempt['order_trade_no']),
            'gateway' => $config['gateway'], 'pid' => $config['pid'], 'key' => $config['key'], 'type' => $config['type'],
        ];
        return $snapshot;
    }

    public function checkout(array $snapshot): array
    {
        $params = [
            'money' => $this->decimal($snapshot['amount_minor']), 'name' => $snapshot['order_trade_no'],
            'notify_url' => $snapshot['notify_url'], 'return_url' => $snapshot['return_url'],
            'out_trade_no' => $snapshot['provider_order_ref'], 'pid' => $snapshot['pid'],
        ];
        if ($snapshot['type'] !== '') {
            $params['type'] = $snapshot['type'];
        }
        $params['sign'] = $this->signature($params, $snapshot['key']);
        $params['sign_type'] = 'MD5';
        return ['type' => 1, 'data' => $snapshot['gateway'].'/submit.php?'.http_build_query($params)];
    }

    public function notificationReference(array $params): ?string
    {
        $reference = $params['out_trade_no'] ?? null;
        return is_string($reference) && preg_match('/\Atx[a-f0-9]{34}\z/D', $reference) === 1 ? $reference : null;
    }

    public function acknowledgement(): string
    {
        return 'success';
    }

    public function verifyNotification(array $snapshot, array $params): array
    {
        $this->scalarFields($params);
        if (($params['trade_status'] ?? null) !== 'TRADE_SUCCESS'
            || ($params['sign_type'] ?? 'MD5') !== 'MD5') {
            throw new BillingError('NATIVE_EPAY_NOTIFICATION_INVALID');
        }
        $this->verifySignature($snapshot, $params);
        return $this->verifiedFact($snapshot, $params);
    }

    /** Null retains uncertainty; an HTTP response alone never proves a payment. */
    public function query(array $snapshot): ?array
    {
        if (DB::transactionLevel() !== 0) {
            throw new BillingError('NATIVE_EPAY_NETWORK_INSIDE_TRANSACTION');
        }
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(10)->withOptions([
                'allow_redirects' => false, 'verify' => true, 'stream' => true,
                'on_headers' => static function (ResponseInterface $response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (!ctype_digit($length) || strlen($length) > 6 || (int) $length > self::RESPONSE_LIMIT)) {
                        throw new BillingError('NATIVE_EPAY_RESPONSE_TOO_LARGE');
                    }
                },
            ])->post($snapshot['gateway'].'/api.php', [
                'act' => 'order', 'pid' => $snapshot['pid'], 'key' => $snapshot['key'],
                'out_trade_no' => $snapshot['provider_order_ref'],
            ]);
            if (!$response->successful()) {
                return null;
            }
            $body = $response->toPsrResponse()->getBody();
            $contents = '';
            while (!$body->eof() && strlen($contents) <= self::RESPONSE_LIMIT) {
                $part = $body->read(min(8192, self::RESPONSE_LIMIT + 1 - strlen($contents)));
                if ($part === '') {
                    break;
                }
                $contents .= $part;
            }
            $body->close();
            if (strlen($contents) > self::RESPONSE_LIMIT) {
                return null;
            }
            $params = json_decode($contents, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($params) || array_is_list($params)) {
                return null;
            }
            $this->scalarFields($params, true);
            if (!in_array($params['code'] ?? null, [1, '1'], true)
                || !in_array($params['status'] ?? null, [1, '1'], true)) {
                return null;
            }
            // The merchant order-query dialect authenticates HTTPS with the frozen key.
            // Some providers additionally sign the response; never ignore a supplied signature.
            if (array_key_exists('sign', $params)) {
                if (($params['sign_type'] ?? 'MD5') !== 'MD5') {
                    return null;
                }
                $this->verifySignature($snapshot, $params);
            }
            return $this->verifiedFact($snapshot, $params);
        } catch (Throwable) {
            return null;
        }
    }

    public function decimal(string $minor): string
    {
        $minor = MoneyValue::minor($minor, true);
        $padded = str_pad($minor, 3, '0', STR_PAD_LEFT);
        return substr($padded, 0, -2).'.'.substr($padded, -2);
    }

    public function minor(mixed $decimal): string
    {
        if (!is_string($decimal) || preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/D', $decimal, $match) !== 1) {
            throw new BillingError('NATIVE_EPAY_AMOUNT_INVALID');
        }
        $minor = ltrim($match[1].str_pad($match[2] ?? '', 2, '0'), '0');
        return MoneyValue::minor($minor === '' ? '0' : $minor, true);
    }

    private function configuration(object $payment): array
    {
        if ($payment->payment !== self::PROVIDER) {
            throw new BillingError('NATIVE_PAYMENT_PROVIDER_UNSUPPORTED');
        }
        if (!(bool) $payment->enable) {
            throw new BillingError('PAYMENT_PROVIDER_UNAVAILABLE');
        }
        if (!in_array($payment->handling_fee_fixed, [null, 0, '0'], true)
            || !preg_match('/\A0(?:\.0+)?\z/D', (string) ($payment->handling_fee_percent ?? '0'))) {
            throw new BillingError('NATIVE_PAYMENT_FEES_UNSUPPORTED');
        }
        $config = is_array($payment->config) ? $payment->config : json_decode((string) $payment->config, true);
        if (!is_array($config) || !is_string($config['pid'] ?? null)
            || preg_match('/\A[1-9][0-9]{0,19}\z/D', $config['pid']) !== 1
            || !is_string($config['key'] ?? null) || $config['key'] === '' || strlen($config['key']) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $config['key'])
            || !is_string($config['type'] ?? '') || strlen($config['type'] ?? '') > 32
            || preg_match('/\A[A-Za-z0-9_-]*\z/D', $config['type'] ?? '') !== 1) {
            throw new BillingError('NATIVE_EPAY_CONFIGURATION_INVALID');
        }
        return ['gateway' => $this->httpsBase($config['url'] ?? null), 'pid' => $config['pid'],
            'key' => $config['key'], 'type' => $config['type'] ?? ''];
    }

    private function httpsBase(mixed $url): string
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw new BillingError('NATIVE_EPAY_URL_INVALID');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('/\A[A-Za-z0-9.-]+\z/D', $parts['host']) !== 1) {
            throw new BillingError('NATIVE_EPAY_URL_INVALID');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (preg_match('/\A(?:\/[A-Za-z0-9._~-]+)*\z/D', $path) !== 1
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new BillingError('NATIVE_EPAY_URL_INVALID');
        }
        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';
        return 'https://'.strtolower(rtrim($parts['host'], '.')).$port.$path;
    }

    private function verifySignature(array $snapshot, array $params): void
    {
        if (!is_string($params['sign'] ?? null) || preg_match('/\A[a-f0-9]{32}\z/D', $params['sign']) !== 1
            || !hash_equals($this->signature($params, $snapshot['key']), $params['sign'])) {
            throw new BillingError('NATIVE_EPAY_SIGNATURE_INVALID');
        }
    }

    private function signature(array $params, string $key): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params, SORT_STRING);
        return md5(stripslashes(urldecode(http_build_query($params))).$key);
    }

    private function verifiedFact(array $snapshot, array $params): array
    {
        if ((string) ($params['pid'] ?? '') !== $snapshot['pid']
            || (string) ($params['out_trade_no'] ?? '') !== $snapshot['provider_order_ref']
            || $this->minor($params['money'] ?? null) !== $snapshot['amount_minor']) {
            throw new BillingError('NATIVE_EPAY_FACT_BINDING_INVALID');
        }
        return [
            'attempt_id' => $snapshot['attempt_id'], 'provider' => self::PROVIDER,
            'provider_account_ref' => $snapshot['provider_account_ref'],
            'transaction_ref' => MoneyValue::key($params['trade_no'] ?? null),
            'amount_minor' => $snapshot['amount_minor'], 'currency' => $snapshot['currency'], 'occurred_at' => null,
        ];
    }

    private function scalarFields(array $params, bool $allowNull = false): void
    {
        if (count($params) > 64) {
            throw new BillingError('NATIVE_EPAY_FIELDS_INVALID');
        }
        $size = 0;
        foreach ($params as $name => $value) {
            if (!is_string($name) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1
                || (!is_string($value) && !is_int($value) && !($allowNull && $value === null))
                || strlen((string) $value) > 4096) {
                throw new BillingError('NATIVE_EPAY_FIELDS_INVALID');
            }
            $size += strlen($name) + strlen((string) $value);
        }
        if ($size > self::RESPONSE_LIMIT) {
            throw new BillingError('NATIVE_EPAY_FIELDS_INVALID');
        }
    }
}
