<?php

namespace Tests\Unit\Domains\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\MoneyValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyValueTest extends TestCase
{
    public function test_minor_units_keep_full_integer_precision(): void
    {
        self::assertSame('0', MoneyValue::minor(0));
        self::assertSame('9007199254740993', MoneyValue::minor('9007199254740993'));
        self::assertSame(MoneyValue::MAX, MoneyValue::minor(MoneyValue::MAX, true));
        self::assertSame('-'.MoneyValue::MAX, MoneyValue::signed('-'.MoneyValue::MAX));
        self::assertSame('9007199254740994', MoneyValue::add('9007199254740993', '1'));
        self::assertSame('0', MoneyValue::add(MoneyValue::MAX, '-'.MoneyValue::MAX));
    }

    #[DataProvider('invalidAmounts')]
    public function test_minor_units_reject_lossy_or_noncanonical_values(mixed $value): void
    {
        $this->expectExceptionMessage('BILLING_AMOUNT_INVALID');
        MoneyValue::minor($value);
    }

    public static function invalidAmounts(): array
    {
        return [[null], [true], [false], [1.0], [1.5], [[]], [''], ['01'], ['00'],
            ['+1'], ['-1'], ['1.00'], ['1e3'], [' 1'], ["1\n"],
            ['9223372036854775808'], ['100000000000000000000']];
    }

    #[DataProvider('invalidSignedAmounts')]
    public function test_signed_values_reject_negative_zero_and_overflow(mixed $value): void
    {
        $this->expectExceptionMessage('BILLING_AMOUNT_INVALID');
        MoneyValue::signed($value);
    }

    public static function invalidSignedAmounts(): array
    {
        return [['-0'], ['-01'], ['--1'], ['-9223372036854775808'], [1.0]];
    }

    #[DataProvider('overflowingSums')]
    public function test_addition_refuses_both_overflow_directions(string $left, string $right): void
    {
        $this->expectExceptionMessage('BILLING_AMOUNT_INVALID');
        MoneyValue::add($left, $right);
    }

    public static function overflowingSums(): array
    {
        return [[MoneyValue::MAX, '1'], ['-'.MoneyValue::MAX, '-1']];
    }

    public function test_positive_transfer_cannot_be_zero(): void
    {
        $this->expectExceptionMessage('BILLING_AMOUNT_INVALID');
        MoneyValue::minor('0', true);
    }

    public function test_currency_and_business_keys_are_canonical(): void
    {
        self::assertSame('USD', MoneyValue::currency('USD'));
        self::assertSame('order:42.capture-1', MoneyValue::key('order:42.capture-1'));
        foreach (['usd', 'US', "USD\n", 123] as $currency) {
            try {
                MoneyValue::currency($currency);
                self::fail('Invalid currency accepted');
            } catch (BillingError $error) {
                self::assertSame('BILLING_CURRENCY_INVALID', $error->errorCode);
            }
        }
        foreach (['', '-start', 'with space', str_repeat('a', 129)] as $key) {
            try {
                MoneyValue::key($key);
                self::fail('Invalid business key accepted');
            } catch (BillingError $error) {
                self::assertSame('BILLING_BUSINESS_KEY_INVALID', $error->errorCode);
            }
        }
    }

    public function test_hash_sorts_object_fields_but_preserves_posting_order_and_types(): void
    {
        $input = ['currency' => 'USD', 'postings' => [
            ['account' => 'wallet_available', 'amount' => '20'],
            ['account' => 'provider_clearing', 'amount' => '20'],
        ]];
        $reordered = ['postings' => [
            ['amount' => '20', 'account' => 'wallet_available'],
            ['amount' => '20', 'account' => 'provider_clearing'],
        ], 'currency' => 'USD'];
        self::assertSame(MoneyValue::hash($input), MoneyValue::hash($reordered));
        $reordered['postings'] = array_reverse($reordered['postings']);
        self::assertNotSame(MoneyValue::hash($input), MoneyValue::hash($reordered));
        self::assertNotSame(MoneyValue::hash(['amount' => '20']), MoneyValue::hash(['amount' => 20]));
    }
}
