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
                return TxapiResponse::error($request, $error->getMessage(),
                    'Core installation is not ready', 503);
            }
        }
        return $next($request);
    }
}
