<?php

namespace Tests\Unit\UsageV2;

use App\Domains\InternalApi\OperationRegistry;
use App\Domains\InternalApi\ReadOperationResult;
use App\Domains\InternalApi\UsageReadOperations;
use App\Domains\UsageV2\Contracts\UsagePage;
use App\Domains\UsageV2\Contracts\UsageQueries;
use App\Domains\UsageV2\Contracts\UsageQuery;
use App\Domains\UsageV2\Contracts\UsageTargetMissing;
use App\Models\User;
use Mockery;
use Tests\TestCase;

final class UsageQueriesContractsTest extends TestCase
{
    public function test_query_is_readonly_and_enforces_frozen_positive_owner_nonnegative_cursor_and_bounded_limit(): void
    {
        $query = new UsageQuery();
        $this->assertSame(['subscriptionId' => null, 'afterId' => 0, 'limit' => 100], get_object_vars($query));
        $this->assertTrue((new \ReflectionClass(UsageQuery::class))->isReadOnly());
        foreach ([[0, 0, 1], [-1, 0, 1], [null, -1, 1], [null, 0, 0], [null, 0, 101]] as [$subscription, $after, $limit]) {
            try {
                new UsageQuery($subscription, $after, $limit);
                $this->fail('Invalid query bounds were accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('NATIVE_USAGE_INPUT_INVALID', $error->getMessage());
            }
        }
        $this->assertSame(['subscriptionId' => 9, 'afterId' => 70, 'limit' => 1],
            get_object_vars(new UsageQuery(9, 70, 1)));
    }

    public function test_page_and_missing_target_are_pure_domain_values(): void
    {
        $items = [['id' => '71']];
        $page = new UsagePage($items, '71');
        $this->assertSame(['items' => $items, 'nextAfterId' => '71'], get_object_vars($page));
        $this->assertTrue((new \ReflectionClass(UsagePage::class))->isReadOnly());
        $this->assertSame('NATIVE_USAGE_SUBSCRIPTION_NOT_FOUND', (new UsageTargetMissing())->getMessage());
    }

    public function test_contract_has_declared_domain_methods_and_no_framework_http_or_sql_types(): void
    {
        $contract = new \ReflectionClass(UsageQueries::class);
        $this->assertTrue($contract->isInterface());
        $this->assertSame(['nodeTrafficHistory', 'listing'], array_map(static fn ($method) => $method->name, $contract->getMethods()));
        $history = $contract->getMethod('nodeTrafficHistory');
        $this->assertSame(['array', 'int', 'int'], array_map(static fn ($parameter) => (string) $parameter->getType(), $history->getParameters()));
        $this->assertSame('array', (string) $history->getReturnType());
        $method = $contract->getMethod('listing');
        $this->assertSame(['int', UsageQuery::class], array_map(static fn ($parameter) => (string) $parameter->getType(), $method->getParameters()));
        $this->assertSame(UsagePage::class, (string) $method->getReturnType());
        $this->assertSame(['userId', 'query'], array_map(static fn ($parameter) => $parameter->name, $method->getParameters()));
        $this->assertSame([], array_intersect(['Request', 'Builder', 'JsonResponse', 'User'],
            array_map(static fn ($parameter) => (string) $parameter->getType(), $method->getParameters())));
    }

    public function test_fixed_handler_selects_actor_only_maps_missing_to_null_and_keeps_adapter_result_data_local(): void
    {
        $actor = new User();
        $actor->forceFill(['id' => 7]);
        $port = Mockery::mock(UsageQueries::class);
        $port->shouldReceive('listing')->once()->with(7, Mockery::on(static fn ($query): bool =>
            $query instanceof UsageQuery && $query->subscriptionId === 12 && $query->afterId === 70 && $query->limit === 1))
            ->andReturn(new UsagePage([['id' => '71']], '71'));
        $handler = new UsageReadOperations($port);
        $result = $handler->listing($actor, ['subscription_id' => '12', 'after_id' => '70', 'limit' => 1, 'user_id' => '999']);
        $this->assertInstanceOf(ReadOperationResult::class, $result);
        $this->assertSame([['id' => '71']], $result->data);
        $this->assertSame(['schema' => 'native-usage-periods/v1', 'next_after_id' => '71'], $result->meta);

        $missing = Mockery::mock(UsageQueries::class);
        $missing->shouldReceive('listing')->once()->andThrow(new UsageTargetMissing());
        $this->assertNull((new UsageReadOperations($missing))->listing($actor, []));
        $failed = Mockery::mock(UsageQueries::class);
        $failed->shouldReceive('listing')->once()->andThrow(new \RuntimeException('safe pipeline should sanitize'));
        $this->expectException(\RuntimeException::class);
        (new UsageReadOperations($failed))->listing($actor, []);
    }

    public function test_internal_registry_keeps_usage_read_fixed_and_receipt_free(): void
    {
        $spec = OperationRegistry::all()['usage.mine.list'];
        $this->assertSame('read', $spec['mode']);
        $this->assertSame('user', $spec['actor']);
        $this->assertSame(['user'], $spec['domains']);
        $this->assertSame(UsageReadOperations::class, $spec['handler']);
        $this->assertNull($spec['command']);
        $this->assertNull($spec['controller']);
        $this->assertSame(['subscription_id', 'after_id', 'limit'],
            array_keys(\App\Domains\InternalApi\OperationSchemas::operation('usage.mine.list')['input']['properties']));
        $this->assertTrue($spec['list']);
        $this->assertSame('listing', $spec['method']);
    }
}
