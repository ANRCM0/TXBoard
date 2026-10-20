<?php

namespace Tests\Unit\Core;

use App\Domains\Platform\ModulePublicProjection;
use Tests\TestCase;

final class ModulePublicProjectionTest extends TestCase
{
    public function test_native_result_json_cannot_bypass_recursive_credential_projection(): void
    {
        config(['core_modules.secrets' => ['test' => 'resolved-host-value']]);
        $result = ModulePublicProjection::data(['label' => 'Visible', 'token' => 'private', 'nested' => [
            ['private_key' => 'hidden', 'title' => 'secret:other'], ['title' => 'prefix resolved-host-value suffix'], ['count' => 4]]]);
        $this->assertSame(['label' => 'Visible', 'nested' => [['title' => '[REDACTED]'], ['title' => '[REDACTED]'], ['count' => 4]]], $result);
        $schema = ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'token' => ['type' => 'string']],
            'required' => ['label', 'token'], 'additionalProperties' => false];
        $this->assertSame(['label'], array_keys(ModulePublicProjection::schema($schema)['properties']));
        $this->assertSame(['label'], ModulePublicProjection::schema($schema)['required']);
        $object = json_decode('{"nested":{},"list":[],"token":"withheld"}', false, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('{"nested":{},"list":[]}', json_encode(ModulePublicProjection::data($object), JSON_THROW_ON_ERROR));
    }
}
