<?php

namespace App\Http\Middleware;

use App\Models\Plugin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runtime boundary for stale plugin routes in long-lived Octane workers.
 * A route registered before disable must not execute after disable.
 */
final class EnsurePluginEnabled
{
    public function handle(Request $request, Closure $next, string $pluginCode)
    {
        if (!preg_match('/^[a-z0-9_]+$/D', $pluginCode)
            || !Plugin::query()->where('code', $pluginCode)->where('is_enabled', true)->exists()) {
            throw new NotFoundHttpException('Plugin route unavailable');
        }

        return $next($request);
    }
}
