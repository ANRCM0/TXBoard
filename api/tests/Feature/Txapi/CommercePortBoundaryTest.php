<?php

namespace Tests\Feature\Txapi;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\Subscription\Contracts\PurchaseResult;
use App\Domains\Subscription\Contracts\Purchases;
use App\Http\Controllers\Txapi\BillingController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\NativeRuntimeTestCase;

final class CommercePortBoundaryTest extends NativeRuntimeTestCase
{
    public function test_http_and_internal_purchase_share_the_typed_port_and_original_actor_without_controller_delegation(): void
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        $user = User::withoutEvents(fn () => User::query()->create([
            'email' => Str::uuid().'@example.test', 'password' => Hash::make('CommercePort2026'),
            'token' => '', 'uuid' => '', 'banned' => false, 'is_admin' => false,
        ]));
        $token = $user->createToken('purchase-owner', ['*'])->plainTextToken;
        $key = (string) Str::uuid();
        $port = Mockery::mock(Purchases::class);
        $port->shouldReceive('purchase')->twice()->with((int) $user->id, 9007199254740993, $key)
            ->andReturnUsing(function () use ($user): PurchaseResult {
                $invocation = app(CurrentInvocation::class)->current();
                $this->assertSame((string) $user->id, $invocation->actor->id);
                $this->assertSame('user', $invocation->actor->kind);
                $this->assertSame('2', $invocation->authorityEpoch);
                return new PurchaseResult('81', 'port-purchase', 0, '1200', 'CNY', 'tx-core.purchase-terms/v2');
            });
        $this->app->instance(Purchases::class, $port);
        Auth::forgetGuards();
        $this->postJson('/txapi/orders', ['offer_id' => '9007199254740993'], [
            'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key,
        ])->assertCreated()->assertJsonPath('data.trade_no', 'port-purchase');
        $this->assertNull(app(CurrentInvocation::class)->current());
        $this->app->bind(BillingController::class, static function () {
            throw new \LogicException('INTERNAL_PURCHASE_MUST_NOT_RESOLVE_HTTP_CONTROLLER');
        });
        $service = app(ServiceCredentials::class)->issue('commerce-port', 'user', str_repeat('a', 64),
            ['commerce.orders.create'], time() + 3600);
        $headers = [
            'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key,
            'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
            'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
            'X-Core-Tls-Certificate-Sha256' => str_repeat('a', 64),
        ];
        $invoke = fn (array $input) => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
            ->postJson('https://localhost/txapi/internal/native/v1/user/operations/commerce.orders.create', [
                'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                'input' => $input,
            ], $headers);
        $invoke(['offer_id' => '9007199254740993'])->assertCreated()->assertJsonPath('data.id', '81');
        $invoke(['offer_id' => '9007199254740993'])->assertCreated()->assertJsonPath('data.currency', 'CNY');
        $invoke(['offer_id' => '9007199254740993', 'user_id' => '1'])->assertStatus(422);
        $this->assertSame(1, DB::table('tx_internal_request')->count());
        $this->assertSame('completed', DB::table('tx_internal_request')->value('state'));
        $this->assertNull(app(CurrentInvocation::class)->current());
    }
}
