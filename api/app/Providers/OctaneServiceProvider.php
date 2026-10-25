<?php

namespace App\Providers;

use App\Services\Plugin\HookManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\WorkerStarting;

class OctaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }
        if ($this->app->bound('octane')) {
            $this->app['events']->listen(WorkerStarting::class, function () {
                HookManager::reset();
            });
        }
    }
}
