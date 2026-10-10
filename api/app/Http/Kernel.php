<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\TrustProxies::class,
        \App\Http\Middleware\CheckForMaintenanceMode::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        \App\Http\Middleware\InitializePlugins::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\ApplyRuntimeSettings::class,
        ],
        'api' => [
            \App\Http\Middleware\ApplyRuntimeSettings::class,
            \App\Http\Middleware\ForceJson::class,
            \App\Http\Middleware\Language::class,
            'bindings',
        ],
    ];

    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'txapi.user' => \App\Http\Middleware\TxapiUser::class,
        'txapi.request-id' => \App\Http\Middleware\TxapiRequestId::class,
        'admin' => \App\Http\Middleware\Admin::class,
        'admin.path' => \App\Http\Middleware\AdminPath::class,
        'agent' => \App\Http\Middleware\AgentAuth::class,
        'agent.log' => \App\Http\Middleware\AgentRequestLog::class,
        'client' => \App\Http\Middleware\Client::class,
        'staff' => \App\Http\Middleware\Staff::class,
        'log' => \App\Http\Middleware\RequestLog::class,
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
    ];

    protected $middlewarePriority = [
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];
}
