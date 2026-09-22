<?php

namespace App\Services\AgentOps;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;

class AgentPairingService
{
    private const CODE_PREFIX = 'txbp_';
    private const CODE_BYTES = 18;

    public function issue(int $adminId, int $tokenId, string $plainTextToken): array
    {
        $ttl = $this->ttlSeconds();
        $code = self::CODE_PREFIX . rtrim(strtr(base64_encode(random_bytes(self::CODE_BYTES)), '+/', '-_'), '=');
        $expiresAt = now()->addSeconds($ttl);

        $payload = Crypt::encryptString(json_encode([
            'admin_id' => $adminId,
            'token_id' => $tokenId,
            'plain_text_token' => $plainTextToken,
            'issued_at' => now()->timestamp,
            'expires_at' => $expiresAt->timestamp,
        ], JSON_THROW_ON_ERROR));

        $stored = $this->store()->put($this->cacheKey($code), $payload, $ttl);
        if ($stored === false) {
            throw new \RuntimeException('Agent pairing store unavailable');
        }

        return [
            'code' => $code,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => $ttl,
        ];
    }

    public function redeem(string $code): array
    {
        if (!preg_match('/^txbp_[A-Za-z0-9_-]{24}$/', $code)) {
            throw new \InvalidArgumentException('Invalid pairing code');
        }

        $key = $this->cacheKey($code);
        $store = $this->store();

        try {
            return $store->lock($key . ':lock', 5)->block(2, function () use ($store, $key) {
                $encrypted = $store->pull($key);
                if (!is_string($encrypted) || $encrypted === '') {
                    throw new \InvalidArgumentException('Pairing code is invalid, expired, or already redeemed');
                }

                try {
                    $payload = json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    throw new \InvalidArgumentException('Pairing code is invalid, expired, or already redeemed');
                }

                if (!is_array($payload)) {
                    throw new \InvalidArgumentException('Pairing code is invalid, expired, or already redeemed');
                }

                $plain = $payload['plain_text_token'] ?? null;
                $tokenId = $payload['token_id'] ?? null;
                $adminId = $payload['admin_id'] ?? null;
                $pairingExpiresAt = $payload['expires_at'] ?? null;

                if (
                    !is_string($plain) || $plain === '' ||
                    !is_numeric($tokenId) ||
                    !is_numeric($adminId) ||
                    !is_numeric($pairingExpiresAt) ||
                    (int) $pairingExpiresAt < now()->timestamp
                ) {
                    throw new \InvalidArgumentException('Pairing code is invalid, expired, or already redeemed');
                }

                $token = PersonalAccessToken::findToken($plain);
                if (
                    !$token ||
                    (int) $token->id !== (int) $tokenId ||
                    (int) $token->tokenable_id !== (int) $adminId ||
                    !str_starts_with((string) $token->name, 'agent:') ||
                    ($token->expires_at && $token->expires_at->isPast())
                ) {
                    throw new \InvalidArgumentException('Pairing code is invalid, expired, or already redeemed');
                }

                return [
                    'plain_text_token' => $plain,
                    'token_id' => (int) $token->id,
                    'client_name' => preg_replace('/^agent:/', '', (string) $token->name),
                    'abilities' => AgentTargetScope::functionalAbilities($token),
                    'target_scope' => AgentTargetScope::describe($token),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ];
            });
        } catch (LockTimeoutException) {
            throw new \RuntimeException('Agent pairing store unavailable');
        }
    }

    private function store()
    {
        return Cache::store((string) config('agent_ops.pairing_cache_store', 'redis'));
    }

    private function ttlSeconds(): int
    {
        return max(60, min(900, (int) config('agent_ops.pairing_ttl_seconds', 600)));
    }

    private function cacheKey(string $code): string
    {
        return 'agent_pairing:v2:' . hash('sha256', $code);
    }
}
