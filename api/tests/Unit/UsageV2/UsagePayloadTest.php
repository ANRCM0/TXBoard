<?php

namespace Tests\Unit\UsageV2;

use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsageMath;
use App\Domains\UsageV2\UsagePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsagePayloadTest extends TestCase
{
    private function vector(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../../../contracts/v2/node-usage/golden-vector.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_shared_golden_vector_matches_node_canonical_bytes_and_digest(): void
    {
        $vector = $this->vector(); $p = json_decode($vector['canonical_payload'], true);
        $parsed = (new UsagePayload())->submission(json_encode(['payload_digest' => $vector['payload_digest'], 'payload' => $p]));
        $this->assertSame($vector['canonical_payload'], $parsed['canonical']);
        $this->assertSame($vector['payload_digest'], $parsed['digest']);
    }

    #[DataProvider('invalidJson')]
    public function test_duplicate_keys_and_malformed_json_are_rejected_before_schema_decoding(string $raw, string $code): void
    {
        try { (new UsagePayload())->decode($raw); $this->fail('Invalid JSON accepted'); }
        catch (UsageError $e) { $this->assertSame($code, $e->errorCode); }
    }

    public static function invalidJson(): array
    {
        return [
            ['{"a":1,"a":2}', 'USAGE_JSON_DUPLICATE_KEY'],
            ['{"a":1,"\u0061":2}', 'USAGE_JSON_DUPLICATE_KEY'],
            ['{"p":[{"x":1,"x":2}]}', 'USAGE_JSON_DUPLICATE_KEY'],
            ['{"a":01}', 'USAGE_JSON_INVALID'], ['{"a":NaN}', 'USAGE_JSON_INVALID'],
            ['{"a":1,}', 'USAGE_JSON_INVALID'], ['{} true', 'USAGE_JSON_INVALID'],
            [str_repeat('[', 18).'0'.str_repeat(']', 18), 'USAGE_JSON_DEPTH'],
        ];
    }

    public function test_unknown_fields_numeric_ids_null_and_unsorted_entries_are_rejected(): void
    {
        $vector = $this->vector(); $p = json_decode($vector['canonical_payload'], true);
        $invalid = [];
        $extra = $p; $extra['extra'] = 1; $invalid[] = $extra;
        $numeric = $p; $numeric['node_id'] = 42; $invalid[] = $numeric;
        $null = $p; $null['source_epoch'] = null; $invalid[] = $null;
        $leading = $p; $leading['source_epoch'] = '01'; $invalid[] = $leading;
        $duplicate = $p; $duplicate['entries'][] = $p['entries'][0]; $invalid[] = $duplicate;
        $huge = $p; $huge['entries'][0]['raw_up_bytes'] = '1125899906842625'; $invalid[] = $huge;
        foreach ($invalid as $payload) {
            try { (new UsagePayload())->submission(json_encode(['payload' => $payload,'payload_digest' => $vector['payload_digest']])); $this->fail('Invalid payload accepted'); }
            catch (UsageError) { $this->addToAssertionCount(1); }
        }
    }

    public function test_rational_tariff_uses_integer_floor_and_detects_overflow(): void
    {
        $this->assertSame('1', UsageMath::floor('3', '1', '2'));
        $this->assertSame('6004799503160662', UsageMath::floor('9007199254740993', '2', '3'));
        $this->expectExceptionMessage('USAGE_ACCOUNTING_OVERFLOW');
        UsageMath::floor('1125899906842624', '100000', '1');
    }
}
