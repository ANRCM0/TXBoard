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

    public function test_every_published_schema_uses_the_native_transport_vocabulary(): void
    {
        $contract = $this->contract();
        $expand = function (array $schema, array $references = []) use (&$expand, $contract): array {
            if (isset($schema['$ref'])) {
                $name = substr($schema['$ref'], strlen('#/definitions/'));
                self::assertArrayHasKey($name, $contract['definitions']);
                self::assertNotContains($name, $references, 'Schema references must be acyclic');
                return $expand($contract['definitions'][$name], [...$references, $name]);
            }
            foreach ($schema['properties'] ?? [] as $name => $property) $schema['properties'][$name] = $expand($property, $references);
            if (isset($schema['items'])) $schema['items'] = $expand($schema['items'], $references);
            if (isset($schema['anyOf'])) $schema['anyOf'] = array_map(fn (array $variant): array => $expand($variant, $references), $schema['anyOf']);
            return $schema;
        };
        foreach ($contract['definitions'] as $schema) TransportSchema::assertDefinition($expand($schema));
        foreach ($contract['operations'] as $operation) {
            foreach (['input', 'output', 'meta'] as $field) {
                if (isset($operation[$field])) TransportSchema::assertDefinition($expand($operation[$field]));
            }
        }
        self::assertNotEmpty($contract['operations']);
    }

    public function test_nested_output_projection_removes_unlisted_financial_and_secret_fields(): void
    {
        $contract = $this->contract();
        $validator = new TransportSchema($contract['definitions']);
        $schema = $contract['operations']['commerce.orders.checkout']['output'];
        $result = $validator->project(['type' => 1, 'data' => 'https://pay.example.test/checkout',
            'attempt' => ['attempt_id' => '1', 'status' => 'pending', 'intent_type' => 'purchase',
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

    public function test_wire_preserves_empty_object_and_array_distinctions_at_nested_boundaries(): void
    {
        $empty = ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false];
        $schema = ['type' => 'object', 'properties' => ['configuration' => $empty], 'required' => ['configuration'], 'additionalProperties' => false];
        $validator = new TransportSchema;
        self::assertTrue($validator->acceptsWire(json_decode('{"configuration":{}}'), $schema));
        self::assertFalse($validator->acceptsWire(json_decode('{"configuration":[]}'), $schema));
        self::assertSame('{"configuration":{}}', json_encode($validator->project(['configuration' => []], $schema)));
        self::assertSame('{}', json_encode(TransportSchema::wireDefinition($empty)['properties']));
    }

    public function test_schema_vocabulary_preserves_absolute_end_and_unicode_scalar_length(): void
    {
        $validator = new TransportSchema;
        self::assertFalse($validator->accepts("12\n", ['type' => 'string', 'pattern' => '^[1-9][0-9]*$']));
        self::assertTrue($validator->accepts("\u{1F642}", ['type' => 'string', 'maxLength' => 1]));
        self::assertFalse($validator->accepts("\u{1F642}\u{1F642}", ['type' => 'string', 'maxLength' => 1]));
        foreach ([['type' => 'string', 'maxLength' => -1], ['const' => 1, 'type' => 'integer'],
            ['type' => 'string', 'x-decimal-maximum' => '01'], ['type' => 'string', 'pattern' => '[']] as $invalid) {
            try { TransportSchema::assertDefinition($invalid); self::fail('Expected invalid schema'); }
            catch (\InvalidArgumentException $error) { self::assertSame('CONTRACT_SCHEMA_INVALID', $error->getMessage()); }
        }
    }
}
