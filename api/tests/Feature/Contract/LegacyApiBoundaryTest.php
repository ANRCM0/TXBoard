<?php

namespace Tests\Feature\Contract;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** P0 freeze: old route consumers remain supported until an explicit migration. */
class LegacyApiBoundaryTest extends TestCase
{
    public function test_critical_legacy_routes_keep_methods_and_guards(): void
    {
        $this->assertRoute('GET', 'api/health');
        $this->assertRoute('POST', 'api/v1/passport/auth/login');
        $this->assertRoute('POST', 'api/v1/user/order/checkout', ['user']);
        $this->assertRoute('GET', 'api/v1/guest/payment/notify/{method}/{uuid}');
        $this->assertRoute('POST', 'api/v1/guest/payment/notify/{method}/{uuid}');
        $this->assertRoute('GET', 'api/v2/server/handshake', ['server.v2']);
        $this->assertRoute('GET', 'api/v2/{admin_path}/config/fetch', ['admin.path', 'admin']);
        $this->assertRoute('GET', 'api/v2/agent/whoami', ['agent']);
    }

    private function assertRoute(string $method, string $uri, array $guards = []): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($entry) => $entry->uri() === $uri && in_array($method, $entry->methods(), true)
        );
        $this->assertNotNull($route, $method . ' ' . $uri . ' is a compatibility boundary');
        foreach ($guards as $guard) {
            $this->assertContains($guard, $route->gatherMiddleware(),
                $method . ' ' . $uri . ' must retain ' . $guard);
        }
    }
}
