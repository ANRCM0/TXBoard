<?php

namespace App\Providers;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Models\Plan;
use App\Models\User;
use App\Observers\PlanObserver;
use App\Observers\ServerObserver;
use App\Observers\ServerRouteObserver;
use App\Observers\UserObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * 事件监听器映射
     * @var array<string, array<int, class-string>>
     */
    protected $listen = [
    ];

    /**
     * 注册任何事件
     * @return void
     */
    public function boot()
    {
        parent::boot();
        Event::listen(\Illuminate\Console\Events\CommandStarting::class,
            [\App\Listeners\EnforceInstallationAuthority::class, 'commandStarting']);
        Event::listen(\Illuminate\Queue\Events\JobProcessing::class,
            [\App\Listeners\EnforceInstallationAuthority::class, 'jobProcessing']);

        User::created([UserObserver::class, 'onCreated']);
        User::updated([UserObserver::class, 'onUpdated']);
        User::deleted([UserObserver::class, 'onDeleted']);
        Plan::observe(PlanObserver::class);
        Server::created([ServerObserver::class, 'onCreated']);
        Server::updated([ServerObserver::class, 'onUpdated']);
        Server::deleted([ServerObserver::class, 'onDeleted']);
        ServerRoute::observe(ServerRouteObserver::class);


    }
}
