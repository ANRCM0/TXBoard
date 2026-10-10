<?php

namespace Tests\Feature\Contract;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The development API is TXAPI only: V1/V2 may not return under any route provider. */
class RetiredLegacyUserRoutesTest extends TestCase
{
    public function test_no_v1_or_v2_routes_are_registered(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('~^api/v[12](?:/|$)~', $route->uri(),
                'A deprecated Xboard route was re-registered: ' . $route->uri());
        }
    }

    public function test_all_required_native_replacements_are_registered(): void
    {
        $paths = array_map(static fn ($route) => $route->uri(), Route::getRoutes()->getRoutes());
        foreach (['txapi/auth/login', 'txapi/auth/register', 'txapi/public/site-config',
            'txapi/me', 'txapi/plans', 'txapi/knowledge/categories',
            'txapi/orders', 'txapi/billing/wallet', 'txapi/payment/webhook/{method}/{uuid}',
            'txapi/agent/v1/whoami', 'txapi/integrations/telegram/webhook',
            'txapi/node/v1/handshake'] as $path) {
            $this->assertContains($path, $paths, 'Required native route missing: ' . $path);
        }
    }
}
