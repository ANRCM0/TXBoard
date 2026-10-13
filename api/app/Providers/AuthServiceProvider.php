<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

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
