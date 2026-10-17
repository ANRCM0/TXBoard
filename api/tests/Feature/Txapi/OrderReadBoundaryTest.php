<?php

namespace Tests\Feature\Txapi;

use App\Domains\Billing\Contracts\OrderListQuery;
use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Billing\Contracts\OrderQueries;
use App\Domains\Billing\NativeOrderQueries;
use App\Domains\InternalApi\ServiceCredentials;
use App\Http\Controllers\Txapi\AccountController;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\NativeRuntimeTestCase;

final class OrderReadBoundaryTest extends NativeRuntimeTestCase
{
    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        $this->service = app(ServiceCredentials::class)->issue('order-read-gateway', 'user', self::FINGERPRINT,
            ['commerce.orders.list', 'commerce.orders.get'], time() + 3600);
    }

    public function test_scoped_native_queries_preserve_stable_pagination_types_and_authenticated_owner(): void
    {
        $queries = app(OrderQueries::class);
        $this->assertInstanceOf(NativeOrderQueries::class, $queries);
        $this->assertSame($queries, app(OrderQueries::class));
        $a = $this->user();
        $b = $this->user();
        $aToken = $a->createToken('owner-a', ['*'])->plainTextToken;
        $bToken = $b->createToken('owner-b', ['*'])->plainTextToken;
        $first = $this->order($a, ['status' => 0]);
        $second = $this->order($a, ['status' => 3, 'paid_at' => 1700000001]);
        $third = $this->order($a, ['status' => 0]);
        $bOrder = $this->order($b);
        $before = DB::table('tx_order')->orderBy('id')->get()->toJson();

        foreach ([1 => $third, 2 => $second, 3 => $first] as $page => $order) {
            $input = ['page' => $page, 'per_page' => 1];
            $http = $this->http('/txapi/orders?'.http_build_query($input), $aToken)->assertOk();
            $internal = $this->invoke('commerce.orders.list', $aToken, $input)->assertOk();
            $this->assertSame([$this->summary($order)], $http->json('data'));
            $this->assertSame($this->decimalIdentifiers($http->json('data')), $internal->json('data'));
            $this->assertSame(['page' => $page, 'per_page' => 1, 'total' => 3, 'last_page' => 3], $http->json('meta'));
            $this->assertSame($http->json('meta'), $internal->json('meta'));
        }
        $filtered = $this->http('/txapi/orders?status=0', $aToken)->assertOk();
        $this->assertSame([$this->summary($third), $this->summary($first)], $filtered->json('data'));
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1], $filtered->json('meta'));
        $this->invoke('commerce.orders.list', $aToken, ['status' => 0])->assertOk()
            ->assertJsonPath('data', $this->decimalIdentifiers($filtered->json('data')))
            ->assertJsonPath('meta', $filtered->json('meta'));
        $this->invoke('commerce.orders.list', $aToken, ['page' => 4, 'per_page' => 1])->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.page', 4)->assertJsonPath('meta.total', 3);
        foreach ([[$aToken, $third], [$bToken, $bOrder], [$aToken, $first], [$bToken, $bOrder]] as [$token, $order]) {
            $http = $this->http('/txapi/orders/'.$order->trade_no, $token)->assertOk();
            $this->assertSame($this->summary($order), $http->json('data'));
            $this->invoke('commerce.orders.get', $token, ['trade_no' => $order->trade_no])->assertOk()
                ->assertJsonPath('data', $this->decimalIdentifiers($http->json('data')));
        }
        $this->assertSame($before, DB::table('tx_order')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        $this->app->forgetScopedInstances();
        $this->assertNotSame($queries, app(OrderQueries::class));
    }

    public function test_summary_detail_and_missing_plan_keep_their_existing_ownership_and_projection_rules(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $token = $owner->createToken('detail-owner', ['*'])->plainTextToken;
        $otherToken = $other->createToken('other-owner', ['*'])->plainTextToken;
        $order = $this->order($owner, ['payment_id' => 42, 'balance_amount' => 15, 'discount_amount' => 25]);
        $plan = Plan::query()->findOrFail($order->plan_id);
        $plan->forceFill(['name' => 'Current display name', 'transfer_enable' => 7])->saveOrFail();
        $summary = $this->summary($order);
        $this->http('/txapi/orders/'.$order->trade_no, $token)->assertOk()->assertJsonPath('data', $summary);
        $detail = $summary;
        $detail['plan']['traffic_limit_bytes'] = 7 * 1073741824;
        $detail += ['payment_id' => 42, 'balance_amount_minor' => 15, 'discount_amount_minor' => 25];
        $this->http('/txapi/orders/'.$order->trade_no.'/detail', $token)->assertOk()->assertJsonPath('data', $detail);
        $this->invoke('commerce.orders.get', $token, ['trade_no' => $order->trade_no])->assertOk()
            ->assertJsonPath('data', $this->decimalIdentifiers($summary));
        foreach ([$order->trade_no, 'missing-order'] as $tradeNo) {
            $this->http('/txapi/orders/'.$tradeNo, $otherToken)->assertNotFound();
            $this->http('/txapi/orders/'.$tradeNo.'/detail', $otherToken)->assertNotFound();
            $this->invoke('commerce.orders.get', $otherToken, ['trade_no' => $tradeNo])->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $orphan = $this->order($owner, ['plan_id' => 99999999]);
        $this->assertNull(Plan::query()->find($orphan->plan_id));
        $this->http('/txapi/orders/'.$orphan->trade_no, $token)->assertOk()->assertJsonPath('data.plan', null);
        $this->invoke('commerce.orders.get', $token, ['trade_no' => $orphan->trade_no])->assertOk()
            ->assertJsonPath('data.plan', null);
        $this->http('/txapi/orders/'.$orphan->trade_no.'/detail', $token)->assertNotFound();
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_all_five_views_use_the_port_and_internal_reads_never_resolve_a_controller(): void
    {
        $user = $this->user();
        $token = $user->createToken('port-owner', ['*'])->plainTextToken;
        $summary = ['id' => 91, 'trade_no' => 'port-order', 'plan_id' => 8, 'period' => 'monthly',
            'type' => 1, 'status' => 3, 'amount_minor' => 1234, 'plan' => ['id' => 8, 'name' => 'Port plan'],
            'paid_at' => null, 'created_at' => '2023-11-14T22:13:20+00:00'];
        $detail = $summary + ['payment_id' => null, 'balance_amount_minor' => 0, 'discount_amount_minor' => 0];
        $detail['plan']['traffic_limit_bytes'] = 1073741824;
        $meta = ['page' => 2, 'per_page' => 3, 'total' => 7, 'last_page' => 3];
        $queries = Mockery::mock(OrderQueries::class);
        $queries->shouldReceive('listing')->twice()->with((int) $user->id, Mockery::on(static fn ($query): bool =>
            $query instanceof OrderListQuery && $query->page === 2 && $query->perPage === 3 && $query->status === 0))
            ->andReturn(new OrderPage([$summary], $meta));
        $queries->shouldReceive('find')->twice()->with((int) $user->id, 'port-order')->andReturn($summary);
        $queries->shouldReceive('detail')->once()->with((int) $user->id, 'port-order')->andReturn($detail);
        $this->app->instance(OrderQueries::class, $queries);
        $this->http('/txapi/orders?page=2&per_page=3&status=0&user_id=999999', $token)->assertOk()
            ->assertJsonPath('data', [$summary])->assertJsonPath('meta', $meta);
        $this->http('/txapi/orders/port-order?user_id=999999', $token)->assertOk()->assertJsonPath('data', $summary);
        $this->http('/txapi/orders/port-order/detail?user_id=999999', $token)->assertOk()->assertJsonPath('data', $detail);
        $this->app->bind(AccountController::class, static function () {
            throw new \LogicException('INTERNAL_ORDER_READ_MUST_NOT_RESOLVE_HTTP_CONTROLLER');
        });
        $this->invoke('commerce.orders.list', $token, ['page' => 2, 'per_page' => 3, 'status' => 0])->assertOk()
            ->assertJsonPath('data', $this->decimalIdentifiers([$summary]))->assertJsonPath('meta', $meta);
        $this->invoke('commerce.orders.get', $token, ['trade_no' => 'port-order'])->assertOk()
            ->assertJsonPath('data', $this->decimalIdentifiers($summary));
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_closed_input_service_and_actor_rejections_happen_before_queries(): void
    {
        $queries = Mockery::mock(OrderQueries::class);
        $queries->shouldNotReceive('listing');
        $queries->shouldNotReceive('find');
        $queries->shouldNotReceive('detail');
        $this->app->instance(OrderQueries::class, $queries);
        $user = $this->user();
        $token = $user->createToken('rejected-owner', ['*'])->plainTextToken;
        foreach (['commerce.orders.list' => [], 'commerce.orders.get' => ['trade_no' => 'order']] as $operation => $input) {
            foreach (['user_id' => (string) $user->id, 'owner_id' => '1', 'filter' => ['user_id' => 1],
                'authorized' => true, 'handler' => 'OrderReadOperations', 'class' => AccountController::class] as $key => $value) {
                $this->invoke($operation, $token, $input + [$key => $value])->assertStatus(422)
                    ->assertJsonPath('error.code', 'INVALID_REQUEST');
            }
            $this->invoke($operation, input: $input)->assertUnauthorized()->assertJsonPath('error.code', 'ACTOR_UNAUTHORIZED');
        }
        foreach ([['page' => 0], ['page' => 100001], ['per_page' => 101], ['status' => 5]] as $input) {
            $this->invoke('commerce.orders.list', $token, $input)->assertStatus(422);
        }
        $this->invoke('commerce.orders.get', $token, [])->assertStatus(422);
        $this->invoke('commerce.orders.list', $token, headers: ['X-Core-Service-Credential' => 'invalid'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
        $this->invoke('commerce.orders.list', $token, headers: ['X-Core-Service-Id' => ''])
            ->assertUnauthorized();
        $this->invoke('commerce.orders.list', $token, headers: ['X-Core-Tls-Certificate-Sha256' => str_repeat('b', 64)])
            ->assertUnauthorized();
        $this->invoke('commerce.orders.list', $token, domain: 'admin')->assertUnauthorized();
        $original = $this->service;
        $this->service = app(ServiceCredentials::class)->issue('limited-order-gateway', 'user', self::FINGERPRINT,
            ['system.capabilities.get'], time() + 3600);
        $this->invoke('commerce.orders.list', $token)->assertForbidden();
        $this->service = $original;
        app(ServiceCredentials::class)->rotate('order-read-gateway', self::FINGERPRINT, time() + 3600);
        $this->invoke('commerce.orders.list', $token)->assertUnauthorized();
        $this->service = app(ServiceCredentials::class)->rotate('order-read-gateway', self::FINGERPRINT, time() + 3600);
        app(ServiceCredentials::class)->revoke('order-read-gateway');
        $this->invoke('commerce.orders.list', $token)->assertUnauthorized();
        $this->service = app(ServiceCredentials::class)->issue('live-actor-order-gateway', 'user', self::FINGERPRINT,
            ['commerce.orders.list', 'commerce.orders.get'], time() + 3600);
        $user->forceFill(['banned' => true])->saveOrFail();
        $this->invoke('commerce.orders.list', $token)->assertForbidden();
        $user->forceFill(['banned' => false])->saveOrFail();
        $user->tokens()->delete();
        $this->invoke('commerce.orders.get', $token, ['trade_no' => 'order'])->assertUnauthorized();
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_internal_projection_redaction_metadata_limits_and_no_store_remain_enforced(): void
    {
        $user = $this->user();
        $token = $user->createToken('projection-owner', ['*'])->plainTextToken;
        $secret = 'PRIVATE_ORDER_READ_SECRET';
        $row = ['id' => 91, 'trade_no' => 'safe-order', 'plan_id' => 8, 'period' => 'monthly', 'type' => 1, 'amount_minor' => 1234, 'status' => 0,
            'plan' => ['id' => 8, 'name' => 'Display', 'token' => $secret, 'nested' => ['private_key' => $secret, 'count' => 2]],
            'paid_at' => null, 'created_at' => '2026-10-18T00:00:00Z',
            'payment_id' => 44, 'balance_amount_minor' => 10, 'password' => $secret, 'unexpected' => $secret];
        $meta = ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1];
        $queries = Mockery::mock(OrderQueries::class);
        $queries->shouldReceive('listing')->once()->andReturn(new OrderPage([$row], $meta + ['unexpected' => $secret]));
        $queries->shouldReceive('find')->once()->with((int) $user->id, 'safe-order')->andReturn($row);
        $large = $row;
        $large['plan']['name'] = str_repeat('x', 255);
        $queries->shouldReceive('find')->once()->with((int) $user->id, 'large-order')->andReturn($large);
        $this->app->instance(OrderQueries::class, $queries);
        $expected = ['id' => '91', 'trade_no' => 'safe-order', 'plan_id' => '8', 'period' => 'monthly', 'type' => 1, 'amount_minor' => '1234', 'status' => 0,
            'plan' => ['id' => '8', 'name' => 'Display'], 'paid_at' => null, 'created_at' => '2026-10-18T00:00:00Z'];
        $list = $this->invoke('commerce.orders.list', $token)->assertOk()
            ->assertJsonPath('data', [$expected])->assertJsonPath('meta', $meta);
        $get = $this->invoke('commerce.orders.get', $token, ['trade_no' => 'safe-order'])->assertOk()
            ->assertJsonPath('data', $expected);
        foreach ([$list, $get] as $response) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        config(['internal_api.max_response_bytes' => 256]);
        $this->invoke('commerce.orders.get', $token, ['trade_no' => 'large-order'])->assertStatus(503)
            ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        $this->assertStringNotContainsString($secret, json_encode(DB::table('tx_internal_audit')->get()));
    }

    public function test_query_failures_keep_safe_dependency_errors_and_no_write_receipts(): void
    {
        $user = $this->user();
        $token = $user->createToken('dependency-owner', ['*'])->plainTextToken;
        $secret = 'PRIVATE_ORDER_QUERY_EXCEPTION';
        $queries = Mockery::mock(OrderQueries::class);
        $queries->shouldReceive('listing')->once()->andThrow(new \RuntimeException($secret));
        $queries->shouldReceive('find')->once()->with((int) $user->id, 'order')->andThrow(new \RuntimeException($secret));
        $this->app->instance(OrderQueries::class, $queries);
        foreach (['commerce.orders.list' => [], 'commerce.orders.get' => ['trade_no' => 'order']] as $operation => $input) {
            $response = $this->invoke($operation, $token, $input)->assertStatus(503)
                ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        $audit = DB::table('tx_internal_audit')->orderBy('id')->get();
        $this->assertSame(['DEPENDENCY_UNAVAILABLE', 'DEPENDENCY_UNAVAILABLE'], $audit->pluck('error_code')->all());
        $this->assertStringNotContainsString($secret, $audit->toJson());
        $this->assertStringNotContainsString($token, $audit->toJson());
    }

    private function invoke(string $operation, ?string $token = null, array $input = [], array $headers = [], string $domain = 'user')
    {
        $headers = array_replace([
            'X-Core-Service-Id' => $this->service['service_id'], 'X-Core-Service-Credential' => $this->service['credential'],
            'X-Core-Service-Epoch' => $this->service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
            'X-Core-Tls-Certificate-Sha256' => self::FINGERPRINT,
            'Authorization' => $token === null ? '' : 'Bearer '.$token,
        ], $headers);
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
            ->postJson('https://localhost/txapi/internal/native/v1/'.$domain.'/operations/'.$operation, [
                'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                'input' => $input === [] ? (object) [] : $input,
            ], $headers);
    }

    private function http(string $path, string $token)
    {
        Auth::forgetGuards();
        return $this->getJson($path, ['Authorization' => 'Bearer '.$token]);
    }

    private function user(): User
    {
        return User::withoutEvents(function (): User {
            $user = new User;
            $user->forceFill(['email' => Str::uuid().'@example.test', 'password' => Hash::make('OrderRead2026'),
                'token' => Str::random(32), 'uuid' => (string) Str::uuid(), 'is_admin' => false, 'banned' => false])->saveOrFail();
            return $user;
        });
    }

    private function order(User $owner, array $overrides = []): Order
    {
        // Force all fixture columns after reduced-schema tests may populate Eloquent's static column cache.
        return Order::withoutEvents(function () use ($owner, $overrides): Order {
            $order = new Order;
            $order->forceFill(array_replace(['user_id' => (int) $owner->id, 'plan_id' => (int) Plan::query()->value('id'),
                'trade_no' => 'read_'.bin2hex(random_bytes(12)), 'period' => 'monthly', 'type' => 1, 'status' => 0,
                'total_amount' => 1234, 'paid_at' => null, 'created_at' => 1700000000, 'updated_at' => 1700000000], $overrides))
                ->saveOrFail();
            return $order;
        });
    }

    private function summary(Order $order): array
    {
        $plan = Plan::query()->find($order->plan_id);
        return ['id' => (int) $order->id, 'trade_no' => $order->trade_no, 'plan_id' => (int) $order->plan_id,
            'period' => $order->period, 'type' => (int) $order->type, 'status' => (int) $order->status,
            'amount_minor' => (int) $order->total_amount,
            'plan' => $plan === null ? null : ['id' => (int) $plan->id, 'name' => $plan->name],
            'paid_at' => $order->paid_at ? gmdate('Y-m-d\TH:i:s+00:00', (int) $order->paid_at) : null,
            'created_at' => '2023-11-14T22:13:20+00:00'];
    }

    private function decimalIdentifiers(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) $value = $this->decimalIdentifiers($value);
            elseif (is_int($value) && is_string($key)
                && ($key === 'id' || preg_match('/(?:_id|_bytes|_minor)\z/D', $key))) $value = (string) $value;
        }
        unset($value);
        return $data;
    }
}
