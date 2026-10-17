<?php

namespace App\Domains\Subscription\Contracts;

use InvalidArgumentException;
use JsonSerializable;

/** A closed non-secret projection, not a credential or signed Node grant. */
final readonly class EntitlementView implements JsonSerializable
{
    public const SCHEMA = 'core-entitlement/v1';

    private const BASE_FIELDS = ['schema', 'authorized', 'reason', 'user_id'];
    private const GRANTED_FIELDS = [
        ...self::BASE_FIELDS, 'subscription_id', 'subscription_version', 'plan_id',
        'terms_id', 'terms_version', 'plan_revision_id', 'usage_period_id',
        'period_terms_id', 'period_version', 'group_id', 'speed_limit_mbps',
        'device_limit', 'expires_at', 'next_reset_at', 'quota_bytes',
        'consumed_bytes', 'remaining_bytes', 'upload_bytes', 'download_bytes',
    ];
    private const DENIAL_REASONS = [
        'ENTITLEMENT_STORAGE_FAILED', 'ENTITLEMENT_EVIDENCE_INVALID',
        'ENTITLEMENT_ACCOUNT_MISSING', 'ENTITLEMENT_ACCOUNT_BANNED',
        'ENTITLEMENT_SLOT_MISSING', 'ENTITLEMENT_SLOT_EMPTY',
        'ENTITLEMENT_SUSPENDED', 'ENTITLEMENT_EXPIRED', 'ENTITLEMENT_REVOKED',
        'ENTITLEMENT_PENDING', 'ENTITLEMENT_NOT_STARTED',
        'ENTITLEMENT_PERIOD_NOT_STARTED', 'ENTITLEMENT_PERIOD_EXPIRED',
        'ENTITLEMENT_QUOTA_EXHAUSTED',
    ];

    private array $projection;

    private function __construct(array $projection)
    {
        // Detach embedded PHP references as well as ordinary array copy-on-write.
        $values = [];
        foreach ($projection as $key => $value) {
            $values[$key] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? true : false,
                default => (string) $value,
            };
        }
        $this->projection = $values;
    }

    public static function fromProjection(array $projection): self
    {
        self::require(($projection['schema'] ?? null) === self::SCHEMA
            && is_bool($projection['authorized'] ?? null));
        $granted = $projection['authorized'];
        $fields = $granted ? self::GRANTED_FIELDS : self::BASE_FIELDS;
        self::require(count($projection) === count($fields)
            && array_diff($fields, array_keys($projection)) === []);
        self::require(self::decimal($projection['user_id'], true));

        if (!$granted) {
            self::require(in_array($projection['reason'], self::DENIAL_REASONS, true));
            return new self($projection);
        }

        self::require($projection['reason'] === 'ENTITLEMENT_GRANTED');
        foreach (['subscription_id', 'subscription_version', 'plan_id', 'terms_id',
            'terms_version', 'plan_revision_id', 'usage_period_id', 'period_terms_id',
            'period_version'] as $field) {
            self::require(self::decimal($projection[$field], true));
        }
        foreach (['quota_bytes', 'consumed_bytes', 'remaining_bytes', 'upload_bytes', 'download_bytes'] as $field) {
            self::require(self::decimal($projection[$field]));
        }
        foreach (['group_id', 'speed_limit_mbps', 'device_limit', 'expires_at', 'next_reset_at'] as $field) {
            self::require($projection[$field] === null || self::decimal($projection[$field]));
        }

        return new self($projection);
    }

    public function isAuthorized(): bool
    {
        return $this->projection['authorized'];
    }

    public function toArray(): array
    {
        return $this->projection;
    }

    public function jsonSerialize(): array
    {
        return $this->projection;
    }

    private static function decimal(mixed $value, bool $positive = false): bool
    {
        return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && (!$positive || $value !== '0');
    }

    private static function require(bool $condition): void
    {
        if (!$condition) {
            throw new InvalidArgumentException('ENTITLEMENT_VIEW_INVALID');
        }
    }
}
