<?php

namespace App\Http\Middleware;

use App\Services\Plugin\PluginManager;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware to initialize all enabled plugins at the beginning of a request.
 * It ensures that all plugin hooks, routes, and services are ready.
 */
class InitializePlugins
{
    protected PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Runtime health must work before the database has been installed.
        // Requiring the plugin table here would make a fresh container unhealthy
        // and block the installer at "docker compose up --wait".
        if ($request->is('api/health') || $request->is('txapi/health')) {
            return $next($request);
        }

        // This single method call handles loading and booting all enabled plugins.
        // It's safe to call multiple times, as it will only run once per request.
        $this->pluginManager->initializeEnabledPlugins();

        return $next($request);
    }
}