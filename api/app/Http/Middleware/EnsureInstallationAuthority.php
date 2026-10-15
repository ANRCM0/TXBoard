<?php

namespace App\Http\Middleware;

use App\Core\Http\TxapiResponse;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstallationAuthority
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->path(), ['api/health', 'txapi/health'], true)) {
            try {
                app(InstallationAuthority::class)->assertRuntime();
            } catch (InstallationAuthorityException $error) {
                return TxapiResponse::error($request, $error->getMessage(),
                    'Core installation is not ready', 503);
            }
        }
        return $next($request);
    }
}
