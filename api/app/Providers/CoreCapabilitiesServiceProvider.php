<?php

namespace App\Providers;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ModuleRuntime;
use App\Core\Host\Modules\ModulePackageLock;
use App\Core\Kernel\Modules\ModuleError;
use App\Modules;
use Illuminate\Support\ServiceProvider;

final class CoreCapabilitiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $providers = [new Modules\PlatformModule, new Modules\InstallationModule, new Modules\ProviderModule,
            new Modules\IdentityModule, new Modules\SubscriptionModule, new Modules\BillingModule,
            new Modules\UsageModule, new Modules\NetworkModule, new Modules\NotificationsModule];
        $extensions = config('core_modules.providers', []);
        if (!is_array($extensions) || !array_is_list($extensions) || count($extensions) > 64) {
            throw new ModuleError('MODULE_PROVIDER_CONFIGURATION_INVALID');
        }
        foreach ($extensions as $class) {
            if (!is_string($class) || !is_subclass_of($class, ModuleProvider::class)) {
                throw new ModuleError('MODULE_PROVIDER_CONFIGURATION_INVALID');
            }
            $providers[] = new $class;
        }
        $packages = ModulePackageLock::fromFile(base_path(), config('core_modules.package_lock'));
        $this->app->instance(ModulePackageLock::class, $packages);
        $runtime = new ModuleRuntime;
        $runtime->register($this->app, array_merge($providers, $packages->providers()), $packages->unavailable());
        $this->app->instance(ModuleRuntime::class, $runtime);
        $this->app->singleton(\App\Core\Host\Execution\InvocationActivities::class);
    }
}
