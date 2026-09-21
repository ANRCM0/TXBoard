<?php

namespace App\Http\Routes\V2;

use App\Http\Routes\V2\Admin\AnalyticsRoute;
use App\Http\Routes\V2\Admin\CommerceRoute;
use App\Http\Routes\V2\Admin\ContentRoute;
use App\Http\Routes\V2\Admin\ExtensionRoute;
use App\Http\Routes\V2\Admin\ServerRoute as AdminServerRoute;
use App\Http\Routes\V2\Admin\SystemRoute;
use App\Http\Routes\V2\Admin\UserRoute as AdminUserRoute;
use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    /** @var array<class-string> */
    private const ROUTE_MODULES = [
        SystemRoute::class,
        AdminServerRoute::class,
        CommerceRoute::class,
        AdminUserRoute::class,
        ContentRoute::class,
        AnalyticsRoute::class,
        ExtensionRoute::class,
    ];

    public function map(Registrar $router): void
    {
        // Register a stable route template and validate the actual secure path
        // per request. This lets operators rotate secure_path without reloading
        // Octane or rebuilding Laravel's route table.
        $router->group([
            'prefix' => '{admin_path}',
            'middleware' => ['admin.path', 'admin', 'log'],
        ], function (Registrar $router): void {
            foreach (self::ROUTE_MODULES as $routeModule) {
                app($routeModule)->map($router);
            }
        });
    }
}
