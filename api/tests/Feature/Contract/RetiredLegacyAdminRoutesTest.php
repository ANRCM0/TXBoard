<?php

namespace Tests\Feature\Contract;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RetiredLegacyAdminRoutesTest extends TestCase
{
    /**
     * V2 public/subscriber/provider/Agent/Node paths have different contracts.
     * This is a zero-V2-ADMIN requirement, not a universal V2 protocol removal.
     */
    public function test_entire_v2_admin_route_tree_is_absent(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $obsolete = $routes->filter(static function ($route): bool {
            $uri = $route->uri();
            return str_starts_with($uri, 'api/v2/') &&
                (str_contains($uri, '{admin_path}') ||
                    str_contains($route->getActionName(), '\\Controllers\\V2\\Admin\\'));
        })->map(static fn ($route): string => $route->methods()[0] . ' ' . $route->uri())->values()->all();
        $this->assertSame([], $obsolete, 'Obsolete V2 admin endpoints must never be re-registered');

        foreach ([
            ['GET', 'txapi/admin/{admin_path}/users'],
            ['GET', 'txapi/admin/{admin_path}/orders'],
            ['GET', 'txapi/admin/{admin_path}/plans'],
            ['GET', 'txapi/admin/{admin_path}/payment-methods'],
            ['GET', 'txapi/admin/{admin_path}/coupons'],
            ['GET', 'txapi/admin/{admin_path}/tickets'],
            ['GET', 'txapi/admin/{admin_path}/audit-logs'],
            ['GET', 'txapi/admin/{admin_path}/queue/snapshot'],
            ['GET', 'txapi/admin/{admin_path}/settings'],
        ] as [$method, $uri]) {
            $found = $routes->first(static fn ($route): bool =>
                $route->uri() === $uri && in_array($method, $route->methods(), true));
            $this->assertNotNull($found, $uri . ' must retain the native admin implementation');
            $this->assertContains('admin.path', $found->gatherMiddleware());
            $this->assertContains('admin', $found->gatherMiddleware());
        }

        // Do not accidentally retire signed external Wire boundaries with admin routes.
        foreach (['txapi/agent/v1/whoami', 'txapi/payment/webhook/{method}/{uuid}',
            'txapi/node/v1/handshake'] as $uri) {
            $this->assertTrue($routes->contains(static fn ($route): bool => $route->uri() === $uri),
                $uri . ' is an external/runtime protocol, not an admin route');
        }
    }
}
