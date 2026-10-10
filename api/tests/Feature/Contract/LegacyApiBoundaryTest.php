<?php

namespace Tests\Feature\Contract;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Native core contracts plus the still-active external-protocol boundaries. */
class LegacyApiBoundaryTest extends TestCase
{
    public function test_critical_legacy_routes_keep_methods_and_guards(): void
    {
        $this->assertRoute('GET', 'api/health');
        $this->assertRoute('POST', 'txapi/auth/login');
        $this->assertRoute('POST', 'txapi/orders/{tradeNo}/checkout', ['txapi.user']);
        $this->assertRoute('GET', 'api/v1/guest/payment/notify/{method}/{uuid}');
        $this->assertRoute('POST', 'api/v1/guest/payment/notify/{method}/{uuid}');
        $this->assertRoute('POST', 'txapi/node/v1/handshake', [\App\Http\Middleware\TxNodeAuth::class]);
        $this->assertRoute('POST', 'txapi/auth/admin/login');
        $this->assertRoute('GET', 'txapi/public/site-config');
        $this->assertRoute('GET', 'txapi/admin/{admin_path}/settings', ['admin.path', 'admin']);
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
