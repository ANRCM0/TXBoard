<?php

namespace Tests\Unit\Core;

use App\Core\Host\Contracts\TransportSchema;
use PHPUnit\Framework\TestCase;

final class TransportSchemaTest extends TestCase
{
    private function contract(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/contracts/core-operations.v1.json'),
            true, 64, JSON_THROW_ON_ERROR);
    }

    public function test_canonical_decimal_range_and_strict_input_object(): void
    {
        $contract = $this->contract();
        $validator = new TransportSchema($contract['definitions']);
        $schema = $contract['operations']['commerce.orders.create']['input'];
        self::assertTrue($validator->accepts(['offer_id' => '9007199254740993'], $schema));
        foreach ([1, '01', '0', ' 1', '1e2', '9223372036854775808'] as $bad) {
            self::assertFalse($validator->accepts(['offer_id' => $bad], $schema));
        }
        self::assertFalse($validator->accepts(['offer_id' => '1', 'actor' => 'admin'], $schema));
    }

    public function test_nested_output_projection_removes_unlisted_financial_and_secret_fields(): void
    {
        $contract = $this->contract();
        $validator = new TransportSchema($contract['definitions']);
        $schema = $contract['operations']['commerce.orders.checkout']['output'];
        $result = $validator->project(['type' => 1, 'data' => 'https://pay.example.test/checkout',
            'attempt' => ['attempt_id' => '1', 'status' => 'pending', 'intent_type' => 'order',
                'order_id' => '2', 'recharge_id' => null, 'payment_id' => '3', 'amount_minor' => '100',
                'currency' => 'CNY', 'request_key' => 'private-request', 'purchase_content_hash' => 'private',
                'internal' => ['secret' => 'private']],
            'snapshot_ciphertext' => 'private'], $schema);
        self::assertSame('1', $result['attempt']['attempt_id']);
        self::assertArrayNotHasKey('request_key', $result['attempt']);
        self::assertArrayNotHasKey('purchase_content_hash', $result['attempt']);
        self::assertArrayNotHasKey('internal', $result['attempt']);
        self::assertArrayNotHasKey('snapshot_ciphertext', $result);
    }

    public function test_malformed_nested_financial_data_is_rejected_instead_of_coerced(): void
    {
        $contract = $this->contract();
        $validator = new TransportSchema($contract['definitions']);
        $this->expectExceptionMessage('CONTRACT_OUTPUT_INVALID');
        $validator->project(['schema' => 'native-wallet/v1', 'balances' => [
            ['currency' => 'CNY', 'available_minor' => 9007199254740993, 'reserved_minor' => '0'],
        ]], $contract['operations']['billing.wallet.get']['output']);
    }
}
