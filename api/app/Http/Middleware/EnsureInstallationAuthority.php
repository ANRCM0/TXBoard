<?php

namespace App\Http\Middleware;

use App\Core\Http\TxapiResponse;
use App\Core\Host\Execution\InvocationScope;
use App\Core\Host\Modules\ModuleRuntime;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstallationAuthority
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('gateway/v1', 'gateway/v1/*')) {
            if ($rejection = app(BrowserIngress::class)->preflight($request)) return $rejection;
            if (!\App\Domains\Installation\NativeRuntimeAdmission::http($request->method(), $request->path())) {
                $known = preg_match('#\Agateway/v1/(?:bootstrap|core/(?:user|admin)/capabilities|core/(?:user|admin)/operations/[a-z][a-z0-9.-]{1,100})\z#D', $request->path());
                return \App\Domains\Browser\BrowserResponse::error($request, 'NOT_FOUND', $known ? 405 : 404);
            }
        }
        return app(InvocationScope::class)->run('web', fn (): Response => $this->admit($request, $next),
            TxapiResponse::requestId($request));
    }

    private function admit(Request $request, Closure $next): Response
    {
        if (!in_array($request->path(), ['api/health', 'txapi/health'], true)) {
            try {
                app(ModuleRuntime::class)->plan();
                $authority = app(InstallationAuthority::class);
                $state = $authority->inspect();
                if (($state['runtime_version'] ?? null) === \App\Domains\Installation\NativeRuntimeAdmission::VERSION) {
                    $authority->assertHttp($request->method(), $request->path());
                } else {
                    $authority->assertRuntime();
                }
                app(InvocationScope::class)->installation($state);
            } catch (InstallationAuthorityException $error) {
                if ($request->is('gateway/v1', 'gateway/v1/*')) return \App\Domains\Browser\BrowserResponse::error($request, 'UPSTREAM_UNAVAILABLE', 503);
                return TxapiResponse::error($request, $error->getMessage(),
                    'Core installation is not ready', 503);
            }
        }
        return $next($request);
    }
}
