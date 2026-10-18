<?php

namespace Tests\Unit\Domains\InternalApi;

use App\Domains\InternalApi\OperationSchemas;
use PHPUnit\Framework\TestCase;

final class OperationMetadataTest extends TestCase
{
    public function test_empty_metadata_uses_the_response_owner_array_contract(): void
    {
        $this->assertSame([], OperationSchemas::meta('billing.wallet.get', []));
        $this->assertSame([], OperationSchemas::meta('identity.session.end', []));
    }

    public function test_cursor_precision_and_closed_pagination_are_validated_independently(): void
    {
        $this->assertSame(['next_after_id' => '9007199254740993'],
            OperationSchemas::meta('billing.recharges.list', ['next_after_id' => '9007199254740993', 'private' => 'removed']));
        $this->expectException(\InvalidArgumentException::class);
        OperationSchemas::meta('commerce.orders.list', ['page' => 1]);
    }
}
