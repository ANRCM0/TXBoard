<?php

namespace App\Providers;

use App\Services\Module\Adapters\AgentOpsModuleAdapter;
use App\Services\Module\Adapters\PluginLifecycleAdapter;
use App\Services\Module\Adapters\PluginModuleAdapter;
use App\Services\Module\Adapters\ThemeModuleAdapter;
use App\Services\Module\ModuleLifecycle;
use App\Services\Module\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ModuleRegistry::class, function ($app) {
            return new ModuleRegistry([
                // System-owned identities win deterministic duplicate-ID resolution.
                $app->make(AgentOpsModuleAdapter::class),
                $app->make(ThemeModuleAdapter::class),
                $app->make(PluginModuleAdapter::class),
            ]);
        });

        $this->app->scoped(ModuleLifecycle::class, function ($app) {
            return new ModuleLifecycle(
                modules: $app->make(ModuleRegistry::class),
                adapters: [
                    $app->make(PluginLifecycleAdapter::class),
                ],
            );
        });
    }
}
