<?php

namespace Tests\Unit;

use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use PHPUnit\Framework\TestCase;

final class RedemptionCodeContractTest extends TestCase
{
    public function test_template_types_use_redemption_code_names(): void
    {
        $types = GiftCardTemplate::getTypeMap();
        self::assertSame('通用兑换码', $types[GiftCardTemplate::TYPE_GENERAL]);
        self::assertSame('套餐兑换码', $types[GiftCardTemplate::TYPE_PLAN]);
        self::assertSame('盲盒兑换码', $types[GiftCardTemplate::TYPE_MYSTERY]);
    }

    public function test_existing_type_and_status_identifiers_are_preserved(): void
    {
        self::assertSame(1, GiftCardTemplate::TYPE_GENERAL);
        self::assertSame(2, GiftCardTemplate::TYPE_PLAN);
        self::assertSame(3, GiftCardTemplate::TYPE_MYSTERY);
        self::assertSame(0, GiftCardCode::STATUS_UNUSED);
        self::assertSame(1, GiftCardCode::STATUS_USED);
        self::assertSame(2, GiftCardCode::STATUS_EXPIRED);
        self::assertSame(3, GiftCardCode::STATUS_DISABLED);
    }

    public function test_code_format_validation_remains_compatible(): void
    {
        self::assertTrue(GiftCardCode::validateCodeFormat('GC1234567890'));
        self::assertFalse(GiftCardCode::validateCodeFormat('invalid-code'));
        self::assertSame(0, GiftCardCode::validateCodeFormat('gc1234567890'));
    }
}
