<?php

namespace App\Domains\Network;

use App\Models\Server;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

/** Signed, finite authorization metadata for one complete native Node users snapshot. */
final class NativeNodeAuthorization
{
    public const PROTOCOL_VERSION = 1;
    public const CAPABILITY = 'node_authorization_snapshot_v1';
    public const MAX_PAYLOAD_BYTES = 1048576;
    public const AUDIENCE = 'tx-node-native-authorization-v1';
    public const POLICY_VERSION = 'native-node-authorization/v1';
    public const PROVIDER_POLICY_VERSION = 'native-provider-authorization/v1';
    public const PROVIDER_USAGE_POLICY_VERSION = 'native-provider-authorization/v2';

    public function enabled(): bool
    {
        return in_array(config('native_node_authorization.enabled'),
            [true, 1, '1', 'true', 'TRUE', 'on', 'ON', 'yes', 'YES'], true);
    }

    /** The handshake exposes public bootstrap trust only over the authenticated TLS channel. */
    public function publicTrust(): array
    {
        $this->assertEnabledConfiguration();
        $key = $this->keyMaterial();
        return ['protocol_version' => 1, 'audience' => self::AUDIENCE,
            'policy_version' => $this->policyVersion(), 'core_instance_id' => $this->coreInstanceId(),
            'key_id' => $key['key_id'], 'key_epoch' => $key['key_epoch'],
            'algorithm' => 'Ed25519', 'public_key' => self::b64url($key['public_key']),
            'lease_seconds' => $this->leaseSeconds(), 'refresh_interval_seconds' => $this->refreshIntervalSeconds(),
            'max_clock_skew_seconds' => $this->clockSkewSeconds()];
    }

