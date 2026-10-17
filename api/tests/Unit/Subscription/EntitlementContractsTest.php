<?php

namespace Tests\Unit\Subscription;

use App\Domains\Subscription\Contracts\EntitlementQuery;
use App\Domains\Subscription\Contracts\EntitlementView;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EntitlementContractsTest extends TestCase
{
    public function test_query_and_view_are_final_immutable_value_types(): void
    {
        foreach ([EntitlementQuery::class, EntitlementView::class] as $class) {
            $reflection = new ReflectionClass($class);
            $this->assertTrue($reflection->isFinal());
            $this->assertTrue($reflection->isReadOnly());
        }

        $query = new EntitlementQuery(17);
        $this->assertSame(17, $query->userId);
        $this->expectException(Error::class);
        $query->userId = 18;
    }

    #[DataProvider('invalidAccountIds')]
    public function test_query_rejects_nonpositive_account_ids(int $userId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ENTITLEMENT_INPUT_INVALID');
        new EntitlementQuery($userId);
    }

    public static function invalidAccountIds(): array
    {
        return [[0], [-1], [PHP_INT_MIN]];
    }

    public function test_granted_projection_preserves_exact_wire_fields_order_and_scalar_types(): void
    {
        $projection = self::grantedProjection();
        $view = EntitlementView::fromProjection($projection);

        $this->assertSame('core-entitlement/v1', EntitlementView::SCHEMA);
        $this->assertTrue($view->isAuthorized());
        $this->assertCount(23, $view->toArray());
        $this->assertSame($projection, $view->toArray());
        $this->assertSame($projection, $view->jsonSerialize());
        $this->assertSame(json_encode($projection, JSON_THROW_ON_ERROR), json_encode($view, JSON_THROW_ON_ERROR));

        $projection['quota_bytes'] = '999';
        $copy = $view->toArray();
        $copy['user_id'] = '19';
        $copy['uuid'] = 'not-a-real-secret';
        $this->assertSame(self::grantedProjection(), $view->toArray());
    }

    public function test_nullable_limits_and_unbounded_time_keep_their_current_representation(): void
    {
        $projection = self::grantedProjection();
        foreach (['group_id', 'speed_limit_mbps', 'device_limit', 'expires_at', 'next_reset_at'] as $field) {
            $projection[$field] = null;
        }
        $this->assertSame($projection, EntitlementView::fromProjection($projection)->toArray());
    }

    public function test_projection_detaches_caller_owned_scalar_references(): void
    {
        $userId = '17';
        $projection = self::grantedProjection();
        $projection['user_id'] = &$userId;
        $view = EntitlementView::fromProjection($projection);
        $userId = '99';
        $copy = $view->toArray();
        $copy['user_id'] = '100';

        $this->assertSame('17', $view->toArray()['user_id']);
        $this->assertSame('99', $userId);
    }

    #[DataProvider('denialReasons')]
    public function test_denied_projection_has_only_four_fields_and_no_grant_data(string $reason): void
    {
        $projection = self::deniedProjection($reason);
        $view = EntitlementView::fromProjection($projection);

        $this->assertFalse($view->isAuthorized());
        $this->assertCount(4, $view->toArray());
        $this->assertSame($projection, $view->toArray());
        $this->assertArrayNotHasKey('quota_bytes', $view->jsonSerialize());
        $this->assertArrayNotHasKey('subscription_id', $view->jsonSerialize());
    }

    public static function denialReasons(): array
    {
        return array_map(static fn (string $reason): array => [$reason], [
            'ENTITLEMENT_ACCOUNT_MISSING', 'ENTITLEMENT_ACCOUNT_BANNED',
            'ENTITLEMENT_SLOT_MISSING', 'ENTITLEMENT_SLOT_EMPTY',
            'ENTITLEMENT_SUSPENDED', 'ENTITLEMENT_EXPIRED', 'ENTITLEMENT_REVOKED',
            'ENTITLEMENT_PENDING', 'ENTITLEMENT_NOT_STARTED',
            'ENTITLEMENT_PERIOD_NOT_STARTED', 'ENTITLEMENT_PERIOD_EXPIRED',
            'ENTITLEMENT_QUOTA_EXHAUSTED', 'ENTITLEMENT_STORAGE_FAILED',
            'ENTITLEMENT_EVIDENCE_INVALID',
        ]);
    }

    #[DataProvider('invalidProjections')]
    public function test_unknown_fields_invalid_schema_and_noncanonical_values_are_rejected(array $projection): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ENTITLEMENT_VIEW_INVALID');
        EntitlementView::fromProjection($projection);
    }

    public static function invalidProjections(): array
    {
        $granted = self::grantedProjection();
        $denied = self::deniedProjection('ENTITLEMENT_SLOT_MISSING');
        $missing = $granted;
        unset($missing['period_terms_id']);
        $extraGrant = $granted + ['uuid' => 'not-a-real-secret'];
        $extraDenial = $denied + ['quota_bytes' => '10'];

        return [
            'empty' => [[]],
            'missing-grant-field' => [$missing],
            'secret-extra-field' => [$extraGrant],
            'grant-data-on-denial' => [$extraDenial],
            'schema-version' => [array_replace($granted, ['schema' => 'core-entitlement/v2'])],
            'integer-boolean' => [array_replace($granted, ['authorized' => 1])],
            'string-boolean' => [array_replace($granted, ['authorized' => 'true'])],
            'wrong-granted-reason' => [array_replace($granted, ['reason' => 'ENTITLEMENT_EXPIRED'])],
            'wrong-denied-reason' => [array_replace($denied, ['reason' => 'ENTITLEMENT_GRANTED'])],
            'unknown-denied-reason' => [array_replace($denied, ['reason' => 'ENTITLEMENT_CUSTOM'])],
            'integer-id' => [array_replace($granted, ['user_id' => 17])],
            'zero-id' => [array_replace($granted, ['user_id' => '0'])],
            'leading-zero-id' => [array_replace($granted, ['subscription_id' => '01'])],
            'zero-version' => [array_replace($granted, ['terms_version' => '0'])],
            'floating-counter' => [array_replace($granted, ['consumed_bytes' => 50.0])],
            'scientific-counter' => [array_replace($granted, ['quota_bytes' => '2e9'])],
            'negative-counter' => [array_replace($granted, ['upload_bytes' => '-1'])],
            'null-counter' => [array_replace($granted, ['download_bytes' => null])],
            'integer-time' => [array_replace($granted, ['expires_at' => 2000000000])],
            'object-limit' => [array_replace($granted, ['device_limit' => (object) ['value' => '4']])],
            'nested-grant-field' => [array_replace($granted, ['group_id' => ['id' => '7']])],
        ];
    }

    private static function grantedProjection(): array
    {
        return [
            'schema' => 'core-entitlement/v1', 'authorized' => true, 'reason' => 'ENTITLEMENT_GRANTED',
            'user_id' => '17', 'subscription_id' => '18', 'subscription_version' => '2', 'plan_id' => '19',
            'terms_id' => '20', 'terms_version' => '2', 'plan_revision_id' => '21',
            'usage_period_id' => '22', 'period_terms_id' => '23', 'period_version' => '3',
            'group_id' => '7', 'speed_limit_mbps' => '30', 'device_limit' => '4',
            'expires_at' => '2000000000', 'next_reset_at' => '1999999999',
            'quota_bytes' => '2147483648', 'consumed_bytes' => '50', 'remaining_bytes' => '2147483598',
            'upload_bytes' => '11', 'download_bytes' => '22',
        ];
    }

    private static function deniedProjection(string $reason): array
    {
        return ['schema' => 'core-entitlement/v1', 'authorized' => false, 'reason' => $reason, 'user_id' => '17'];
    }
}
