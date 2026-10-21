<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/** Fixed Telegram authority; no upstream body, callback credential or exception escapes. */
final class NativeTelegramConfiguration
{
    public static function callback(string $base, string $token): string
    {
        $parts = parse_url($base);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $base)) {
            throw new \DomainException('TELEGRAM_WEBHOOK_URL_INVALID');
        }
        $base = rtrim($base, '/');
        $url = str_ends_with($base, '/txapi/integrations/telegram/webhook')
            ? $base : $base.'/txapi/integrations/telegram/webhook';
        return $url.'?'.http_build_query(['access_token' => md5($token)]);
    }

    public function inspect(string $token, string $callback): array
    {
        $this->request($token, 'getMe');
        $result = $this->request($token, 'getWebhookInfo');
        if (!is_array($result) || !is_string($result['url'] ?? null)
            || !is_int($result['pending_update_count'] ?? null) || $result['pending_update_count'] < 0) {
            throw new \DomainException('TELEGRAM_RESPONSE_INVALID');
        }
        return ['configured' => $result['url'] !== '', 'matches_saved' => hash_equals($callback, $result['url']),
            'pending_updates' => min(2147483647, $result['pending_update_count']),
            'delivery_error' => isset($result['last_error_date']) || isset($result['last_error_message'])];
    }

    public function register(string $token, string $callback): void
    {
        $this->request($token, 'getMe');
        if ($this->request($token, 'setWebhook', ['url' => $callback]) !== true) {
            throw new \DomainException('TELEGRAM_RESPONSE_INVALID');
        }
    }

    private function request(string $token, string $method, array $input = []): mixed
    {
        if (!preg_match('/\A[0-9]+:[A-Za-z0-9_-]+\z/D', $token)) throw new \DomainException('TELEGRAM_TOKEN_INVALID');
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false])
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $input);
            $body = $response->json();
            if (!$response->successful() || !is_array($body) || ($body['ok'] ?? null) !== true || !array_key_exists('result', $body)) {
                throw new \RuntimeException();
            }
            return $body['result'];
        } catch (\Throwable) {
            throw new \DomainException('TELEGRAM_DEPENDENCY_UNAVAILABLE');
        }
    }
}
