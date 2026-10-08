<?php

namespace Tests\Feature\Plugin;

use App\Http\Middleware\EnsurePluginEnabled;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PluginRouteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_registered_in_memory_is_denied_after_disabling_plugin(): void
    {
        $plugin = Plugin::create([
            'name' => 'Route Guard Test',
            'code' => 'phase3_route_fixture',
            'version' => '1.0.0',
            'type' => Plugin::TYPE_FEATURE,
            'is_enabled' => true,
            'installed_at' => now(),
        ]);
        Route::middleware([EnsurePluginEnabled::class . ':phase3_route_fixture'])
            ->get('/_phase3_plugin_route_fixture', static fn () => response()->json(['ok' => true]));

        $this->getJson('/_phase3_plugin_route_fixture')->assertOk()->assertJsonPath('ok', true);

        $plugin->update(['is_enabled' => false]);
        $this->getJson('/_phase3_plugin_route_fixture')->assertNotFound();
    }

    public function test_missing_or_uninstalled_plugin_route_is_denied(): void
    {
        Route::middleware([EnsurePluginEnabled::class . ':uninstalled_plugin'])
            ->get('/_phase3_uninstalled_route_fixture', static fn () => response('unsafe'));

        $this->get('/_phase3_uninstalled_route_fixture')->assertNotFound();
    }
}
