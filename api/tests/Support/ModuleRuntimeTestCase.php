<?php

namespace Tests\Support;

use App\Core\Host\Modules\ModulePackageLock;
use App\Core\Host\Modules\ModuleRuntime;
use App\Core\Host\Modules\ModuleDeployment;
use App\Domains\Billing\NativePaymentCheckout;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Payment;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Real fixed composition and production migrations; external Provider wire is the base fixture's only replacement. */
abstract class ModuleRuntimeTestCase extends NativeRuntimeTestCase
{
    protected string $publicationCurrency = 'CNY';
    protected array $planPrices = ['monthly' => '12.34', 'onetime' => '0.00'];

    protected function setUp(): void
    {
        parent::setUp();
        $packages = ModulePackageLock::fromFile(base_path(), base_path('bootstrap/module-packages.proof.lock.json'));
        $runtime = new ModuleRuntime;
        $providers = [new \App\Modules\PlatformModule, new \App\Modules\InstallationModule, new \App\Modules\ProviderModule,
            new \App\Modules\IdentityModule, new \App\Modules\SubscriptionModule, new \App\Modules\BillingModule,
            new \App\Modules\UsageModule, new \App\Modules\NetworkModule, new \App\Modules\NotificationsModule];
        // Domain bindings are the application's real bindings; proof packages add registries, not alternate owners.
        $runtime->register(new Container, array_merge($providers, $packages->providers()), $packages->unavailable());
        $this->app->instance(ModulePackageLock::class, $packages);
        $this->app->instance(ModuleRuntime::class, $runtime);
        $this->assertSame([], $runtime->plan()->unavailable);
        config(['app.url' => 'https://core.example.test',
            'core_modules.secrets' => ['receiver' => 'original-receiver-secret'],
            'core_modules.outbound_hosts' => ['delivery_receipt' => ['receiver.example.test', 'changed.example.test']]]);
    }

    protected function deploy(string $code, string $action, ?array $configuration = null, ?string $key = null): array
    {
        return app(ModuleDeployment::class)->execute($code, $action, $key ?? (string) Str::uuid(), $configuration);
    }

    protected function enableModule(string $code, ?array $configuration = null): void
    {
        $this->deploy($code, 'install');
        if ($configuration !== null) $this->deploy($code, 'configure', $configuration);
        $this->deploy($code, 'enable');
    }

    protected function receiverConfiguration(string $endpoint = 'https://receiver.example.test/events'): array
    {
        return ['endpoint' => $endpoint, 'token' => 'secret:receiver'];
    }

    protected function actor(bool $admin = false, array $permissions = []): array
    {
        [, $user] = app(NativeIdentityService::class)->create(Str::uuid().'@example.test', 'module-runtime-password');
        if ($admin) User::withoutEvents(fn () => $user->update(['is_admin' => true]));
        foreach ($permissions as $permission) DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $user->id, 'permission' => $permission, 'created_at' => time()]);
        $token = $user->createToken('module-test', $permissions === [] ? ['*'] : $permissions)->plainTextToken;
        return [$user, ['Authorization' => 'Bearer '.$token]];
    }

    protected function payment(): Payment
    {
        return Payment::query()->create(['uuid' => bin2hex(random_bytes(16)), 'payment' => 'EPay', 'name' => 'Epay proof',
            'config' => ['url' => 'https://epay.example.test/gateway', 'pid' => '42', 'key' => 'original-merchant-secret', 'type' => 'alipay'],
            'enable' => true]);
    }

    protected function settle(?User $user = null): array
    {
        if ($user === null) [$user] = $this->actor();
        $payment = $this->payment();
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], (string) Str::uuid());
        $started = app(NativePaymentCheckout::class)->start($user->id, $order->id, $payment->id, (string) Str::uuid());
        $params = $this->notification($started['attempt']['attempt_id']);
        $result = app(NativePaymentCheckout::class)->notify('EPay', $payment->uuid, $params);
        return [$user, $order, $payment, $started, $params, $result];
    }

    protected function notification(int|string $attemptId): array
    {
        $ciphertext = DB::table('tx_payment_dispatch')->where('attempt_id', $attemptId)->value('snapshot_ciphertext');
        $snapshot = json_decode(Crypt::decryptString($ciphertext), true, 16, JSON_THROW_ON_ERROR);
        $params = ['pid' => $snapshot['pid'], 'out_trade_no' => $snapshot['provider_order_ref'],
            'trade_no' => 'module-channel-'.$attemptId, 'money' => app(\Plugin\Epay\NativeAdapter::class)->decimal($snapshot['amount_minor']),
            'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'MD5'];
        $signed = $params;
        unset($signed['sign_type']);
        ksort($signed, SORT_STRING);
        return $params + ['sign' => md5(stripslashes(urldecode(http_build_query($signed))).'original-merchant-secret')];
    }
}
