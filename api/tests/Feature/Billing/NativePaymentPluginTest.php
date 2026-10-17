<?php

namespace Tests\Feature\Billing;

use App\Contracts\NativePaymentProviderInterface;
use App\Domains\Billing\NativePaymentCheckout;
use App\Domains\Billing\NativePaymentProviderRegistry;
use App\Models\Plugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginPackage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\NativePaymentTestCase;

final class NativePaymentPluginTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://core.example.test']);
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test', 'pid' => '42', 'key' => 'plugin-secret', 'type' => 'alipay',
        ]]);
        Http::preventStrayRequests();
        HookManager::reset();
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_installed_current_plugin_is_frozen_and_removed_payment_plugin_is_not_available(): void
    {
        $provider = app(NativePaymentProviderRegistry::class)->forPayment($this->payment);
        $this->assertSame('epay', $provider->pluginCode);
        $this->assertSame('1.0.0', $provider->pluginVersion);
        $this->assertSame('native-payment-provider/1', $provider->contractVersion);
        $this->assertSame('epay-md5-dispatch/1', $provider->snapshotSchema);
        $this->assertSame([], HookManager::filter('available_payment_methods', []));
        Plugin::query()->create(['code' => 'alipay_f2f', 'name' => 'Historical provider', 'type' => Plugin::TYPE_PAYMENT,
            'version' => '1.0.0', 'is_enabled' => true, 'config' => '{}', 'installed_at' => now()]);
        $this->rejects('NATIVE_PAYMENT_PROVIDER_UNSUPPORTED',
            fn () => app(PluginManager::class)->nativePaymentProvider('alipay_f2f'));
        // An unsupported optional plugin does not poison the unrelated bundled channel.
        $this->assertSame('epay', app(NativePaymentProviderRegistry::class)->forPayment($this->payment)->pluginCode);
        $this->assertSame(['EPay'], \App\Services\PaymentService::getAllPaymentMethodNames());
        [$user, $order] = $this->purchase();
        app(NativePaymentCheckout::class)->start($user->id, $order->id, $this->payment->id, 'typed-plugin');
        $dispatch = DB::table('tx_payment_dispatch')->first();
        $snapshot = $this->paymentSnapshot($dispatch->attempt_id);
        $this->assertSame('native-payment-dispatch/1', $snapshot['schema']);
        foreach (['plugin_code', 'plugin_version', 'contract_version', 'provider'] as $field) {
            $this->assertSame($snapshot[$field], $dispatch->$field);
        }
        $this->assertSame('epay-md5-dispatch/1', $snapshot['provider_snapshot_schema']);
        Http::assertNothingSent();
    }

    public function test_unsupported_refund_capabilities_cannot_advertise_or_resolve_frozen_dispatches(): void
    {
        [$user, $order] = $this->purchase();
        $started = app(NativePaymentCheckout::class)->start(
            $user->id, $order->id, $this->payment->id, 'unsupported-refund-capability');
        $snapshot = $this->paymentSnapshot($started['attempt']['attempt_id']);
        $supported = app(PluginManager::class)->nativePaymentProvider('epay');
        $identity = $supported['adapter']->identity();
        $capabilities = $supported['adapter']->capabilities();
        $this->assertTrue(app(PluginManager::class)->disable('epay'));
        // A disabled valid provider still resolves the original query without booting hooks.
        $this->assertSame('epay', app(NativePaymentProviderRegistry::class)->forFrozen($snapshot, 'query')->pluginCode);

        foreach (['external_refund', 'query_refund'] as $unsupported) {
            $adapter = \Mockery::mock(NativePaymentProviderInterface::class);
            $adapter->shouldReceive('identity')->twice()->andReturn($identity);
            $adapter->shouldReceive('capabilities')->twice()->andReturn(
                array_replace($capabilities, [$unsupported => true]));
            $registration = array_replace($supported, ['adapter' => $adapter]);
            $manager = \Mockery::mock(PluginManager::class);
            $manager->shouldReceive('nativePaymentProvider')->with('epay', false)->once()->andReturn($registration);
            $manager->shouldReceive('nativePaymentProvider')->with('epay', true)->once()->andReturn($registration);
            $registry = new NativePaymentProviderRegistry($manager);

            Plugin::query()->where('code', 'epay')->update(['is_enabled' => true]);
            $this->assertSame([], $registry->availableMethods());
            Plugin::query()->where('code', 'epay')->update(['is_enabled' => false]);
            $this->rejects('PAYMENT_PROVIDER_CAPABILITY_UNSUPPORTED',
                fn () => $registry->forFrozen($snapshot, 'query'));
        }

        $this->assertSame([], HookManager::filter('available_payment_methods', []));
        $this->assertSame(1, DB::table('tx_payment_attempt')->count());
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        Http::assertNothingSent();
    }

    public function test_legacy_hook_cannot_advertise_an_unimplemented_channel_or_execute_a_payment(): void
    {
        HookManager::registerFilter('available_payment_methods', static fn (array $methods): array =>
            $methods + ['LegacyPay' => ['plugin_code' => 'epay']], 20);
        $this->assertSame(['EPay'], \App\Services\PaymentService::getAllPaymentMethodNames());
        $service = new \App\Services\PaymentService('EPay', $this->payment->id);
        $this->assertSame('plugin-secret', $service->form()['key']['value']);
        foreach (['pay', 'notify'] as $entry) {
            try {
                $service->$entry([]);
                $this->fail('Direct payment execution must not bypass native dispatch.');
            } catch (\App\Exceptions\ApiException $error) {
                $this->assertSame(409, $error->getCode());
                $this->assertSame('Payment execution requires a native frozen dispatch', $error->getMessage());
            }
        }
        $this->assertSame(0, DB::table('tx_payment_attempt')->count());
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        Http::assertNothingSent();
    }

    public function test_manifest_protocol_binding_cannot_certify_a_different_loaded_provider(): void
    {
        $directory = sys_get_temp_dir().'/native-manifest-contract-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        try {
            $manifest = json_decode(File::get(base_path('plugins-core/Epay/config.json')), true, 16, JSON_THROW_ON_ERROR);
            $manifest['payment']['provider'] = 'OtherPay';
            File::put($directory.'/config.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            app(PluginManager::class)->nativePaymentProvider('epay');
            $manager = new class(app(PluginPackage::class), $directory) extends PluginManager {
                public function __construct(PluginPackage $package, private readonly string $fixturePath)
                {
                    parent::__construct($package);
                }
                public function getPluginPath(string $pluginCode): string { return $this->fixturePath; }
            };
            $this->rejects('PAYMENT_PROVIDER_CONTRACT_INVALID', fn () => $manager->nativePaymentProvider('epay'));
            unset($manifest['payment']);
            File::put($directory.'/config.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->rejects('NATIVE_PAYMENT_PROVIDER_UNSUPPORTED', fn () => $manager->nativePaymentProvider('epay'));
            Http::assertNothingSent();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_disabled_plugin_stops_new_checkout_and_url_reissue_but_retains_limited_frozen_recovery(): void
    {
        [$user, $order] = $this->purchase();
        $checkout = app(NativePaymentCheckout::class);
        $started = $checkout->start($user->id, $order->id, $this->payment->id, 'disabled-plugin');
        $snapshot = $this->paymentSnapshot($started['attempt']['attempt_id']);
        $this->assertTrue(app(PluginManager::class)->disable('epay'));
        $this->payment->update(['config' => ['url' => 'https://new.example.test', 'pid' => '9', 'key' => 'rotated']]);
        $this->rejects('PAYMENT_PROVIDER_UNAVAILABLE',
            fn () => $checkout->start($user->id, $order->id, $this->payment->id, 'disabled-plugin'));
        $this->rejects('NATIVE_PAYMENT_PROVIDER_UNSUPPORTED',
            fn () => $checkout->start($user->id, $order->id, $this->payment->id, 'new-disabled-plugin'));
        Http::fake(function ($request) use ($snapshot) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('plugin-secret', $request['key']);
            return Http::response(['code' => 1, 'pid' => '42', 'status' => '1',
                'out_trade_no' => $snapshot['provider_order_ref'], 'trade_no' => 'disabled-tx', 'money' => '12.34']);
        });
        $this->assertSame('succeeded', $checkout->query($user->id, (int) $snapshot['attempt_id'])['status']);
        $params = ['pid' => '42', 'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => $snapshot['provider_order_ref'],
            'trade_no' => 'disabled-tx', 'money' => '12.34', 'sign_type' => 'MD5'];
        $unsigned = $params;
        unset($unsigned['sign_type']);
        ksort($unsigned, SORT_STRING);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($unsigned))).'plugin-secret');
        $this->assertSame('success', $checkout->notify('EPay', $this->payment->uuid, $params)['acknowledgement']);
        $this->assertSame([], HookManager::filter('available_payment_methods', []));
        $this->assertSame(1, DB::table('tx_payment_attempt')->count());
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
    }

    public function test_exact_version_schema_and_encrypted_binding_cannot_be_substituted(): void
    {
        [$user, $order] = $this->purchase();
        $checkout = app(NativePaymentCheckout::class);
        $started = $checkout->start($user->id, $order->id, $this->payment->id, 'pinned-version');
        $snapshot = $this->paymentSnapshot($started['attempt']['attempt_id']);
        $registry = app(NativePaymentProviderRegistry::class);
        foreach (['plugin_version' => '2.0.0', 'contract_version' => 'native-payment-provider/2',
            'provider_snapshot_schema' => 'epay-md5-dispatch/2'] as $field => $value) {
            $this->rejects('PAYMENT_PROVIDER_VERSION_UNAVAILABLE',
                fn () => $registry->forFrozen(array_replace($snapshot, [$field => $value]), 'query'));
        }
        Plugin::query()->where('code', 'epay')->update(['version' => '2.0.0']);
        $this->rejects('PAYMENT_PROVIDER_VERSION_UNAVAILABLE',
            fn () => $checkout->query($user->id, (int) $snapshot['attempt_id']));
        Plugin::query()->where('code', 'epay')->update(['version' => '1.0.0']);
        DB::table('tx_payment_dispatch')->where('attempt_id', $snapshot['attempt_id'])->update(['plugin_version' => '2.0.0']);
        $this->rejects('NATIVE_PAYMENT_DISPATCH_EVIDENCE_INVALID',
            fn () => $checkout->status($user->id, (int) $snapshot['attempt_id']));
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        Http::assertNothingSent();
    }

    public function test_historical_dispatch_permanently_blocks_destructive_lifecycle_before_any_package_mutation(): void
    {
        [$user, $order] = $this->purchase();
        app(NativePaymentCheckout::class)->start($user->id, $order->id, $this->payment->id, 'permanent-pin');
        $manager = app(PluginManager::class);
        $this->rejectsModule('MODULE_DRAIN_REQUIRED', fn () => $manager->uninstall('epay'));
        $this->rejectsModule('MODULE_DRAIN_REQUIRED', fn () => $manager->update('epay'));
        $this->assertTrue(Plugin::query()->where('code', 'epay')->firstOrFail()->is_enabled);
        $this->assertNull(Plugin::query()->where('code', 'epay')->value('native_payment_mutation_token'));
        $this->assertFileExists(base_path('plugins-core/Epay/NativeAdapter.php'));

        Plugin::query()->create(['code' => 'native_pin_fixture', 'name' => 'Pinned package', 'type' => Plugin::TYPE_PAYMENT,
            'version' => '1.0.0', 'is_enabled' => false, 'config' => '{}', 'installed_at' => now()]);
        DB::table('tx_payment_dispatch')->update(['plugin_code' => 'native_pin_fixture']);
        if (!class_exists(\ZipArchive::class)) { return; }
        $archive = tempnam(sys_get_temp_dir(), 'native-pin-');
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
            $zip->addFromString('config.json', json_encode(['name' => 'Pinned package', 'code' => 'native_pin_fixture',
                'package' => ['schema' => 1],
                'payment' => ['contract' => 'native-payment-provider/1', 'provider' => 'EPay',
                    'snapshot_schema' => 'epay-md5-dispatch/1'],
                'version' => '1.1.0', 'description' => 'Pinned package', 'author' => 'Tests', 'type' => 'payment',
                'require' => []], JSON_THROW_ON_ERROR));
            $zip->addFromString('Plugin.php', '<?php // Lifecycle must reject before loading this package.');
            $zip->close();
            $target = $manager->getUserPluginPath('native_pin_fixture');
            $this->assertFalse(File::exists($target));
            $this->rejects('PAYMENT_PLUGIN_DRAIN_REQUIRED',
                fn () => $manager->upload(new UploadedFile($archive, 'plugin.zip', 'application/zip', null, true)));
            $this->assertFalse(File::exists($target));
            $this->assertSame([], File::glob($target.'.staging-*'));
            $this->assertSame([], File::glob($target.'.backup-*'));
        } finally {
            @unlink($archive);
        }
    }

    public function test_matching_new_manifest_and_database_do_not_certify_an_old_loaded_php_class(): void
    {
        app(PluginManager::class)->nativePaymentProvider('epay');
        $directory = sys_get_temp_dir().'/native-loaded-version-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        try {
            $path = $directory.'/plugins-core/Epay';
            File::copyDirectory(base_path('plugins-core/Epay'), $path);
            foreach (['config.json', 'module.json'] as $file) {
                $manifest = json_decode(File::get($path.'/'.$file), true, 32, JSON_THROW_ON_ERROR);
                $manifest['version'] = '2.0.0';
                File::put($path.'/'.$file, json_encode($manifest, JSON_THROW_ON_ERROR));
            }
            $lock = json_decode(File::get(base_path('bootstrap/module-packages.lock.json')), true, 32, JSON_THROW_ON_ERROR);
            $entry = array_values(array_filter($lock['packages'], static fn (array $entry): bool => $entry['code'] === 'epay'))[0];
            $entry['version'] = '2.0.0';
            foreach ($entry['files'] as $file => $_hash) $entry['files'][$file] = hash_file('sha256', $path.'/'.$file);
            $this->app->instance(\App\Core\Host\Modules\ModulePackageLock::class,
                new \App\Core\Host\Modules\ModulePackageLock($directory, ['schema' => 1, 'packages' => [$entry]]));
            Plugin::query()->where('code', 'epay')->update(['version' => '2.0.0']);
            $manager = new class(app(PluginPackage::class), $path) extends PluginManager {
                public function __construct(PluginPackage $package, private readonly string $fixturePath)
                {
                    parent::__construct($package);
                }
                public function getPluginPath(string $pluginCode): string { return $this->fixturePath; }
            };
            $this->rejects('PAYMENT_PROVIDER_LOADED_VERSION_UNAVAILABLE', fn () => $manager->nativePaymentProvider('epay'));
            Http::assertNothingSent();
        } finally {
            Plugin::query()->where('code', 'epay')->update(['version' => '1.0.0']);
            File::deleteDirectory($directory);
        }
    }

    public function test_persisted_lifecycle_fence_rejects_checkout_and_concurrent_enable_without_creating_attempts(): void
    {
        [$user, $order] = $this->purchase();
        $token = str_repeat('b', 64);
        Plugin::query()->where('code', 'epay')->update(['native_payment_mutation_token' => $token]);
        $this->rejects('NATIVE_PAYMENT_PROVIDER_UNSUPPORTED',
            fn () => app(NativePaymentCheckout::class)->start($user->id, $order->id, $this->payment->id, 'fenced'));
        $this->rejectsModule('MODULE_DEPLOYMENT_BUSY', fn () => app(PluginManager::class)->enable('epay'));
        $this->rejectsModule('MODULE_DEPLOYMENT_BUSY', fn () => app(PluginManager::class)->uninstall('epay'));
        $this->assertSame($token, Plugin::query()->where('code', 'epay')->value('native_payment_mutation_token'));
        $this->assertSame(0, DB::table('tx_payment_attempt')->count());
        Http::assertNothingSent();
    }

    public function test_package_delete_keeps_registration_and_fence_until_directory_removal_finishes(): void
    {
        $this->deleteProbe(true);
    }

    public function test_uninstalled_package_delete_still_fences_install_without_an_installed_row(): void
    {
        $this->deleteProbe(false);
    }

    private function deleteProbe(bool $installed): void
    {
        $code = 'native_delete_probe';
        $manager = app(PluginManager::class);
        $path = $manager->getUserPluginPath($code);
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($path);
        $filesystem->put($path.'/config.json', json_encode(['package' => ['schema' => 1],
            'code' => $code, 'name' => 'Delete probe', 'version' => '1.0.0', 'type' => 'payment',
            'payment' => ['contract' => 'native-payment-provider/1', 'provider' => 'EPay',
                'snapshot_schema' => 'epay-md5-dispatch/1'],
            'description' => 'Package delete boundary', 'author' => 'Tests'], JSON_THROW_ON_ERROR));
        $filesystem->put($path.'/Plugin.php', '<?php namespace Plugin\\NativeDeleteProbe; '
            .'class Plugin extends \\App\\Services\\Plugin\\AbstractPlugin {}');
        if ($installed) {
            Plugin::query()->create(['code' => $code, 'name' => 'Delete probe', 'type' => Plugin::TYPE_PAYMENT,
                'version' => '1.0.0', 'is_enabled' => false, 'config' => '{}', 'installed_at' => now()]);
        }
        try {
            File::partialMock()->shouldReceive('deleteDirectory')->with($path)->once()
                ->andReturnUsing(function (string $directory) use ($code, $installed, $filesystem): bool {
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertSame($installed, Plugin::query()->where('code', $code)->exists());
                    $this->assertNotNull(DB::table('tx_plugin_package_fence')->where('plugin_code', $code)->value('mutation_token'));
                    $this->rejects('PAYMENT_PLUGIN_MUTATION_IN_PROGRESS',
                        fn () => app(PluginManager::class)->install($code));
                    $this->rejects('PAYMENT_PLUGIN_MUTATION_IN_PROGRESS',
                        fn () => app(PluginManager::class)->enable($code));
                    return $filesystem->deleteDirectory($directory);
                });
            $this->assertTrue($manager->delete($code));
            $this->assertFalse($filesystem->exists($path));
            $this->assertFalse(Plugin::query()->where('code', $code)->exists());
            $fence = DB::table('tx_plugin_package_fence')->where('plugin_code', $code)->first();
            $this->assertNotNull($fence);
            $this->assertNull($fence->mutation_token);
            $this->assertSame(0, DB::table('tx_payment_dispatch')->count());
        } finally {
            $filesystem->deleteDirectory($path);
        }
    }

    private function rejectsModule(string $code, \Closure $action): void
    {
        try { $action(); $this->fail('Expected '.$code); }
        catch (\App\Extensions\Contracts\ExtensionFailure $error) { $this->assertSame($code, $error->errorCode); }
    }

    private function paymentSnapshot(int|string $attemptId): array
    {
        return json_decode(Crypt::decryptString(DB::table('tx_payment_dispatch')->where('attempt_id', $attemptId)
            ->value('snapshot_ciphertext')), true, 16, JSON_THROW_ON_ERROR);
    }
}
