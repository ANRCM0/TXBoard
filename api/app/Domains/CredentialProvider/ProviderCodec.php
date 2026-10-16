<?php

namespace App\Domains\CredentialProvider;

use JsonException;
use stdClass;

final class ProviderCodec
{
    public const MAX_BYTES = 65536;
    private const MAX_DECIMAL = '9223372036854775807';
    private const PROFILES = ['vless-uuid/v1', 'vmess-uuid/v1'];

    public static function canonical(#[\SensitiveParameter] array $value): string
    {
        try {
            return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ProviderError('PROVIDER_INVALID_REQUEST');
        }
    }

    public static function inputHash(string $operation, string $providerId, string $epoch, #[\SensitiveParameter] array $input): string
    {
        return hash('sha256', self::canonical([
            'operation' => $operation,
            'provider_id' => $providerId,
            'provider_instance_epoch' => $epoch,
            'input' => $input,
        ]));
    }

    public static function validateRequest(#[\SensitiveParameter] array $request, ?int $now = null): void
    {
        $now ??= time();
        self::keys($request, [
            'contract_version', 'operation', 'request_id', 'operation_id', 'provider_id',
            'provider_instance_epoch', 'idempotency_key', 'input_hash', 'deadline_at', 'input',
        ]);
        self::require($request['contract_version'] === 'credential-provider/1');
        self::require(in_array($request['operation'], ['issue', 'query', 'revoke'], true));
        self::token($request['request_id'], '/^[A-Za-z0-9._~:-]{1,96}$/D');
        self::token($request['operation_id'], '/^cp_[0-9a-f]{32}$/D');
        self::token($request['provider_id'], '/^[a-z][a-z0-9_-]{0,63}$/D');
        self::decimal($request['provider_instance_epoch']);
        self::token($request['idempotency_key'], '/^[A-Za-z0-9._~:-]{1,128}$/D');
        self::token($request['input_hash'], '/^[0-9a-f]{64}$/D');
        self::timestamp($request['deadline_at']);
        self::require($request['deadline_at'] > $now && $request['deadline_at'] - $now <= 30);
        self::require(is_array($request['input']));
        self::input($request['operation'], $request['input']);
        if ($request['operation'] === 'query') {
            self::require($request['input']['operation_id'] === $request['operation_id']);
        }
        self::require(hash_equals(
            self::inputHash($request['operation'], $request['provider_id'], $request['provider_instance_epoch'], $request['input']),
            $request['input_hash'],
        ));
        self::require(strlen(self::canonical($request)) <= self::MAX_BYTES);
    }

    public static function decodeResponse(
        #[\SensitiveParameter] string $json,
        #[\SensitiveParameter] array $request,
        #[\SensitiveParameter] ?array $originalRequest = null,
    ): array
    {
        try {
            self::require(strlen($json) <= self::MAX_BYTES && $json !== '');
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            self::require($decoded instanceof stdClass);
            $position = 0;
            self::scanValue($json, $position);
            self::space($json, $position);
            self::require($position === strlen($json));
            $response = self::arrays($decoded);
            self::keys($response, [
                'contract_version', 'request_id', 'operation_id', 'provider_id',
                'provider_instance_epoch', 'status', 'result', 'error',
            ]);
            foreach (['contract_version', 'request_id', 'operation_id', 'provider_id', 'provider_instance_epoch'] as $key) {
                self::require($response[$key] === $request[$key]);
            }
            self::require(in_array($response['status'], ['succeeded', 'unknown', 'rejected'], true));
            if ($response['status'] !== 'succeeded') {
                self::require($response['result'] === null && is_array($response['error']));
                self::keys($response['error'], ['code', 'retryable']);
                self::token($response['error']['code'], '/^[A-Z][A-Z0-9_]{0,63}$/D');
                self::require(is_bool($response['error']['retryable']));
                if ($response['status'] === 'unknown') {
                    self::require($response['error']['retryable'] === true
                        && ($response['error']['code'] === 'STORE_UNAVAILABLE'
                            || ($request['operation'] === 'query' && $response['error']['code'] === 'OPERATION_UNKNOWN')));
                }
                return $response;
            }
            self::require($response['error'] === null && is_array($response['result']));
            if ($request['operation'] === 'query') {
                self::keys($response['result'], ['operation', 'input_hash', 'result']);
                $receipt = $response['result'];
                self::require(in_array($receipt['operation'], ['issue', 'revoke'], true));
                self::token($receipt['input_hash'], '/^[0-9a-f]{64}$/D');
                self::require(is_array($receipt['result']));
                self::require($originalRequest !== null);
                foreach (['operation_id', 'provider_id', 'provider_instance_epoch', 'idempotency_key'] as $key) {
                    self::require(($originalRequest[$key] ?? null) === $request[$key]);
                }
                self::require($receipt['operation'] === ($originalRequest['operation'] ?? null));
                self::token($originalRequest['input_hash'] ?? null, '/^[0-9a-f]{64}$/D');
                self::require(is_array($originalRequest['input'] ?? null));
                self::input($originalRequest['operation'], $originalRequest['input']);
                self::require(hash_equals($originalRequest['input_hash'], $receipt['input_hash']));
                self::require(hash_equals($originalRequest['input_hash'], self::inputHash(
                    $originalRequest['operation'],
                    $originalRequest['provider_id'],
                    $originalRequest['provider_instance_epoch'],
                    $originalRequest['input'],
                )));
                self::result($receipt['operation'], $receipt['result'], $originalRequest['input']);
            } else {
                self::result($request['operation'], $response['result'], $request['input']);
            }
            return $response;
        } catch (JsonException | ProviderError) {
            throw new ProviderError('PROVIDER_INVALID_RESPONSE', true);
        }
    }

    private static function input(string $operation, #[\SensitiveParameter] array $input): void
    {
        if ($operation === 'query') {
            self::keys($input, ['operation_id']);
            self::token($input['operation_id'], '/^cp_[0-9a-f]{32}$/D');
            return;
        }
        if ($operation === 'issue') {
            self::keys($input, [
                'installation_id', 'access_subject_id', 'subscription_id', 'terms_id',
                'provider_subject_ref', 'expected_subject_version', 'credential_profile_id',
                'not_before_at', 'credential_expires_at',
            ]);
            foreach (['subscription_id', 'terms_id', 'expected_subject_version'] as $key) {
                self::decimal($input[$key]);
            }
            self::require(in_array($input['credential_profile_id'], self::PROFILES, true));
            self::timestamp($input['not_before_at']);
            self::timestamp($input['credential_expires_at']);
            self::require($input['credential_expires_at'] > $input['not_before_at']);
            self::require($input['credential_expires_at'] - $input['not_before_at'] <= 2678400);
        } else {
            self::keys($input, [
                'installation_id', 'access_subject_id', 'provider_subject_ref',
                'revocation_fact_version', 'effective_at', 'reason_code',
            ]);
            self::decimal($input['revocation_fact_version']);
            self::timestamp($input['effective_at']);
            self::require(in_array($input['reason_code'], ['MANUAL', 'SUBSCRIPTION_CHANGED', 'ACCOUNT_BANNED'], true));
        }
        self::token($input['installation_id'], '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D');
        self::decimal($input['access_subject_id']);
        self::token($input['provider_subject_ref'], '/^subject_[0-9a-f]{32}$/D');
    }

    private static function result(string $operation, #[\SensitiveParameter] array $result, #[\SensitiveParameter] ?array $input): void
    {
        if ($operation === 'issue') {
            self::keys($result, [
                'access_subject_id', 'provider_subject_ref', 'credential_profile_id',
                'credential_ref', 'credential_generation', 'issued_at', 'not_before_at', 'credential_expires_at',
            ]);
            self::token($result['credential_ref'], '/^credential_[0-9a-f]{32}$/D');
            self::require($result['credential_generation'] === '1');
            self::require(in_array($result['credential_profile_id'], self::PROFILES, true));
            foreach (['issued_at', 'not_before_at', 'credential_expires_at'] as $key) {
                self::timestamp($result[$key]);
            }
            self::require($result['credential_expires_at'] > $result['not_before_at']);
            self::require($result['credential_expires_at'] - $result['not_before_at'] <= 2678400);
            if ($input !== null) {
                foreach (['credential_profile_id', 'not_before_at', 'credential_expires_at'] as $key) {
                    self::require($result[$key] === $input[$key]);
                }
            }
        } else {
            self::keys($result, [
                'access_subject_id', 'provider_subject_ref', 'accepted_revocation_fact_version', 'provider_revoked_at',
            ]);
            self::decimal($result['accepted_revocation_fact_version']);
            self::timestamp($result['provider_revoked_at']);
            if ($input !== null) {
                self::require(self::compareDecimal($result['accepted_revocation_fact_version'], $input['revocation_fact_version']) >= 0);
            }
        }
        self::decimal($result['access_subject_id']);
        self::token($result['provider_subject_ref'], '/^subject_[0-9a-f]{32}$/D');
        if ($input !== null) {
            foreach (['access_subject_id', 'provider_subject_ref'] as $key) {
                self::require($result[$key] === $input[$key]);
            }
        }
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sort($item);
        }
        return $value;
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::arrays($item);
            }
        }
        return $value;
    }

    private static function keys(#[\SensitiveParameter] array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        self::require($actual === $keys);
    }

    private static function token(#[\SensitiveParameter] mixed $value, string $pattern): void
    {
        self::require(is_string($value) && preg_match($pattern, $value) === 1);
    }

    private static function decimal(#[\SensitiveParameter] mixed $value): void
    {
        self::token($value, '/^[1-9][0-9]{0,18}$/D');
        self::require(self::compareDecimal($value, self::MAX_DECIMAL) <= 0);
    }

    private static function compareDecimal(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function timestamp(#[\SensitiveParameter] mixed $value): void
    {
        self::require(is_int($value) && $value >= 0);
    }

    private static function require(bool $condition): void
    {
        if (!$condition) {
            throw new ProviderError('PROVIDER_INVALID_REQUEST');
        }
    }

    // json_decode accepts duplicate keys. Inspect the validated JSON before trusting its object map.
    private static function scanValue(string $json, int &$position): void
    {
        self::space($json, $position);
        $character = $json[$position];
        if ($character === '{') {
            $position++;
            self::space($json, $position);
            $seen = [];
            if ($json[$position] === '}') {
                $position++;
                return;
            }
            do {
                self::space($json, $position);
                $key = json_decode(self::scanString($json, $position), true, 4, JSON_THROW_ON_ERROR);
                self::require(!array_key_exists($key, $seen));
                $seen[$key] = true;
                self::space($json, $position);
                $position++;
                self::scanValue($json, $position);
                self::space($json, $position);
                $separator = $json[$position++];
            } while ($separator === ',');
            return;
        }
        if ($character === '[') {
            $position++;
            self::space($json, $position);
            if ($json[$position] === ']') {
                $position++;
                return;
            }
            do {
                self::scanValue($json, $position);
                self::space($json, $position);
                $separator = $json[$position++];
            } while ($separator === ',');
            return;
        }
        if ($character === '"') {
            self::scanString($json, $position);
            return;
        }
        $start = $position;
        while ($position < strlen($json) && !str_contains(",]} \t\r\n", $json[$position])) {
            $position++;
        }
        $literal = substr($json, $start, $position - $start);
        self::require(in_array($literal, ['true', 'false', 'null'], true)
            || preg_match('/^(0|[1-9][0-9]*)$/D', $literal) === 1);
    }

    private static function scanString(string $json, int &$position): string
    {
        $start = $position++;
        while ($position < strlen($json)) {
            $character = $json[$position++];
            if ($character === '\\') {
                $position++;
            } elseif ($character === '"') {
                return substr($json, $start, $position - $start);
            }
        }
        throw new ProviderError('PROVIDER_INVALID_RESPONSE', true);
    }

    private static function space(string $json, int &$position): void
    {
        while ($position < strlen($json) && str_contains(" \t\r\n", $json[$position])) {
            $position++;
        }
    }
}
