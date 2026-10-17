<?php

namespace Tests\Unit\Core;

use App\Core\Host\Modules\ModulePackageLock;
use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ModuleRuntime;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Core\Kernel\Modules\ModuleError;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

interface PackagePrivatePort {}
final class PackagePrivateOwner implements PackagePrivatePort {}

final class ModulePackageLockTest extends TestCase
{
    private string $root;
    private array $manifest;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/module-lock-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/plugins/Demo', 0700, true);
        $this->manifest = ['schema' => 'core-module/v1', 'id' => 'demo', 'version' => '1.0.0', 'kind' => 'optional-business',
            'execution' => 'in-process', 'kernel_api' => ['min' => 1, 'max' => 1], 'requires' => [], 'boot_after' => [],
            'operations' => (object) [], 'subscriptions' => [], 'config_schema' => [
                'type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false], 'schema_version' => 0];
        $this->write();
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $path) {
            if ($path->isDir() && !$path->isLink()) rmdir($path->getPathname());
            else unlink($path->getPathname());
        }
        rmdir($this->root);
    }

    public function test_fixed_hashes_reject_changed_and_unlisted_code_and_optional_errors_stay_bounded(): void
    {
        $lock = $this->lock();
        $valid = new ModulePackageLock($this->root, $lock);
        $this->assertNotNull($valid->package('demo'));
        $fingerprint = $valid->fingerprint();
        file_put_contents($this->root.'/plugins/Demo/Plugin.php', '<?php /* changed */');
        $invalid = new ModulePackageLock($this->root, $lock);
        $this->assertNull($invalid->package('demo'));
        $this->assertSame(['plugin.demo' => 'MODULE_PACKAGE_VERIFICATION_FAILED'], $invalid->unavailable());
        $this->assertSame($fingerprint, $invalid->fingerprint());
        $this->assertSame($valid->declared(), $invalid->declared());
        $this->write();
        file_put_contents($this->root.'/plugins/Demo/extra.php', '<?php');
        $this->assertNull((new ModulePackageLock($this->root, $lock))->package('demo'));
        $lock['packages'][0]['required'] = true;
        $this->expectException(ModuleError::class);
        $this->expectExceptionMessage('MODULE_PACKAGE_VERIFICATION_FAILED');
        new ModulePackageLock($this->root, $lock);
    }

    public function test_self_declared_admin_permission_or_event_cannot_grant_itself_execution(): void
    {
        $this->manifest['operations'] = ['status.get' => ['actor' => 'admin', 'permission' => 'extensions.demo.view',
            'idempotency' => 'read', 'input' => $this->manifest['config_schema'], 'output' => $this->manifest['config_schema']]];
        $this->manifest['subscriptions'] = ['billing.payment-settled.v1'];
        $this->write();
        $lock = $this->lock();
        $this->assertNull((new ModulePackageLock($this->root, $lock))->package('demo'));
        $lock['packages'][0]['grants'] = ['permission:extensions.demo.view', 'event:billing.payment-settled.v1'];
        $this->assertNotNull((new ModulePackageLock($this->root, $lock))->package('demo'));
        $this->manifest['operations']['status.get']['permission'] = 'billing.refund';
        $this->write();
        $lock = $this->lock();
        $lock['packages'][0]['grants'] = ['permission:billing.refund', 'event:billing.payment-settled.v1'];
        $this->assertNull((new ModulePackageLock($this->root, $lock))->package('demo'));
    }

    public function test_trusted_capability_grant_still_cannot_export_private_sql_participation(): void
    {
        $this->manifest['requires'] = [['id' => 'private.sql-owner', 'min' => 1, 'max' => 1]];
        $this->write();
        $lock = $this->lock();
        $lock['packages'][0]['grants'] = ['capability:private.sql-owner'];
        $packages = new ModulePackageLock($this->root, $lock);
        $owner = new class implements ModuleProvider {
            public function definition(): ModuleDefinition
            {
                return new ModuleDefinition('owner', '1.0.0', 'required-business', provides: [
                    new CapabilityDefinition('private.sql-owner', PackagePrivatePort::class, exported: false)], required: true);
            }
            public function bindings(): array { return [new ServiceBinding(PackagePrivatePort::class, PackagePrivateOwner::class)]; }
        };
        $runtime = new ModuleRuntime;
        $container = new Container;
        $runtime->register($container, [$owner, ...$packages->providers()]);
        $this->assertSame('MODULE_PRIVATE_CAPABILITY_DENIED', $runtime->plan()->unavailable['plugin.demo']);
        $this->assertInstanceOf(PackagePrivateOwner::class, $container->make(PackagePrivatePort::class));
        $lock['packages'][0]['required'] = true;
        $required = new ModulePackageLock($this->root, $lock);
        $untouched = new Container;
        try { (new ModuleRuntime)->register($untouched, [$owner, ...$required->providers()]); $this->fail('Expected required package rejection'); }
        catch (ModuleError $error) { $this->assertSame('MODULE_PRIVATE_CAPABILITY_DENIED', $error->errorCode); }
        $this->assertFalse($untouched->bound(PackagePrivatePort::class));
    }

    public function test_path_traversal_duplicate_json_and_case_confusion_fail_package_verification(): void
    {
        $lock = $this->lock();
        $lock['packages'][0]['root'] = 'plugins/../Demo';
        $this->assertNull((new ModulePackageLock($this->root, $lock))->package('demo'));
        $path = $this->root.'/plugins/Demo/module.json';
        file_put_contents($path, preg_replace('/"id":"demo"/', '"id":"demo","id":"other"', file_get_contents($path)));
        $this->assertNull((new ModulePackageLock($this->root, $this->lock()))->package('demo'));
        $this->write();
        $lock = $this->lock();
        $lock['packages'][0]['files']['../Plugin.php'] = hash('sha256', '<?php');
        $this->assertNull((new ModulePackageLock($this->root, $lock))->package('demo'));
    }

    private function write(): void
    {
        file_put_contents($this->root.'/plugins/Demo/module.json', json_encode($this->manifest, JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/plugins/Demo/config.json', json_encode(['code' => 'demo', 'version' => '1.0.0',
            'package' => ['schema' => 1], 'name' => 'Demo', 'type' => 'feature']));
        file_put_contents($this->root.'/plugins/Demo/Plugin.php', '<?php /* fixture; never loaded */');
    }

    private function lock(): array
    {
        $files = [];
        foreach (['module.json', 'config.json', 'Plugin.php'] as $file) $files[$file] = hash_file('sha256', $this->root.'/plugins/Demo/'.$file);
        return ['schema' => 1, 'packages' => [['code' => 'demo', 'version' => '1.0.0', 'root' => 'plugins/Demo',
            'required' => false, 'grants' => [], 'files' => $files]]];
    }
}
