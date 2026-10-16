<?php

namespace App\Domains\CredentialProvider;

final class ProviderMetadataCodec
{
    public const VERSION = 'credential-delivery/1';
    public const MAX_BYTES = 1048576;
    public const MAX_SUBJECTS = 128;

    public static function validateRequest(array $request): void
    {
        self::keys($request, ['contract_version', 'request_id', 'provider_id', 'provider_instance_epoch', 'subjects']);
        self::require($request['contract_version'] === self::VERSION);
        self::token($request['request_id'], '/\A[A-Za-z0-9._~:-]{1,96}\z/D');
        self::token($request['provider_id'], '/\A[a-z][a-z0-9_-]{0,63}\z/D');
        self::decimal($request['provider_instance_epoch']);
        self::require(is_array($request['subjects']) && array_is_list($request['subjects'])
            && count($request['subjects']) <= self::MAX_SUBJECTS);
        $seen = [];
        foreach ($request['subjects'] as $subject) {
            self::require(is_array($subject));
            self::keys($subject, ['installation_id', 'access_subject_id', 'subscription_id', 'terms_id',
                'provider_subject_ref', 'credential_ref', 'credential_generation', 'credential_profile_id']);
            self::token($subject['installation_id'], '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D');
            foreach (['access_subject_id', 'subscription_id', 'terms_id', 'credential_generation'] as $key) {
                self::decimal($subject[$key]);
            }
            self::token($subject['provider_subject_ref'], '/\Asubject_[0-9a-f]{32}\z/D');
            self::token($subject['credential_ref'], '/\Acredential_[0-9a-f]{32}\z/D');
            self::require(in_array($subject['credential_profile_id'], ['vless-uuid/v1', 'vmess-uuid/v1'], true));
            foreach (['access_subject_id', 'provider_subject_ref', 'credential_ref'] as $key) {
                self::require(!isset($seen[$key][$subject[$key]]));
                $seen[$key][$subject[$key]] = true;
            }
        }
        self::require(strlen(ProviderCodec::canonical($request)) <= self::MAX_BYTES);
    }

    public static function decodeResponse(#[\SensitiveParameter] string $json, array $request): array
    {
        try {
            $response = ProviderCodec::decodeObject($json, self::MAX_BYTES);
            self::keys($response, ['contract_version', 'request_id', 'provider_id', 'provider_instance_epoch', 'credentials']);
            foreach (['contract_version', 'request_id', 'provider_id', 'provider_instance_epoch'] as $key) {
                self::require($response[$key] === $request[$key]);
            }
            self::require(is_array($response['credentials']) && array_is_list($response['credentials'])
                && count($response['credentials']) === count($request['subjects']));
            foreach ($response['credentials'] as $index => $credential) {
                self::require(is_array($credential));
                self::keys($credential, ['access_subject_id', 'credential_ref', 'credential_generation',
                    'credential_sha256', 'credential_expires_at']);
                foreach (['access_subject_id', 'credential_ref', 'credential_generation'] as $key) {
                    self::require($credential[$key] === $request['subjects'][$index][$key]);
                }
                self::token($credential['credential_sha256'], '/\A[0-9a-f]{64}\z/D');
                self::require(is_int($credential['credential_expires_at']) && $credential['credential_expires_at'] > 0);
            }
            return $response;
        } catch (\Throwable) {
            throw new ProviderError('PROVIDER_INVALID_RESPONSE', true);
        }
    }

    private static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        self::require($actual === $keys);
    }

    private static function decimal(mixed $value): void
    {
        self::token($value, '/\A[1-9][0-9]{0,18}\z/D');
        self::require(strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0);
    }

    private static function token(mixed $value, string $pattern): void
    {
        self::require(is_string($value) && preg_match($pattern, $value) === 1);
    }

    private static function require(bool $condition): void
    {
        if (!$condition) {
            throw new ProviderError('PROVIDER_METADATA_INVALID');
        }
    }
}
