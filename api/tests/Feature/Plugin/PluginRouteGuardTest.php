<?php

namespace Tests\Feature\Plugin;

use App\Http\Middleware\EnsurePluginEnabled;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PluginRouteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_plugin_guard_immediately_revokes_disabled_plugin(): void
    {
        $plugin = Plugin::create([
            'name' => 'Route Guard Test',
            'code' => 'phase3_route_fixture',
            'version' => '1.0.0',
            'type' => Plugin::TYPE_FEATURE,
            'is_enabled' => true,
            'installed_at' => now(),
        ]);

        $guard = app(EnsurePluginEnabled::class);
        $request = Request::create('/_phase3_plugin_route_fixture', 'GET');
        $next = static fn () => response()->json(['ok' => true]);

        $response = $guard->handle($request, $next, 'phase3_route_fixture');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], $response->getData(true));

        $plugin->update(['is_enabled' => false]);
        $this->expectException(NotFoundHttpException::class);
        $guard->handle($request, $next, 'phase3_route_fixture');
    }

    public function test_missing_or_uninstalled_plugin_is_denied(): void
    {
        $guard = app(EnsurePluginEnabled::class);
        $this->expectException(NotFoundHttpException::class);
        $guard->handle(
            Request::create('/_phase3_uninstalled_route_fixture', 'GET'),
            static fn () => response('unsafe'),
            'uninstalled_plugin',
        );
    }

    public function test_invalid_plugin_identity_is_denied(): void
    {
        $guard = app(EnsurePluginEnabled::class);
        $this->expectException(NotFoundHttpException::class);
        $guard->handle(
            Request::create('/_phase3_invalid_plugin_route_fixture', 'GET'),
            static fn () => response('unsafe'),
            '../invalid_plugin',
        );
    }
}
