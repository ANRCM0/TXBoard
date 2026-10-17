<?php

namespace Tests\Unit\Domains\InternalApi;

use App\Domains\InternalApi\StrictJson;
use JsonException;
use PHPUnit\Framework\TestCase;

final class StrictJsonTest extends TestCase
{
    public function test_rejects_duplicate_decoded_keys_at_any_depth(): void
    {
        foreach (['{"input":{},"input":{"actor":"1"}}', '{"input":{"x":1,"\\u0078":2}}',
            '{"input":[{"amount":"1","amount":"2"}]}'] as $input) {
            try {
                StrictJson::object($input);
                $this->fail('Duplicate key accepted');
            } catch (JsonException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_preserves_decimal_strings_escaped_strings_and_distinct_key_scopes(): void
    {
        $result = StrictJson::object('{"a":{"id":"9223372036854775807"},"b":{"id":"2"},"text":"a\\\\b\\"c"}');
        $this->assertSame('9223372036854775807', $result['a']['id']);
        $this->assertSame('a\\b"c', $result['text']);
    }

    public function test_required_object_members_cannot_be_lists_or_scalars_even_when_empty(): void
    {
        $this->assertSame(['input' => []], StrictJson::object('{"input":{}}', ['input']));
        foreach (['{"input":[]}', '{"input":null}', '{"input":"secret"}', '{}', '[]'] as $input) {
            try {
                StrictJson::object($input, ['input']);
                $this->fail('Non-object input accepted');
            } catch (JsonException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
