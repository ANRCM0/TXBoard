<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeMoneyLedger;
use App\Domains\Billing\NativeOrderAdministration;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\NativeWalletReservations;
use App\Domains\Billing\MoneyValue;
use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\NativeRuntimeTestCase;

/** Deferred acceptance: real native migrations, sessions, reservations and original order recovery. */
final class NativeOrderAdministrationTest extends NativeRuntimeTestCase
{
    protected array $planPrices = ['monthly' => '0.00', 'onetime' => '1.00'];
    protected string $publicationCurrency = 'CNY';
    private NativeOrderAdministration $commands;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp(); $this->activate();
        $this->commands = app(NativeOrderAdministration::class);
        $this->admin = $this->actor(); $this->admin->forceFill(['is_admin' => true])->saveOrFail();
        foreach (['commerce.orders.read', 'commerce.orders.manage'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $this->admin->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $this->authenticateAdministrator();
    }

    public function test_stale_close_replay_releases_only_the_original_wallet_reservation_once(): void
    {
        [$user, $order] = $this->order('onetime'); $this->reserve($user, $order);
        $key = $this->key(); $input = $this->input($order);
        $first = $this->commands->execute($this->admin->id, 'close', $input, $key);
        $this->assertSame(2, $first['status']);
        $this->assertSame($first, $this->commands->execute($this->admin->id, 'close', array_reverse($input, true), $key));
        $this->assertSame('100', $this->balance($user));
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'wallet_release')->count());
        $this->assertSame(1, DB::table('tx_native_order_admin_request')->count());
        $this->assertSame(['created', 'cancelled'], DB::table('tx_native_order_event')->where('order_id', $order->id)->orderBy('sequence')->pluck('type')->all());
        $this->rejects('ADMIN_ORDER_STATUS_CONFLICT', fn () => $this->commands->execute($this->admin->id, 'close', $input, $this->key()));
        $input['reason'] = 'changed';
        $this->rejects('ADMIN_ORDER_IDEMPOTENCY_CONFLICT', fn () => $this->commands->execute($this->admin->id, 'close', $input, $key));
        Bus::assertNothingDispatched();
    }

    public function test_unconfirmed_channel_attempt_blocks_close_until_the_owner_records_failure(): void
    {
        [$user, $order] = $this->order('onetime');
        $payment = Payment::query()->create(['uuid' => bin2hex(random_bytes(16)), 'payment' => 'native-test',
            'name' => 'Deferred test adapter', 'config' => [], 'enable' => true]);
        $attempts = app(NativePaymentAttempts::class);
        $attempt = $attempts->prepare($user->id, $order->id, $payment->id, 'test-merchant', $this->key());
        $key = $this->key(); $input = $this->input($order);
        $this->rejects('ADMIN_ORDER_PAYMENT_UNCONFIRMED', fn () => $this->commands->execute($this->admin->id, 'close', $input, $key));
        $attempts->markUnknown($user->id, (int) $attempt['attempt_id']);
        $this->rejects('ADMIN_ORDER_PAYMENT_UNCONFIRMED', fn () => $this->commands->execute($this->admin->id, 'close', $input, $key));
        $this->assertSame(0, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('tx_native_order_admin_request')->count());
        $attempts->markFailed($user->id, (int) $attempt['attempt_id']);
        $this->assertSame(2, $this->commands->execute($this->admin->id, 'close', $input, $key)['status']);
    }

    public function test_receipt_insert_failure_rolls_back_cancellation_wallet_release_and_events(): void
    {
        [$user, $order] = $this->order('onetime'); $this->reserve($user, $order);
        $key = $this->key(); $fail = true; $beforeEvents = DB::table('tx_module_event')->count();
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && preg_match('/insert into ["`]tx_native_order_admin_request["`]/i', $sql)) {
                $fail = false; throw new \RuntimeException('INJECTED_ORDER_ADMIN_RECEIPT_FAILURE');
            }
        });
        try { $this->commands->execute($this->admin->id, 'close', $this->input($order), $key); $this->fail('Expected rollback'); }
        catch (\RuntimeException $error) { $this->assertSame('INJECTED_ORDER_ADMIN_RECEIPT_FAILURE', $error->getMessage()); }
        $this->assertSame(0, (int) $order->fresh()->status); $this->assertSame('40', $this->balance($user));
        $this->assertSame(0, DB::table('tx_money_journal')->where('business_type', 'wallet_release')->count());
        $this->assertSame(1, DB::table('tx_native_order_event')->where('order_id', $order->id)->count());
        $this->assertSame($beforeEvents, DB::table('tx_module_event')->count());
        $this->assertSame(2, $this->commands->execute($this->admin->id, 'close', $this->input($order), $key)['status']);
    }

    public function test_retry_survives_lost_redis_wakeup_and_recovers_the_same_order_once(): void
    {
        [, $order] = $this->order();
        $this->assertTrue((new NativeOrderLifecycle($order))->paid($order->trade_no));
        $key = $this->key(); $input = $this->input($order, 1); $dispatcher = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue response lost'));
        $accepted = $this->commands->execute($this->admin->id, 'retry', $input, $key);
        $this->assertSame(1, $accepted['status']);
        $this->assertSame(1, DB::table('tx_native_order_admin_request')->count());
        $this->assertSame(0, DB::table('tx_subscription_operation')->count());
        Bus::swap($dispatcher); Bus::fake();
        $this->artisan('check:order', ['--limit' => 100])->assertSuccessful();
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        $job = Bus::dispatched(OrderHandleJob::class)->first(); $job->handle(); $job->handle();
        $this->assertSame(3, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $order->id)->count());
        $replay = $this->commands->execute($this->admin->id, 'retry', $input, $key);
        $this->assertSame(3, $replay['status']); $this->assertSame($accepted['request_id'], $replay['request_id']);
        $this->assertSame(1, DB::table('tx_native_order_admin_request')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
    }

    public function test_replay_rechecks_current_grants_token_and_account_before_releasing_any_result(): void
    {
        [, $order] = $this->order(); $key = $this->key(); $input = $this->input($order);
        $this->commands->execute($this->admin->id, 'close', $input, $key);
        DB::table('tx_internal_actor_grant')->where('user_id', $this->admin->id)->where('permission', 'commerce.orders.manage')->delete();
        $this->denied(fn () => $this->commands->execute($this->admin->id, 'close', $input, $key));
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $this->admin->id, 'permission' => 'commerce.orders.manage', 'created_at' => time()]);
        DB::table('tx_user')->where('id', $this->admin->id)->update(['banned' => true]);
        $this->denied(fn () => $this->commands->execute($this->admin->id, 'close', $input, $key));
        DB::table('tx_user')->where('id', $this->admin->id)->update(['banned' => false]);
        DB::table('personal_access_tokens')->where('tokenable_id', $this->admin->id)->delete();
        try { $this->commands->execute($this->admin->id, 'close', $input, $key); $this->fail('Revoked token accepted'); }
        catch (AuthenticationException) { $this->assertTrue(true); }
        $this->assertSame(1, DB::table('tx_native_order_admin_request')->count());
    }

    public function test_cursor_audit_is_scoped_and_detects_resealed_input_tampering(): void
    {
        [, $order] = $this->order(); (new NativeOrderLifecycle($order))->paid($order->trade_no);
        $this->commands->execute($this->admin->id, 'retry', $this->input($order, 1), $this->key());
        $this->commands->execute($this->admin->id, 'retry', $this->input($order, 1), $this->key());
        $first = $this->commands->audit($this->admin->id, $order->trade_no, '0', 1);
        $this->assertTrue($first->meta['has_more']);
        $next = $this->commands->audit($this->admin->id, $order->trade_no, $first->meta['next_after_id'], 1);
        $this->assertFalse($next->meta['has_more']);
        $this->assertNotSame($first->items[0]['id'], $next->items[0]['id']);
        $this->assertNull($this->commands->audit($this->admin->id, 'missing-order', '0', 1));
        $row = DB::table('tx_native_order_admin_request')->where('id', $first->items[0]['id'])->first();
        $payload = json_decode($row->payload_json, true); $payload['reason'] = 'tampered';
        DB::table('tx_native_order_admin_request')->where('id', $row->id)->update([
            'payload_json' => json_encode($payload), 'content_hash' => MoneyValue::hash($payload)]);
        $this->expectExceptionMessage('ADMIN_ORDER_RECEIPT_INVALID');
        $this->commands->audit($this->admin->id, $order->trade_no, '0', 20);
    }

    public function test_internal_transport_recovers_a_committed_but_unrecorded_response_with_original_key(): void
    {
        [, $order] = $this->order(); $input = $this->input($order); $key = $this->key();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'order_admin']);
        $token = $this->admin->createToken('order-admin-http', ['*'])->plainTextToken;
        $fingerprint = str_repeat('c', 64);
        $service = app(ServiceCredentials::class)->issue('admin-order-test', 'admin', $fingerprint,
            ['commerce.admin-orders.close', 'commerce.admin-orders.audit.list'], time() + 3600);
        $invoke = function (string $operation, array $data) use ($token, $fingerprint, $service, $key) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$operation, [
                    'contract_version' => '1', 'request_id' => $this->key(), 'deadline_at' => time() + 15, 'input' => $data],
                    ['X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Authorization' => 'Bearer '.$token,
                    'X-Core-Admin-Path' => 'order_admin', 'Idempotency-Key' => $key]);
        };
        $first = $invoke('commerce.admin-orders.close', $input);
        $this->assertSame(200, $first->status(), $first->getContent());
        $first->assertJsonPath('data.status', 2);
        DB::table('tx_internal_request')->where('actor_id', $this->admin->id)->update([
            'state' => 'started', 'response_json' => null, 'response_status' => null]);
        $invoke('commerce.admin-orders.close', $input)->assertOk()->assertJsonPath('data.request_id', $first->json('data.request_id'));
        $invoke('commerce.admin-orders.audit.list', ['trade_no' => $order->trade_no])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.actor_id', (string) $this->admin->id);
        DB::table('tx_internal_actor_grant')->where('user_id', $this->admin->id)->where('permission', 'commerce.orders.manage')->delete();
        $invoke('commerce.admin-orders.close', $input)->assertForbidden();
        $this->assertSame(1, DB::table('tx_native_order_admin_request')->count());
    }

    public function test_populated_command_journal_cannot_be_dropped_by_migration_rollback(): void
    {
        [, $order] = $this->order();
        $this->commands->execute($this->admin->id, 'close', $this->input($order), $this->key());
        $migration = require database_path('migrations/2026_10_19_000010_native_order_administration.php');
        try { $migration->down(); $this->fail('Durable command journal was dropped'); }
        catch (\RuntimeException $error) {
            $this->assertSame('NATIVE_ORDER_ADMIN_FORWARD_RECOVERY_REQUIRED', $error->getMessage());
        }
        $this->assertSame(1, DB::table('tx_native_order_admin_request')->count());
    }

    private function actor(): User
    {
        [, $user] = app(NativeIdentityService::class)->create($this->key().'@example.test', 'runtime-password');
        return $user;
    }
    private function authenticateAdministrator(): void
    {
        $token = $this->admin->createToken('admin-order', ['*']); Auth::forgetGuards();
        Auth::guard('sanctum')->setUser($this->admin->withAccessToken($token->accessToken));
    }
    private function order(string $period = 'monthly'): array
    {
        $user = $this->actor(); $offer = collect($this->offers)->firstWhere('offer_code', $period);
        return [$user, app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], $this->key())];
    }
    private function reserve(User $user, Order $order): void
    {
        app(NativeMoneyLedger::class)->post($user->id, ['business_type' => 'wallet_credit', 'business_ref' => 'order-admin-fixture',
            'idempotency_key' => $this->key(), 'currency' => 'CNY', 'occurred_at' => (string) time(), 'postings' => [
                ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => '100'],
                ['owner_id' => (string) $user->id, 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => '100']]]);
        app(PublishedOrderService::class)->transitionOrder($order->id, function ($locked, $purchase, $installation) use ($user): void {
            app(NativeWalletReservations::class)->reserveLocked($installation, $user->id, $locked, $purchase, '60', $this->key());
        });
    }
    private function balance(User $user): string { return (string) DB::table('tx_money_account')->where('owner_id', $user->id)->where('purpose', 'wallet_available')->value('balance_minor'); }
    private function input(Order $order, int $status = 0): array { return ['trade_no' => $order->trade_no, 'expected_status' => $status, 'reason' => 'admin_reviewed']; }
    private function key(): string { return (string) Str::uuid(); }
    private function rejects(string $code, \Closure $run): void
    {
        try { $run(); $this->fail('Expected '.$code); }
        catch (BillingError $error) { $this->assertSame($code, $error->errorCode); }
    }
    private function denied(\Closure $run): void
    {
        try { $run(); $this->fail('Expected current authorization rejection'); }
        catch (HttpExceptionInterface $error) { $this->assertSame(403, $error->getStatusCode()); }
    }
}
