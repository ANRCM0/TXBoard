<?php

namespace Tests\Unit\Domains\InternalApi;

use App\Domains\InternalApi\OperationSchemas;
use PHPUnit\Framework\TestCase;

final class DiscoverySchemaTest extends TestCase
{
    public function test_discovery_only_carries_transitively_used_definitions_and_preserves_object_shapes(): void
    {
        $wire = OperationSchemas::wire(OperationSchemas::operation('identity.admin.get')['output']);
        $this->assertEquals(['id'], array_keys((array) $wire['definitions']));
        $this->assertInstanceOf(\stdClass::class, $wire['properties']);
        $empty = OperationSchemas::wire(OperationSchemas::operation('identity.admin.get')['input']);
        $this->assertInstanceOf(\stdClass::class, $empty['properties']);
        $this->assertInstanceOf(\stdClass::class, $empty['definitions']);
    }
}
