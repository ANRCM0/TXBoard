<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        // HTTPS scheme is forced per-request via middleware (Octane-safe).
        parent::boot();
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        // Container/runtime health endpoint. Keep this route deliberately free
        // of database, cache and session middleware: a successful response proves
        // that Laravel providers booted and an Octane worker can serve requests.
        Route::get('/api/health', static function () {
            return response()->json(['status' => 'ok']);
        });
        // Public liveness is deliberately outside API middleware and database
        // boot: independent of billing, Redis, plug-ins or migration state.
        Route::get('/txapi/health', static function (\Illuminate\Http\Request $request) {
            return \App\Core\Http\TxapiResponse::success($request, ['status' => 'ok']);
        });

        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::middleware(['api', 'txapi.request-id'])
            ->prefix('/txapi')
            ->group(base_path('routes/txapi.php'));

        // No TXBoard V1/V2 route registry: TXAPI is the sole application API.
    }
}
