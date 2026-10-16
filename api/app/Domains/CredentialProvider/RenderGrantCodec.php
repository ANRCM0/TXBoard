<?php

namespace App\Domains\CredentialProvider;

use DomainException;

/** Public metadata only. The recipient's private key stays outside Core. */
final class RenderGrantCodec
{
    public const POLICY = 'native-provider-subscription/v1';
    public const AUDIENCE = 'tx-subscription-client-v1';
    public const FORMAT = 'native-connection-bundle/v1';

    public static function publicKey(mixed $encoded): string
    {
        self::require(is_string($encoded) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $encoded) === 1);
        $key = base64_decode(strtr($encoded, '-_', '+/').'=', true);
        self::require(is_string($key) && strlen($key) === 32 && self::base64url($key) === $encoded);
        return $encoded;
    }

    public static function sign(array $payload, #[\SensitiveParameter] string $seed): array
    {
        self::require(extension_loaded('sodium'));
        $decoded = base64_decode(strtr($seed, '-_', '+/').str_repeat('=', (4 - strlen($seed) % 4) % 4), true);
        self::require(is_string($decoded) && strlen($decoded) === 32 && self::base64url($decoded) === $seed);
        $message = ProviderCodec::canonical($payload);
        self::require(strlen($message) <= ProviderMetadataCodec::MAX_BYTES);
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($decoded));
        try {
            $signature = sodium_crypto_sign_detached($message, $secret);
        } finally {
            sodium_memzero($secret);
            sodium_memzero($decoded);
        }
        return ['payload' => self::base64url($message), 'signature' => self::base64url($signature)];
    }

    public static function host(mixed $value): string
    {
        self::require(is_string($value) && $value !== '' && strlen($value) <= 253);
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
        foreach (explode('.', $value) as $label) {
            self::require(preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/D', $label) === 1);
        }
        return $value;
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function require(bool $condition): void
    {
        if (!$condition) {
            throw new DomainException('NATIVE_SUBSCRIPTION_DELIVERY_INPUT_INVALID');
        }
    }
}
