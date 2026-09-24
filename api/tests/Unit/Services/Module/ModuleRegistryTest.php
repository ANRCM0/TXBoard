<?php

namespace Tests\Unit\Services\Module;

use App\Services\Module\ModuleAdapter;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleDiscoveryResult;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleId;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleRegistry;
use App\Services\Module\ModuleSource;
use App\Services\Module\ModuleType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ModuleRegistryTest extends TestCase
{
    public function test_registry_merges_sorts_and_finds_modules(): void
    {
        $registry = new ModuleRegistry([
            $this->adapter('b', [$this->descriptor('plugin_b')]),
            $this->adapter('a', [$this->descriptor('plugin_a')]),
        ]);

        $snapshot = $registry->snapshot();

        $this->assertSame(
            ['plugin_a', 'plugin_b'],
            array_map(fn (ModuleDescriptor $module) => $module->manifest->id, $snapshot->modules),
        );
        $this->assertSame('plugin_b', $snapshot->find('plugin_b')?->manifest->id);
        $this->assertNull($snapshot->find('missing'));
    }

    public function test_adapter_failure_is_isolated(): void
    {
        $broken = new class implements ModuleAdapter {
            public function name(): string
            {
                return 'broken';
            }

            public function discover(): ModuleDiscoveryResult
            {
                throw new RuntimeException('broken adapter: password=do-not-expose');
            }
        };

        $registry = new ModuleRegistry([
            $broken,
            $this->adapter('healthy', [$this->descriptor('healthy_module')]),
        ]);

        $snapshot = $registry->snapshot();

        $this->assertCount(1, $snapshot->modules);
        $this->assertSame('healthy_module', $snapshot->modules[0]->manifest->id);
        $this->assertCount(1, $snapshot->errors);
        $this->assertSame('broken', $snapshot->errors[0]->adapter);
        $this->assertSame('Module adapter discovery failed', $snapshot->errors[0]->message);
        $this->assertStringNotContainsString('do-not-expose', json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_duplicate_module_ids_are_reported_without_replacing_first_module(): void
    {
        $first = $this->descriptor('duplicate', ModuleSource::SYSTEM);
        $second = $this->descriptor('duplicate', ModuleSource::USER);

        $registry = new ModuleRegistry([
            $this->adapter('first', [$first]),
            $this->adapter('second', [$second]),
        ]);

        $snapshot = $registry->snapshot();

        $this->assertCount(1, $snapshot->modules);
        $this->assertSame(ModuleSource::SYSTEM, $snapshot->modules[0]->source);
        $this->assertCount(1, $snapshot->errors);
        $this->assertSame('duplicate', $snapshot->errors[0]->moduleId);
    }

    public function test_snapshot_summary_counts_all_health_states(): void
    {
        $registry = new ModuleRegistry([
            $this->adapter('test', [
                $this->descriptor('healthy_module', health: ModuleHealth::HEALTHY),
                $this->descriptor('disabled_module', health: ModuleHealth::DISABLED),
                $this->descriptor('failed_module', health: ModuleHealth::FAILED),
            ]),
        ]);

        $payload = $registry->snapshot()->toArray();

        $this->assertSame(3, $payload['summary']['total']);
        $this->assertSame(1, $payload['summary']['health']['healthy']);
        $this->assertSame(1, $payload['summary']['health']['disabled']);
        $this->assertSame(1, $payload['summary']['health']['failed']);
        $this->assertSame(0, $payload['summary']['discovery_errors']);
    }

    public function test_legacy_theme_ids_are_stable_and_safe(): void
    {
        $this->assertSame('theme.txboard', ModuleId::legacy('theme', 'TXBoard'));

        $first = ModuleId::legacy('theme', '主题 A');
        $second = ModuleId::legacy('theme', '主题 A');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^theme\.[a-z0-9][a-z0-9._-]*$/', $first);
        $this->assertLessThanOrEqual(64, strlen($first));
    }

    private function adapter(string $name, array $modules): ModuleAdapter
    {
        return new class($name, $modules) implements ModuleAdapter {
            public function __construct(
                private readonly string $adapterName,
                private readonly array $modules,
            ) {
            }

            public function name(): string
            {
                return $this->adapterName;
            }

            public function discover(): ModuleDiscoveryResult
            {
                return new ModuleDiscoveryResult($this->modules);
            }
        };
    }

    private function descriptor(
        string $id,
        ModuleSource $source = ModuleSource::BUNDLED,
        ModuleHealth $health = ModuleHealth::HEALTHY,
    ): ModuleDescriptor {
        return new ModuleDescriptor(
            manifest: ModuleManifest::fromArray([
                'schema' => 1,
                'module' => [
                    'id' => $id,
                    'name' => $id,
                    'version' => '1.0.0',
                    'type' => ModuleType::PLUGIN->value,
                ],
                'compatibility' => ['txboard' => '*'],
                'capabilities' => [],
            ]),
            source: $source,
            installed: true,
            enabled: true,
            active: null,
            health: $health,
        );
    }
}