    /** Caller holds the Server lock after account, Subject and receipt locks. */
    public function issueProviderLocked(Server $node, array $users, array $provider, int $expiresAt, int $now, ?array $usage = null): array
    {
        $installation = app(\App\Domains\Installation\InstallationAuthority::class)->assertNativeRead();
        if ($this->policyVersion() !== ($usage === null ? self::PROVIDER_POLICY_VERSION : self::PROVIDER_USAGE_POLICY_VERSION)
            || ($provider['installation_id'] ?? null) !== $this->coreInstanceId()
            || $provider['installation_id'] !== $installation['installation_id'] || $expiresAt <= $now) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_POLICY_INVALID');
        }
        return $this->issueSnapshotLocked($node, $users, $now, $provider, $expiresAt, $usage);
    }

    private function issueSnapshotLocked(Server $node, array $users, int $now, ?array $provider = null, ?int $expiresAt = null, ?array $usage = null): array
    {
        $this->assertEnabledConfiguration();
        if (DB::transactionLevel() < 1) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_TRANSACTION_REQUIRED'); }
        if ($now < 0 || !$node->exists || !$node->enabled) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_TARGET_INVALID');
        }

        $machineId = $node->machine_id === null ? null : (int) $node->machine_id;
        $users = $this->normalizeUsers($users);

        DB::table('tx_node_authorization_state')->insertOrIgnore([
            'node_id' => $node->id,
            'machine_id' => $machineId,
            'key_id' => config('native_node_authorization.key_id'),
            'key_epoch' => config('native_node_authorization.key_epoch'),
            'revision' => 0,
            'issued_at' => 0,
            'snapshot_digest' => str_repeat('0', 64),
            'updated_at' => $now,
        ]);
        $state = DB::table('tx_node_authorization_state')->where('node_id', $node->id)->lockForUpdate()->first();
        if (!$state) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_STATE_UNAVAILABLE');
        }
        if ($now < (int) $state->issued_at) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_CLOCK_REGRESSION'); }
        if (($state->machine_id === null ? null : (int) $state->machine_id) !== $machineId) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_TARGET_CHANGED');
        }
        $currentKeyId = (string) config('native_node_authorization.key_id');
        $currentKeyEpoch = (string) config('native_node_authorization.key_epoch');
        if ((string) $state->key_id !== $currentKeyId && (string) $state->key_epoch === $currentKeyEpoch) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_KEY_CONFLICT');
        }
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $state->key_epoch)
            || (int) $currentKeyEpoch < (int) $state->key_epoch) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_KEY_ROLLBACK');
        }
        $previous = (string) $state->revision;
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $previous) || $previous === '9223372036854775807') {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_REVISION_EXHAUSTED');
        }
        $revision = (string) ((int) $previous + 1);

        $key = $this->keyMaterial();
        $payload = [
            'protocol_version' => 1, 'audience' => self::AUDIENCE, 'policy_version' => $this->policyVersion(),
            'core_instance_id' => $this->coreInstanceId(),
            'node_id' => (int) $node->id, 'machine_id' => $machineId, 'revision' => $revision,
            'key_id' => $key['key_id'], 'key_epoch' => $key['key_epoch'], 'issued_at' => $now,
            'not_before' => max(0, $now - $this->clockSkewSeconds()),
            'expires_at' => min($now + $this->leaseSeconds(), $expiresAt ?? PHP_INT_MAX),
            'complete' => true, 'users' => array_values($users),
        ];
        if ($provider !== null) {
            $payload['provider'] = $provider;
            self::validateProviderPayload($payload);
        }
        if ($usage !== null) {
            $payload['usage_v2'] = $usage;
            self::validateUsagePayload($payload);
        }
        $message = self::canonicalJson($payload);
        if (strlen($message) > self::MAX_PAYLOAD_BYTES) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_USERS_INVALID'); }
        $digest = hash('sha256', $message);
        $updated = DB::table('tx_node_authorization_state')->where('id', $state->id)->update([
            'machine_id' => $machineId, 'key_id' => $key['key_id'], 'key_epoch' => $key['key_epoch'],
            'revision' => $revision, 'issued_at' => $now, 'snapshot_digest' => $digest, 'updated_at' => $now,
        ]);
        if ($updated !== 1) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_STATE_UNAVAILABLE'); }
        return [
            'payload' => self::b64url($message),
            'signature' => self::b64url(sodium_crypto_sign_detached($message, $key['secret_key'])),
        ];
    }

    /** Verify an issued DTO against independently pinned public trust and a caller-supplied expected target. */
    public static function verify(array $authorization, string $publicKeyBase64Url, array $expected, int $now, int $maxClockSkewSeconds = 5): array
    {
        try {
            if (array_keys($authorization) !== ['payload', 'signature'] || !is_string($authorization['payload'])
                || !is_string($authorization['signature']) || $now < 0 || $maxClockSkewSeconds < 0 || $maxClockSkewSeconds > 30) {
                throw new DomainException('NATIVE_NODE_AUTHORIZATION_INVALID');
            }
            $bytes = self::decodeB64url($authorization['payload'], self::MAX_PAYLOAD_BYTES, false);
            $signature = self::decodeB64url($authorization['signature'], SODIUM_CRYPTO_SIGN_BYTES);
            $publicKey = self::decodeB64url($publicKeyBase64Url, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            if (!sodium_crypto_sign_verify_detached($signature, $bytes, $publicKey)) {
                throw new DomainException('NATIVE_NODE_AUTHORIZATION_INVALID');
            }
            $payload = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            $required = ['protocol_version','audience','policy_version','core_instance_id','node_id','machine_id','revision','key_id','key_epoch',
                'issued_at','not_before','expires_at','complete','users'];
            $usagePolicy = ($expected['policy_version'] ?? null) === self::PROVIDER_USAGE_POLICY_VERSION;
            $providerPolicy = $usagePolicy || ($expected['policy_version'] ?? null) === self::PROVIDER_POLICY_VERSION;
            if ($providerPolicy) { $required[] = 'provider'; }
            if ($usagePolicy) { $required[] = 'usage_v2'; }
            if (!is_array($payload)) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_INVALID'); }
            if (self::canonicalJson($payload) !== $bytes) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_INVALID'); }
            $keys = array_keys($payload); sort($keys, SORT_STRING); sort($required, SORT_STRING);
            if ($keys !== $required || $payload['protocol_version'] !== 1 || $payload['audience'] !== self::AUDIENCE
                || !in_array($payload['policy_version'], [self::POLICY_VERSION, self::PROVIDER_POLICY_VERSION, self::PROVIDER_USAGE_POLICY_VERSION], true)
                || $payload['policy_version'] !== ($expected['policy_version'] ?? null)
                || $payload['core_instance_id'] !== ($expected['core_instance_id'] ?? null)
                || $payload['node_id'] !== ($expected['node_id'] ?? null)
                || $payload['machine_id'] !== ($expected['machine_id'] ?? null)
                || $payload['key_id'] !== ($expected['key_id'] ?? null) || $payload['key_epoch'] !== ($expected['key_epoch'] ?? null)
                || !is_string($payload['core_instance_id']) || !preg_match('/\A[A-Za-z0-9:_-]{8,80}\z/D', $payload['core_instance_id'])
                || !is_string($payload['key_id']) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $payload['key_id'])
                || !is_string($payload['key_epoch']) || !preg_match('/\A[1-9][0-9]{0,18}\z/D', $payload['key_epoch'])
                || !is_string($payload['policy_version']) || !preg_match('/\A[a-zA-Z0-9_\/-]{1,64}\z/D', $payload['policy_version'])
                || !is_string($payload['revision']) || !preg_match('/\A[1-9][0-9]{0,18}\z/D', $payload['revision'])
                || !is_int($payload['node_id']) || $payload['node_id'] < 1
                || ($payload['machine_id'] !== null && (!is_int($payload['machine_id']) || $payload['machine_id'] < 1))
                || !is_int($payload['issued_at']) || $payload['issued_at'] < 0
                || !is_int($payload['not_before']) || $payload['not_before'] < 0
                || !is_int($payload['expires_at']) || $payload['expires_at'] < 1
                || $payload['issued_at'] > $now + $maxClockSkewSeconds || $payload['not_before'] > $now + $maxClockSkewSeconds
                || $payload['not_before'] > $payload['issued_at'] || $payload['expires_at'] <= $now
                || $payload['expires_at'] <= $payload['issued_at'] || $payload['expires_at'] - $payload['issued_at'] > 120
                || $payload['complete'] !== true || !is_array($payload['users']) || !array_is_list($payload['users'])) {
                throw new DomainException('NATIVE_NODE_AUTHORIZATION_INVALID');
            }
            self::validateUsers($payload['users']);
            if ($providerPolicy) { self::validateProviderPayload($payload); }
            if ($usagePolicy) { self::validateUsagePayload($payload); }
            return $payload;
        } catch (JsonException|\ValueError $error) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_INVALID', previous: $error);
        }
    }

    private function normalizeUsers(array $users): array
    {
        self::validateUsers($users);
        $normalized = array_map(static fn ($user): array => is_object($user) ? (array) $user : $user, $users);
        usort($normalized, static function (array $left, array $right): int {
            $id = ((int) $left['id']) <=> ((int) $right['id']);
            return $id !== 0 ? $id : strcmp((string) $left['uuid'], (string) $right['uuid']);
        });
        return array_values($normalized);
    }

    private static function validateUsers(array $users): void
    {
        if (!array_is_list($users)) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_USERS_INVALID'); }
        $ids = [];
        foreach ($users as $user) {
            $row = is_object($user) ? (array) $user : $user;
            if (!is_array($row) || !array_key_exists('id', $row) || !is_int($row['id']) || $row['id'] < 1
                || !array_key_exists('uuid', $row) || !is_string($row['uuid']) || $row['uuid'] === '' || strlen($row['uuid']) > 255
                || isset($ids[$row['id']])) {
                throw new DomainException('NATIVE_NODE_AUTHORIZATION_USERS_INVALID');
            }
            $ids[$row['id']] = true;
        }
    }

    private static function validateProviderPayload(array $payload): void
    {
        $fail = static function (bool $valid): void {
            if (!$valid) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_PROVIDER_INVALID'); }
        };
        $keys = static function (mixed $value, array $expected) use ($fail): void {
            $fail(is_array($value));
            $actual = array_keys($value); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
            $fail($actual === $expected);
        };
        $decimal = static function (mixed $value) use ($fail): void {
            $fail(is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) === 1
                && (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0));
        };
        $provider = $payload['provider'] ?? null;
        $keys($provider, ['installation_id', 'provider_id', 'provider_instance_epoch', 'recipient_sha256',
            'credential_profile_id', 'subjects']);
        $fail(is_string($provider['installation_id'])
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $provider['installation_id']) === 1
            && $provider['installation_id'] === $payload['core_instance_id']);
        $fail(is_string($provider['provider_id']) && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $provider['provider_id']) === 1);
        $decimal($provider['provider_instance_epoch']);
        $fail(is_string($provider['recipient_sha256']) && preg_match('/\A[0-9a-f]{64}\z/D', $provider['recipient_sha256']) === 1
            && in_array($provider['credential_profile_id'], ['vless-uuid/v1', 'vmess-uuid/v1'], true)
            && is_array($provider['subjects']) && array_is_list($provider['subjects'])
            && count($provider['subjects']) === count($payload['users']) && count($payload['users']) <= 128);
        $last = 0; $seen = [];
        foreach ($payload['users'] as $index => $user) {
            $keys($user, ['id', 'uuid', 'speed_limit', 'device_limit']);
            $fail(is_int($user['id']) && $user['id'] > $last && $user['id'] <= 2147483647
                && is_string($user['uuid']) && preg_match('/\Asha256:[0-9a-f]{64}\z/D', $user['uuid']) === 1
                && !isset($seen['uuid'][$user['uuid']]));
            foreach (['speed_limit', 'device_limit'] as $field) {
                $fail(is_int($user[$field]) && $user[$field] >= 0 && $user[$field] <= 2147483647);
            }
            $last = $user['id']; $seen['uuid'][$user['uuid']] = true;
            $subject = $provider['subjects'][$index];
            $keys($subject, ['user_id', 'access_subject_id', 'subscription_id', 'terms_id', 'provider_subject_ref',
                'credential_ref', 'credential_generation', 'credential_expires_at']);
            $fail($subject['user_id'] === $user['id']);
            foreach (['access_subject_id', 'subscription_id', 'terms_id', 'credential_generation'] as $field) { $decimal($subject[$field]); }
            $fail(is_string($subject['provider_subject_ref']) && preg_match('/\Asubject_[0-9a-f]{32}\z/D', $subject['provider_subject_ref']) === 1
                && is_string($subject['credential_ref']) && preg_match('/\Acredential_[0-9a-f]{32}\z/D', $subject['credential_ref']) === 1
                && is_int($subject['credential_expires_at']) && $subject['credential_expires_at'] >= $payload['expires_at']);
            foreach (['access_subject_id', 'provider_subject_ref', 'credential_ref'] as $field) {
                $fail(!isset($seen[$field][$subject[$field]])); $seen[$field][$subject[$field]] = true;
            }
        }
    }

    private function assertEnabledConfiguration(): void
    {
        if (!$this->enabled()) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_DISABLED'); }
        $this->keyMaterial();
        $this->coreInstanceId();
        $this->leaseSeconds();
        $this->refreshIntervalSeconds();
        $this->clockSkewSeconds();
    }

    private static function validateUsagePayload(array $payload): void
    {
        $fail = static function (bool $valid): void {
            if (!$valid) { throw new DomainException('NATIVE_NODE_AUTHORIZATION_USAGE_INVALID'); }
        };
        $keys = static function (mixed $row, array $required) use ($fail): void {
            $fail(is_array($row));
            $actual = array_keys($row); sort($actual, SORT_STRING); sort($required, SORT_STRING);
            $fail($actual === $required);
        };
        $decimal = static function (mixed $value, bool $zero = false) use ($fail): void {
            $fail(is_string($value)
                && preg_match($zero ? '/\A(?:0|[1-9][0-9]{0,18})\z/D' : '/\A[1-9][0-9]{0,18}\z/D', $value) === 1
                && (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0));
        };
        $usage = $payload['usage_v2'] ?? null;
        $keys($usage, ['protocol_version', 'core_instance_id', 'node_id', 'source_epoch', 'receipt_generation',
            'retention_seconds', 'max_late_report_seconds', 'receipt_retention_seconds', 'issued_at', 'grants']);
        $fail($usage['protocol_version'] === 2 && $usage['core_instance_id'] === $payload['core_instance_id']
            && $usage['node_id'] === (string) $payload['node_id'] && $usage['issued_at'] === $payload['issued_at']
            && is_array($usage['grants']) && array_is_list($usage['grants']) && count($usage['grants']) === count($payload['users']));
        foreach (['source_epoch', 'receipt_generation'] as $field) {
            $decimal($usage[$field]);
        }
        foreach (['retention_seconds', 'max_late_report_seconds', 'receipt_retention_seconds'] as $field) {
            $fail(is_int($usage[$field]) && $usage[$field] > 0 && $usage[$field] <= 9007199254740991);
        }
        $fail($usage['max_late_report_seconds'] <= $usage['retention_seconds']
            && $usage['retention_seconds'] <= $usage['receipt_retention_seconds']);
        foreach ($usage['grants'] as $index => $grant) {
            $keys($grant, ['user_id', 'access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id',
                'usage_period_id', 'issued_at', 'lease_expires_at', 'revocation_version']);
            $subject = $payload['provider']['subjects'][$index];
            $fail($grant['user_id'] === (string) $payload['users'][$index]['id']
                && $grant['access_subject_id'] === $subject['access_subject_id'] && $grant['terms_id'] === $subject['terms_id']
                && $grant['issued_at'] === $payload['issued_at'] && $grant['lease_expires_at'] === $payload['expires_at']);
            foreach (['auth_snapshot_id', 'tariff_revision_id', 'usage_period_id'] as $field) {
                $decimal($grant[$field]);
            }
            $decimal($grant['revocation_version'], true);
        }
    }

    private function keyMaterial(): array
    {
        if (!extension_loaded('sodium')) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_CRYPTO_UNAVAILABLE');
        }
        $encoded = config('native_node_authorization.private_seed');
        $seed = is_string($encoded) ? self::decodeB64url($encoded, SODIUM_CRYPTO_SIGN_SEEDBYTES) : '';
        $keyId = config('native_node_authorization.key_id');
        $epoch = config('native_node_authorization.key_epoch');
        if (is_int($epoch)) { $epoch = (string) $epoch; }
        if (!is_string($keyId) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $keyId)
            || !is_string($epoch) || !preg_match('/\A[1-9][0-9]{0,18}\z/D', $epoch)
            || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_KEY_UNAVAILABLE');
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);
        return ['secret_key' => sodium_crypto_sign_secretkey($pair), 'public_key' => sodium_crypto_sign_publickey($pair),
            'key_id' => $keyId, 'key_epoch' => $epoch];
    }

    private function coreInstanceId(): string
    {
        $id = config('native_node_authorization.core_instance_id');
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9:_-]{8,80}\z/D', $id)) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_INSTANCE_UNAVAILABLE');
        }
        return $id;
    }

    private function leaseSeconds(): int
    {
        $seconds = $this->integerConfig('lease_seconds');
        $maximum = $this->integerConfig('max_lease_seconds');
        if ($maximum === null || $maximum < 30 || $maximum > 120 || $seconds === null || $seconds < 30 || $seconds > $maximum) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_POLICY_INVALID');
        }
        return $seconds;
    }

    private function refreshIntervalSeconds(): int
    {
        $seconds = $this->integerConfig('refresh_interval_seconds');
        if ($seconds === null || $seconds < 1 || $seconds > 30 || $seconds > $this->leaseSeconds()) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_POLICY_INVALID');
        }
        return $seconds;
    }

    private function clockSkewSeconds(): int
    {
        $seconds = $this->integerConfig('max_clock_skew_seconds');
        if ($seconds === null || $seconds < 0 || $seconds > 30) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_POLICY_INVALID');
        }
        return $seconds;
    }

    private function integerConfig(string $name): ?int
    {
        $value = config('native_node_authorization.'.$name);
        if (is_int($value)) { return $value; }
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $value)) { return (int) $value; }
        return null;
    }

    private function policyVersion(): string
    {
        $version = config('native_node_authorization.policy_version');
        if (!in_array($version, [self::POLICY_VERSION, self::PROVIDER_POLICY_VERSION, self::PROVIDER_USAGE_POLICY_VERSION], true)) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_POLICY_INVALID');
        }
        return $version;
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_object($value)) { $value = (array) $value; }
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as &$child) { $child = self::normalize($child); }
        unset($child);
        return $value;
    }

    private static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decodeB64url(string $value, int $bytes, bool $exact = true): string
    {
        if (strlen($value) > (int) ceil($bytes * 4 / 3) + 4 || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $value)) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_INVALID');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded) || ($exact ? strlen($decoded) !== $bytes : strlen($decoded) > $bytes)
            || self::b64url($decoded) !== $value) {
            throw new DomainException('NATIVE_NODE_AUTHORIZATION_INVALID');
        }
        return $decoded;
    }
}
