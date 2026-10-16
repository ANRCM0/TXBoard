<?php

namespace App\Domains\CredentialProvider;

final class ProviderCapabilityCodec
{
    public const VERSION = 'credential-provider-capabilities/1';

    public static function decodeResponse(#[\SensitiveParameter] string $json, array $request): array
    {
        try {
            $response = ProviderCodec::decodeObject($json, ProviderCodec::MAX_BYTES);
            $keys = array_keys($response);
            sort($keys, SORT_STRING);
            $expected = ['contract_version', 'request_id', 'provider_id', 'provider_instance_epoch',
                'store_version', 'operations', 'node_delivery', 'subscription_render', 'profiles'];
            sort($expected, SORT_STRING);
            self::require($keys === $expected);
            foreach (['contract_version', 'request_id', 'provider_id', 'provider_instance_epoch'] as $key) {
                self::require(($request[$key] ?? null) === $response[$key]);
            }
            self::require($response['contract_version'] === self::VERSION && $response['store_version'] === 2
                && $response['operations'] === ['issue', 'query', 'revoke', 'rotate']
                && is_bool($response['node_delivery']) && is_bool($response['subscription_render'])
                && is_array($response['profiles']) && array_is_list($response['profiles'])
                && count($response['profiles']) >= 1 && count($response['profiles']) <= 2);
            $previous = '';
            foreach ($response['profiles'] as $profile) {
                self::require(is_string($profile) && in_array($profile, ['vless-uuid/v1', 'vmess-uuid/v1'], true)
                    && strcmp($profile, $previous) > 0);
                $previous = $profile;
            }
            return $response;
        } catch (\Throwable) {
            throw new ProviderError('PROVIDER_CAPABILITIES_INVALID', true);
        }
    }

    private static function require(bool $condition): void
    {
        if (!$condition) {
            throw new ProviderError('PROVIDER_CAPABILITIES_INVALID', true);
        }
    }
}
