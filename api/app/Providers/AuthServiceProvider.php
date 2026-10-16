<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\RequestGuard;
use App\Domains\Identity\AccountSessionGuard;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * 策略映射
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * 注册任何认证/授权服务
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // Same factory and request-refresh lifecycle as the locked Sanctum provider.
        // Session metadata uses the same account lock order as issue and revoke.
        Auth::resolved(static function ($auth): void {
            $auth->extend('sanctum', static function ($app, $name, array $config) use ($auth) {
                return tap(new RequestGuard(
                    new AccountSessionGuard($auth, config('sanctum.expiration'), $config['provider']),
                    request(), $auth->createUserProvider($config['provider'] ?? null)
                ), static function ($guard): void {
                    app()->refresh('request', $guard, 'setRequest');
                });
            });
        });

        // Retired Agent credentials must not become ordinary User/Admin tokens.
        // This is a pure token policy: it stores no request state in Octane workers
        // and preserves Sanctum's expiration/provider checks and historical rows.
        Sanctum::authenticateAccessTokensUsing(static function ($token, bool $valid): bool {
            if (!$valid || str_starts_with((string) $token->name, 'agent:')) return false;
            foreach ($token->abilities ?? [] as $ability) {
                if (is_string($ability) && str_starts_with($ability, 'agent:')) return false;
            }
            return true;
        });
    }
}
