<?php

namespace App\Core\Host\Modules;

use App\Core\Kernel\Modules\ModuleDefinition;
use App\Core\Kernel\Modules\ModuleError;
use App\Core\Kernel\Modules\ModulePlan;
use App\Core\Kernel\Modules\ModuleResolver;
use Illuminate\Contracts\Container\Container;

/** One immutable composition per application process; business owners retain their scoped instances. */
final class ModuleRuntime
{
    private ?ModulePlan $plan = null;

    /** @param list<ModuleProvider> $providers */
    public function register(Container $container, array $providers): void
    {
        if ($this->plan !== null) throw new ModuleError('MODULE_COMPOSITION_ALREADY_REGISTERED');
        $definitions = [];
        $byId = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof ModuleProvider) throw new ModuleError('MODULE_PROVIDER_INVALID');
            $definition = $provider->definition();
            $definitions[] = $definition;
            if (isset($byId[$definition->id])) throw new ModuleError('MODULE_ID_CONFLICT', $definition->id);
            $byId[$definition->id] = $provider;
        }
        $plan = (new ModuleResolver)->compose($definitions);
        $bindings = [];
        $owners = [];
        foreach ($plan->modules as $definition) {
            $provided = [];
            $declared = array_column(array_map(static fn ($capability): array => [
                'contract' => $capability->contract, 'multiple' => $capability->multiple,
            ], $definition->provides), 'multiple', 'contract');
            foreach ($byId[$definition->id]->bindings() as $binding) {
                if (!$binding instanceof ServiceBinding || !class_exists($binding->implementation)
                    || !is_a($binding->implementation, $binding->contract, true)
                    || !array_key_exists($binding->contract, $declared) || $declared[$binding->contract]
                    || !(new \ReflectionClass($binding->implementation))->isInstantiable()
                    || isset($bindings[$binding->contract])) {
                    throw new ModuleError('MODULE_BINDING_INVALID', $definition->id);
                }
                $provided[$binding->contract] = true;
                $bindings[$binding->contract] = $binding;
                $oldScope = $owners[$binding->implementation] ?? null;
                if ($oldScope !== null && $oldScope !== $binding->singleton) {
                    throw new ModuleError('MODULE_BINDING_SCOPE_CONFLICT', $definition->id);
                }
                $owners[$binding->implementation] = $binding->singleton;
            }
            foreach ($definition->provides as $capability) {
                if (!$capability->multiple && !isset($provided[$capability->contract])) {
                    throw new ModuleError('MODULE_BINDING_MISSING', $definition->id, $capability->id);
                }
            }
        }
        // Validate the entire set before changing the container. Multiple-provider capabilities
        // remain in their domain registry; no arbitrary connector is bound as the unique authority.
        foreach ($owners as $implementation => $singleton) {
            if ($singleton) $container->singleton($implementation);
            else $container->scoped($implementation);
        }
        foreach ($bindings as $contract => $binding) {
            if ($contract === $binding->implementation) continue;
            $factory = static fn ($app) => $app->make($binding->implementation);
            if ($binding->singleton) $container->singleton($contract, $factory);
            else $container->scoped($contract, $factory);
        }
        $this->plan = $plan;
    }

    public function plan(): ModulePlan
    {
        return $this->plan ?? throw new ModuleError('MODULE_COMPOSITION_NOT_REGISTERED');
    }

    public function inventory(bool $publicOnly = true): array
    {
        $plan = $this->plan();
        return ['schema' => 'core-modules/v1', 'kernel_api' => ModuleDefinition::KERNEL_API,
            'composition' => $plan->fingerprint(), 'composition_ready' => true,
            'modules' => array_map(static fn (ModuleDefinition $module): array => $module->toArray($publicOnly), $plan->modules),
            'unavailable' => $plan->unavailable];
    }
}
