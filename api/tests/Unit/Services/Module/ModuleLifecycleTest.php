<?php

namespace Tests\Unit\Services\Module;

use App\Services\Module\ModuleAdapter;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleDiscoveryResult;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleLifecycle;
use App\Services\Module\ModuleLifecycleAdapter;
use App\Services\Module\ModuleLifecycleErrorCode;
use App\Services\Module\ModuleLifecycleOperation;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleRegistry;
use App\Services\Module\ModuleSource;
use App\Services\Module\ModuleType;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class ModuleLifecycleTest extends TestCase
{
    public function test_successful_operation_re_reads_registry_state(): void
    {
        $state = $this->state();
        $registry = $this->registry($state);

        $adapter = new class($state) implements ModuleLifecycleAdapter {
            public function __construct(private readonly stdClass $state)
            {
            }

            public function name(): string
            {
                return 'test';
            }

            public function supports(ModuleDescriptor $module): bool
            {
                return $module->manifest->type === ModuleType::PLUGIN;
            }

            public function supportsOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return $this->supports($module);
            }

            public function expectsModuleAfterOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return true;
            }

            public function execute(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): void {
                $this->state->executions++;
                if ($operation === ModuleLifecycleOperation::ENABLE) {
                    $this->state->enabled = true;
                }
            }
        };

        $result = (new ModuleLifecycle($registry, [$adapter]))
            ->execute('access_audit', ModuleLifecycleOperation::ENABLE);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertTrue($result->module?->enabled);
        $this->assertSame(1, $state->executions);
        $this->assertSame(2, $state->discoveries);
    }

    public function test_unknown_module_returns_structured_error_without_delegation(): void
    {
        $state = $this->state();
        $state->exists = false;

        $result = (new ModuleLifecycle($this->registry($state), []))
            ->execute('missing_module', ModuleLifecycleOperation::ENABLE);

        $this->assertFalse($result->success);
        $this->assertSame(ModuleLifecycleErrorCode::MODULE_NOT_FOUND, $result->error?->code);
        $this->assertNull($result->module);
    }

    public function test_non_plugin_module_without_lifecycle_adapter_is_explicitly_unsupported(): void
    {
        $state = $this->state();
        $state->type = ModuleType::THEME;

        $result = (new ModuleLifecycle($this->registry($state), []))
            ->execute('access_audit', ModuleLifecycleOperation::ENABLE);

        $this->assertFalse($result->success);
        $this->assertSame(
            ModuleLifecycleErrorCode::UNSUPPORTED_MODULE_TYPE,
            $result->error?->code,
        );
        $this->assertSame(ModuleType::THEME, $result->module?->manifest->type);
    }

    public function test_runtime_failure_is_mapped_and_refreshed_without_exposing_exception_text(): void
    {
        $state = $this->state();
        $registry = $this->registry($state);

        $adapter = new class($state) implements ModuleLifecycleAdapter {
            public function __construct(private readonly stdClass $state)
            {
            }

            public function name(): string
            {
                return 'plugin';
            }

            public function supports(ModuleDescriptor $module): bool
            {
                return true;
            }

            public function supportsOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return $this->supports($module);
            }

            public function expectsModuleAfterOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return true;
            }

            public function execute(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): void {
                $this->state->enabled = true;
                throw new RuntimeException('database password=super-secret');
            }
        };

        $result = (new ModuleLifecycle($registry, [$adapter]))
            ->execute('access_audit', ModuleLifecycleOperation::ENABLE);

        $this->assertFalse($result->success);
        $this->assertSame(ModuleLifecycleErrorCode::RUNTIME_ERROR, $result->error?->code);
        $this->assertSame('plugin', $result->error?->adapter);
        $this->assertStringNotContainsString('super-secret', $result->error?->message ?? '');
        $this->assertTrue($result->module?->enabled);
        $this->assertSame(2, $state->discoveries);
    }

    public function test_missing_module_after_mutation_returns_state_refresh_error(): void
    {
        $state = $this->state();
        $registry = $this->registry($state);

        $adapter = new class($state) implements ModuleLifecycleAdapter {
            public function __construct(private readonly stdClass $state)
            {
            }

            public function name(): string
            {
                return 'plugin';
            }

            public function supports(ModuleDescriptor $module): bool
            {
                return true;
            }

            public function supportsOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return $this->supports($module);
            }

            public function expectsModuleAfterOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return true;
            }

            public function execute(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): void {
                $this->state->exists = false;
            }
        };

        $result = (new ModuleLifecycle($registry, [$adapter]))
            ->execute('access_audit', ModuleLifecycleOperation::UNINSTALL);

        $this->assertFalse($result->success);
        $this->assertSame(
            ModuleLifecycleErrorCode::STATE_REFRESH_FAILED,
            $result->error?->code,
        );
        $this->assertNull($result->module);
    }

    public function test_known_module_type_with_unsupported_operation_returns_explicit_error(): void
    {
        $state = $this->state();
        $registry = $this->registry($state);

        $adapter = new class implements ModuleLifecycleAdapter {
            public function name(): string
            {
                return 'theme';
            }

            public function supports(ModuleDescriptor $module): bool
            {
                return true;
            }

            public function supportsOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return false;
            }

            public function expectsModuleAfterOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return true;
            }

            public function execute(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): void {
                throw new RuntimeException('must not execute');
            }
        };

        $result = (new ModuleLifecycle($registry, [$adapter]))
            ->execute('access_audit', ModuleLifecycleOperation::DISABLE);

        $this->assertFalse($result->success);
        $this->assertSame(
            ModuleLifecycleErrorCode::UNSUPPORTED_OPERATION,
            $result->error?->code,
        );
        $this->assertSame('theme', $result->error?->adapter);
        $this->assertSame(1, $state->discoveries);
    }

    public function test_successful_removal_may_end_with_module_absent_from_registry(): void
    {
        $state = $this->state();
        $registry = $this->registry($state);

        $adapter = new class($state) implements ModuleLifecycleAdapter {
            public function __construct(private readonly stdClass $state)
            {
            }

            public function name(): string
            {
                return 'theme';
            }

            public function supports(ModuleDescriptor $module): bool
            {
                return true;
            }

            public function supportsOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return $operation === ModuleLifecycleOperation::UNINSTALL;
            }

            public function expectsModuleAfterOperation(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): bool {
                return false;
            }

            public function execute(
                ModuleDescriptor $module,
                ModuleLifecycleOperation $operation,
            ): void {
                $this->state->exists = false;
            }
        };

        $result = (new ModuleLifecycle($registry, [$adapter]))
            ->execute('access_audit', ModuleLifecycleOperation::UNINSTALL);

        $this->assertTrue($result->success);
        $this->assertNull($result->module);
        $this->assertNull($result->error);
        $this->assertSame(2, $state->discoveries);
    }

    public function test_result_array_uses_stable_operation_and_error_vocabulary(): void
    {
        $state = $this->state();
        $state->exists = false;

        $payload = (new ModuleLifecycle($this->registry($state), []))
            ->execute('missing_module', ModuleLifecycleOperation::INSTALL)
            ->toArray();

        $this->assertSame('install', $payload['operation']);
        $this->assertFalse($payload['success']);
        $this->assertNull($payload['module']);
        $this->assertSame('module_not_found', $payload['error']['code']);
        $this->assertSame('Module not found', $payload['error']['message']);
    }

    private function state(): stdClass
    {
        $state = new stdClass();
        $state->id = 'access_audit';
        $state->exists = true;
        $state->installed = true;
        $state->enabled = false;
        $state->type = ModuleType::PLUGIN;
        $state->discoveries = 0;
        $state->executions = 0;

        return $state;
    }

    private function registry(stdClass $state): ModuleRegistry
    {
        $adapter = new class($state) implements ModuleAdapter {
            public function __construct(private readonly stdClass $state)
            {
            }

            public function name(): string
            {
                return 'state';
            }

            public function discover(): ModuleDiscoveryResult
            {
                $this->state->discoveries++;

                if (!$this->state->exists) {
                    return new ModuleDiscoveryResult([]);
                }

                return new ModuleDiscoveryResult([
                    new ModuleDescriptor(
                        manifest: ModuleManifest::fromArray([
                            'schema' => 1,
                            'module' => [
                                'id' => $this->state->id,
                                'name' => 'Access Audit',
                                'version' => '1.0.0',
                                'type' => $this->state->type->value,
                            ],
                            'compatibility' => ['txboard' => '*'],
                            'capabilities' => [],
                        ]),
                        source: ModuleSource::USER,
                        installed: $this->state->installed,
                        enabled: $this->state->enabled,
                        active: null,
                        health: ModuleHealth::HEALTHY,
                    ),
                ]);
            }
        };

        return new ModuleRegistry([$adapter]);
    }
}
