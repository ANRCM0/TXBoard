<?php

namespace App\Domains\CredentialProvider;

use DomainException;

/** Public metadata only. The recipient's private key stays outside Core. */
final class RenderGrantCodec
{
    public const POLICY = 'native-provider-subscription/v1';
    public const AUDIENCE = 'tx-subscription-client-v1';
    public const FORMAT = 'native-connection-bundle/v1';
    public const CLIENT_FORMATS = [
        'clash/v1' => ['min' => '1.18.0', 'before' => '2.0.0'],
        'clash-meta/v1' => ['min' => '1.18.0', 'before' => '2.0.0'],
        'sing-box/v1' => ['min' => '1.10.0', 'before' => '1.13.0'],
        'shadowrocket/v1' => ['min' => '2.2.0', 'before' => '3.0.0'],
        'surge-ios/v1' => ['min' => '5.20.0', 'before' => '6.0.0'],
        'surge-mac/v1' => ['min' => '6.7.0', 'before' => '7.0.0'],
        'stash/v1' => ['min' => '3.4.0', 'before' => '3.4.1'],
        'surfboard/v1' => ['min' => '2.35.2', 'before' => '2.35.3'],
    ];

    public static function profiles(string $format): array
    {
        return match ($format) {
            'clash/v1', 'surge-ios/v1', 'surge-mac/v1', 'surfboard/v1' => ['vmess-uuid/v1'],
            self::FORMAT, 'clash-meta/v1', 'sing-box/v1', 'shadowrocket/v1', 'stash/v1' => ['vless-uuid/v1', 'vmess-uuid/v1'],
            default => throw new DomainException('NATIVE_SUBSCRIPTION_DELIVERY_PROFILE_UNSUPPORTED'),
        };
    }

    /** An omitted ALPN field cannot represent an explicit signed TLS offer. */
    public static function compatibleNode(string $format, array $node): bool
    {
        return ($node['transport'] ?? null) === 'tcp'
            && in_array(($node['protocol'] ?? '').'-uuid/v1', self::profiles($format), true)
            && (!in_array($format, ['clash/v1', 'stash/v1', 'surfboard/v1'], true) || ($node['tls']['alpn'] ?? []) === []);
    }

    /** Explicit compatibility admission; user-agent sniffing never chooses policy. */
    public static function format(string $format, ?string $clientVersion): void
    {
        if ($format === self::FORMAT) {
            self::require($clientVersion === null);
            return;
        }
        $rule = self::CLIENT_FORMATS[$format] ?? null;
        self::require($rule !== null && is_string($clientVersion)
            && preg_match('/\A(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\z/D', $clientVersion) === 1);
        self::require(version_compare($clientVersion, $rule['min'], '>=')
            && version_compare($clientVersion, $rule['before'], '<'));
    }

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
