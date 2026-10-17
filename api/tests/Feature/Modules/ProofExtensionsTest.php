<?php

namespace Tests\Feature\Modules;

use App\Core\Host\Modules\ModuleDeliveryWorker;
use App\Core\Host\Modules\ModuleDeployment;
use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Billing\NativePaymentCheckout;
use App\Domains\Subscription\Contracts\PurchaseResult;
use App\Domains\Subscription\Contracts\Purchases;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Extensions\Contracts\ExtensionFailure;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ModuleRuntimeTestCase;

final class ProofExtensionsTest extends ModuleRuntimeTestCase
{
    public function test_pending_payment_query_uses_original_channel_snapshot_after_generic_disable(): void
    {
        $this->enableModule('epay');
        $this->activate();
        [$user] = $this->actor();
        $payment = $this->payment();
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], (string) Str::uuid());
        $checkout = app(NativePaymentCheckout::class);
        $started = $checkout->start($user->id, $order->id, $payment->id, (string) Str::uuid());
        $attemptId = (int) $started['attempt']['attempt_id'];
        $params = $this->notification($attemptId);
        $this->deploy('epay', 'disable');
        $payment->update(['enable' => false, 'config' => ['url' => 'https://changed.example.test', 'pid' => '9', 'key' => 'changed']]);
        Http::fake(function ($request) use ($params) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('https://epay.example.test/gateway/api.php', $request->url());
            $this->assertSame('original-merchant-secret', $request['key']);
            $this->assertSame($params['out_trade_no'], $request['out_trade_no']);
            return Http::response(['code' => 1, 'status' => 1, 'pid' => '42', 'out_trade_no' => $params['out_trade_no'],
                'trade_no' => $params['trade_no'], 'money' => '12.34']);
        });
        $this->assertSame('succeeded', $checkout->recover($user->id, $attemptId)['status']);
        $checkout->notify('EPay', $payment->uuid, $params);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
    }

    public function test_payment_connector_uses_standard_deployment_and_preserves_verified_history_when_disabled(): void
    {
        $this->enableModule('epay');
        $this->activate();
        [$user, $order, $payment, $started, $params] = $this->settle();
        $this->assertSame('succeeded', DB::table('tx_payment_attempt')->value('status'));
        $this->deploy('epay', 'disable');
        $payment->update(['enable' => false, 'config' => ['url' => 'https://changed.example.test', 'pid' => 'different', 'key' => 'changed']]);
        $result = app(NativePaymentCheckout::class)->notify('EPay', $payment->uuid, $params);
        $this->assertSame('1234', $result['amount_minor']);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->expectException(ExtensionFailure::class);
        $this->expectExceptionMessage('MODULE_DRAIN_REQUIRED');
        $this->deploy('epay', 'uninstall');
    }

    public function test_notification_failure_keeps_verified_money_and_retries_original_event_and_secret_after_configuration_changes(): void
    {
        $this->enableModule('epay');
        $this->enableModule('delivery_receipt', $this->receiverConfiguration());
        $this->activate();
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('worker', app(CurrentInvocation::class)->current()->source);
            $this->assertSame('system', app(CurrentInvocation::class)->current()->actor->kind);
            $this->assertSame('https://receiver.example.test/events', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer original-receiver-secret'));
            $sent[] = $request->data();
            if (count($sent) === 1) throw new ConnectionException('simulated lost acknowledgement');
            return Http::response(['status' => 'accepted', 'event_id' => $request['event_id']]);
        });
        [, , $payment, , $params] = $this->settle();
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $event = DB::table('tx_module_event')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('merchant-secret', $event->body);
        $this->assertStringNotContainsString('receiver-secret', DB::table('tx_module_delivery')->value('configuration_ciphertext'));
        app(NativePaymentCheckout::class)->notify('EPay', $payment->uuid, $params);
        $this->assertSame(1, DB::table('tx_module_event')->count());
        $this->assertSame(1, DB::table('tx_module_delivery')->count());
        $worker = app(ModuleDeliveryWorker::class);
        $this->assertSame(1, $worker->run(10)['unknown']);
        $this->assertNull(app(CurrentInvocation::class)->current());
        $this->assertSame('unknown', DB::table('tx_module_delivery')->value('status'));
        config(['core_modules.secrets.receiver' => 'rotated-receiver-secret']);
        $this->deploy('delivery_receipt', 'configure', $this->receiverConfiguration('https://changed.example.test/events'));
        DB::table('tx_module_delivery')->update(['next_attempt_at' => 0]);
        $this->deploy('delivery_receipt', 'disable');
        $this->assertSame(1, $worker->run(10)['paused']);
        try { $this->deploy('delivery_receipt', 'uninstall'); $this->fail('Expected retained delivery reference'); }
        catch (ExtensionFailure $error) { $this->assertSame('MODULE_DRAIN_REQUIRED', $error->errorCode); }
        $this->deploy('delivery_receipt', 'enable');
        $this->assertSame(1, $worker->run(10)['accepted']);
        $this->assertCount(2, $sent);
        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame($event->event_key, $sent[0]['event_id']);
        $this->assertSame(1, DB::table('tx_ext_delivery_receipt')->count());
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
    }

    public function test_independent_business_unknown_purchase_recovers_original_owner_key_after_disable(): void
    {
        $this->enableModule('offer_reservation');
        $this->activate();
        [$user, $headers] = $this->actor();
        $owner = app(Purchases::class);
        $port = new class($owner) implements Purchases {
            public int $calls = 0;
            public array $keys = [];
            public function __construct(private readonly Purchases $owner) {}
            public function purchase(int $userId, int $offerId, string $key): PurchaseResult
            {
                if (DB::transactionLevel() !== 0) throw new \LogicException('Cross-owner transaction escaped');
                $this->keys[] = $key;
                $result = $this->owner->purchase($userId, $offerId, $key);
                if (++$this->calls === 1) throw new \RuntimeException('Simulated committed purchase response loss');
                return $result;
            }
        };
        $this->app->instance(Purchases::class, $port);
        $key = (string) Str::uuid();
        $headers['Idempotency-Key'] = $key;
        $base = '/txapi/extensions/offer_reservation/operations/';
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $response = $this->postJson($base.'reservation.create', ['input' => [
            'offer_id' => $offer['offer_id'], 'pay_with_wallet' => false]], $headers)
            ->assertStatus(202)->assertJsonPath('data.status', 'unknown');
        $id = $response->json('data.reservation_id');
        $this->assertSame(1, DB::table('tx_order')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
        $this->deploy('offer_reservation', 'disable');
        try { app(ModuleDeployment::class)->assertDrained('offer_reservation'); $this->fail('Expected owner drain rejection'); }
        catch (ExtensionFailure $error) { $this->assertSame('MODULE_RESERVATION_DRAIN_REQUIRED', $error->errorCode); }
        $this->postJson($base.'reservation.create', ['input' => [
            'offer_id' => $offer['offer_id'], 'pay_with_wallet' => false]], ['Idempotency-Key' => (string) Str::uuid()] + $headers)->assertStatus(409);
        [, $otherHeaders] = $this->actor();
        Auth::forgetGuards();
        $this->postJson($base.'reservation.recover', ['input' => ['reservation_id' => $id]],
            ['Idempotency-Key' => (string) Str::uuid()] + $otherHeaders)->assertNotFound();
        Auth::forgetGuards();
        $this->postJson($base.'reservation.recover', ['input' => ['reservation_id' => $id]], $headers)
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame([$key, $key], $port->keys);
        $this->assertSame(1, DB::table('tx_order')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        $this->assertTrue(app(ModuleDeployment::class)->assertDrained('offer_reservation')['drained']);
        $this->assertNull(app(CurrentInvocation::class)->current());
    }

    public function test_presentation_requires_live_grant_for_resources_operations_configuration_and_disable(): void
    {
        $this->enableModule('module_portal', ['label' => 'Original portal']);
        $this->activate();
        admin_setting(['secure_path' => 'module-test-admin']);
        $base = '/txapi/admin/module-test-admin/extensions/module_portal/';
        [$user, $headers] = $this->actor(true, ['extensions.module_portal.view', 'extensions.module_portal.configure']);
        $this->getJson($base.'presentation', $headers)->assertOk()->assertJsonPath('data.entry', 'ui/index.html');
        $this->getJson($base.'presentation', ['X-Core-Module-Fingerprint' => str_repeat('0', 64)] + $headers)
            ->assertStatus(409)->assertJsonPath('error.code', 'MODULE_VERSION_CHANGED');
        $this->get($base.'resources/ui/index.html', $headers)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson($base.'resources/Plugin.php', $headers)->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_NATIVE_CAPABILITY_UNAVAILABLE');
        $this->get($base.'resources/ui/Plugin.php', $headers)->assertNotFound();
        $this->postJson($base.'operations/status.get', ['input' => (object) []], $headers)
            ->assertOk()->assertJsonPath('data.actor_id', (string) $user->id)->assertJsonPath('data.label', 'Original portal');
        $key = (string) Str::uuid();
        $this->postJson($base.'configuration', ['configuration' => ['label' => 'Configured portal']], ['Idempotency-Key' => $key] + $headers)->assertOk();
        $this->postJson($base.'configuration', ['configuration' => ['label' => 'Configured portal']], ['Idempotency-Key' => $key] + $headers)->assertOk();
        $this->assertSame(3, (int) DB::table('tx_plugins')->where('code', 'module_portal')->value('module_config_version'));
        DB::table('tx_internal_actor_grant')->where('user_id', $user->id)->where('permission', 'extensions.module_portal.view')->delete();
        $this->getJson($base.'presentation', $headers)->assertForbidden()->assertJsonPath('error.code', 'MODULE_PERMISSION_DENIED');
        $this->get($base.'resources/ui/index.html', $headers)->assertForbidden();
        $this->postJson($base.'operations/status.get', ['input' => (object) []], $headers)->assertForbidden();
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $user->id, 'permission' => 'extensions.module_portal.view', 'created_at' => time()]);
        $this->deploy('module_portal', 'disable');
        $this->getJson($base.'presentation', $headers)->assertStatus(409);
        $this->get($base.'resources/ui/index.html', $headers)->assertStatus(409);
        $this->postJson($base.'operations/status.get', ['input' => (object) []], $headers)->assertStatus(409);
    }
}
