<?php

namespace Tests\Feature\Plugin;

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginInstallSafetyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Phase3MissingRuntime'));
        File::deleteDirectory(base_path('plugins/RetiredPackageFixture'));
        File::deleteDirectory(base_path('plugins/OldPaymentFixture'));
        File::deleteDirectory(base_path('plugins/MissingPaymentInterfaceFixture'));
        parent::tearDown();
    }

    public function test_enabled_old_manifest_is_rejected_before_loading_php(): void
    {
        $path = base_path('plugins/RetiredPackageFixture');
        File::ensureDirectoryExists($path);
        File::put($path.'/config.json', json_encode([
            'name' => 'Old package', 'code' => 'retired_package_fixture', 'version' => '1.0.0',
            'description' => 'No current package schema', 'author' => 'TXBoard',
        ], JSON_THROW_ON_ERROR));
        File::put($path.'/Plugin.php', '<?php throw new \RuntimeException("old code executed");');
        Plugin::create(['name' => 'Old package', 'code' => 'retired_package_fixture',
            'version' => '1.0.0', 'is_enabled' => true, 'installed_at' => now()]);
        $manager = app(PluginManager::class);
        $this->assertArrayNotHasKey('retired_package_fixture', $manager->getEnabledPlugins());
        $method = new \ReflectionMethod(PluginManager::class, 'loadPlugin');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid current plugin package');
        $method->invoke($manager, 'retired_package_fixture');
    }

    public function test_missing_plugin_implementation_rejects_before_transaction_or_registration(): void
    {
        $path = base_path('plugins/Phase3MissingRuntime');
        File::ensureDirectoryExists($path);
        File::put($path . '/config.json', json_encode([
            'name' => 'Missing Runtime',
            'code' => 'phase3_missing_runtime',
            'version' => '1.0.0',
            'description' => 'Safety fixture',
            'author' => 'TXBoard',
            'package' => ['schema' => 1],
        ], JSON_THROW_ON_ERROR));

        $before = DB::transactionLevel();
        try {
            app(PluginManager::class)->install('phase3_missing_runtime');
            $this->fail('Missing PHP implementation must never be installed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('implementation not found', $e->getMessage());
        }
        $this->assertSame($before, DB::transactionLevel());
        $this->assertFalse(Plugin::query()->where('code', 'phase3_missing_runtime')->exists());
        $this->assertFileExists($path . '/config.json');
    }

    public function test_payment_package_without_current_contract_is_rejected_before_loading_php(): void
    {
        $path = base_path('plugins/OldPaymentFixture');
        File::ensureDirectoryExists($path);
        File::put($path.'/config.json', json_encode([
            'name' => 'Old Payment Fixture', 'code' => 'old_payment_fixture', 'version' => '1.0.0',
            'description' => 'No typed payment declaration', 'author' => 'Tests',
            'type' => 'payment', 'package' => ['schema' => 1],
        ], JSON_THROW_ON_ERROR));
        File::put($path.'/Plugin.php', '<?php throw new \RuntimeException("old payment code executed");');
        try {
            app(PluginManager::class)->install('old_payment_fixture');
            $this->fail('Old payment packages must not be installed.');
        } catch (\Exception $error) {
            $this->assertSame('Invalid plugin config', $error->getMessage());
        }
        $this->assertFalse(Plugin::query()->where('code', 'old_payment_fixture')->exists());
        $this->assertNull(DB::table('tx_plugin_package_fence')->where('plugin_code', 'old_payment_fixture')->value('mutation_token'));
    }

    public function test_payment_declaration_cannot_replace_the_required_php_interface(): void
    {
        $path = base_path('plugins/MissingPaymentInterfaceFixture');
        File::ensureDirectoryExists($path.'/database/migrations');
        File::put($path.'/config.json', json_encode([
            'name' => 'Missing Payment Interface Fixture', 'code' => 'missing_payment_interface_fixture', 'version' => '1.0.0',
            'description' => 'Declaration without typed runtime', 'author' => 'Tests',
            'type' => 'payment', 'package' => ['schema' => 1],
            'payment' => ['contract' => 'native-payment-provider/1', 'provider' => 'FixturePay',
                'snapshot_schema' => 'fixturepay-dispatch/1'],
        ], JSON_THROW_ON_ERROR));
        File::put($path.'/Plugin.php', '<?php namespace Plugin\MissingPaymentInterfaceFixture; '
            .'class Plugin extends \App\Services\Plugin\AbstractPlugin {}');
        File::put($path.'/database/migrations/2026_10_17_000001_probe.php',
            '<?php throw new \RuntimeException("payment migration executed");');
        try {
            app(PluginManager::class)->install('missing_payment_interface_fixture');
            $this->fail('A payment declaration must not substitute for an implementation.');
        } catch (\App\Domains\Billing\BillingError $error) {
            $this->assertSame('NATIVE_PAYMENT_PROVIDER_UNSUPPORTED', $error->errorCode);
        }
        $this->assertFalse(Plugin::query()->where('code', 'missing_payment_interface_fixture')->exists());
    }
}
