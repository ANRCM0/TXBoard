<?php

namespace Tests\Feature\Subscription;

use App\Core\Kernel\Clock;
use App\Domains\Subscription\Contracts\EntitlementQueries;
use App\Domains\Subscription\Contracts\EntitlementQuery;
use App\Domains\Subscription\Contracts\EntitlementView;
use App\Domains\Subscription\Internal\LockedEntitlementQueries;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionEntitlementResolver;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Http\Controllers\Txapi\AccountController;
use App\Models\Order;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Support\NativeSubscriptionTestCase;
use Tests\Support\ReadsEntitlements;

final class EntitlementQueryBoundaryTest extends NativeSubscriptionTestCase
{
    use ReadsEntitlements;

    public function test_public_and_locked_ports_bind_the_same_authoritative_scoped_resolver(): void
    {
        $queries = app(EntitlementQueries::class);
        $this->assertInstanceOf(SubscriptionEntitlementResolver::class, $queries);
        $this->assertSame($queries, app(EntitlementQueries::class));
        $this->assertSame($queries, app(LockedEntitlementQueries::class));
        $this->assertInstanceOf(Clock::class, app(Clock::class));
        $clock = app(Clock::class);
        $this->assertSame(EntitlementView::SCHEMA, SubscriptionEntitlementResolver::SCHEMA);

        $user = $this->user();
        $view = $queries->forAccount(new EntitlementQuery((int) $user->id));
        $this->assertFalse($view->isAuthorized());
        $this->assertSame('ENTITLEMENT_SLOT_MISSING', $view->toArray()['reason']);
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
        $this->readEntitlement((int) $user->id, time() - 1);
        $this->assertSame($queries, app(EntitlementQueries::class));
        $this->assertSame($clock, app(Clock::class));

        $this->app->forgetScopedInstances();
        $nextScope = app(EntitlementQueries::class);
        $this->assertNotSame($queries, $nextScope);
        $this->assertSame($nextScope, app(LockedEntitlementQueries::class));
        $this->assertSame($clock, app(Clock::class));
    }

    public function test_public_query_refuses_an_outer_transaction(): void
    {
        $user = $this->user();
        $queries = app(EntitlementQueries::class);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ENTITLEMENT_OUTER_TRANSACTION_UNSUPPORTED');
        DB::transaction(fn () => $queries->forAccount(new EntitlementQuery((int) $user->id)));
    }

    public function test_account_adapters_use_authenticated_owner_and_replaceable_public_port(): void
    {
        $owner = $this->user();
        $other = $this->user();
        Sanctum::actingAs($owner);
        $projection = [
            'schema' => EntitlementView::SCHEMA, 'authorized' => false,
            'reason' => 'ENTITLEMENT_SLOT_MISSING', 'user_id' => (string) $owner->id,
        ];
        $queries = Mockery::mock(EntitlementQueries::class);
        $queries->shouldReceive('forAccount')->twice()
            ->withArgs(fn (EntitlementQuery $query): bool => $query->userId === (int) $owner->id)
            ->andReturn(EntitlementView::fromProjection($projection));
        $this->app->instance(EntitlementQueries::class, $queries);
        $this->app->bind(SubscriptionEntitlementResolver::class, static function (): never {
            throw new RuntimeException('CONCRETE_RESOLVER_MUST_NOT_BE_USED');
        });

        $input = ['user_id' => $other->id, 'subscription_id' => '999', 'at' => 0, 'authorized' => true];
        $controller = app(AccountController::class);
        $account = $controller->me(Request::create('/txapi/me', 'GET', $input))->getData(true)['data'];
        $subscription = $controller->subscription(
            Request::create('/txapi/me/subscription', 'GET', $input),
        )->getData(true)['data'];

        $this->assertSame((int) $owner->id, $account['id']);
        $this->assertSame($projection, $account['subscription']);
        $this->assertSame((string) $owner->id, $subscription['user_id']);
        $this->assertFalse($subscription['authorized']);
        $this->assertArrayNotHasKey('quota_bytes', $subscription);
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
    }

    public function test_damaged_evidence_is_a_public_denial_but_aborts_the_locked_query(): void
    {
        $user = $this->user();
        $order = app(PublishedOrderService::class)->create(
            (int) $user->id, $this->publishedOffer($this->plan()), 'boundary-open',
        );
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $at = time() - 10;
        $opened = app(SubscriptionFulfillmentService::class)->fulfill((int) $order->id, $at);
        DB::table('tx_subscription_terms')->where('id', $opened['terms_id'])
            ->update(['content_hash' => str_repeat('0', 64)]);

        $this->assertSame('ENTITLEMENT_EVIDENCE_INVALID', $this->readEntitlement((int) $user->id, $at + 1)['reason']);
        $this->expectException(DomainException::class);
        DB::transaction(fn () => app(LockedEntitlementQueries::class)->resolveLocked((int) $user->id, $at + 1));
    }
}
