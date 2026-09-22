<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\ModuleController;
use App\Http\Controllers\V2\Admin\PluginController;
use App\Http\Controllers\V2\Admin\ThemeController;
use Illuminate\Contracts\Routing\Registrar;

class ExtensionRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'module'], function (Registrar $router): void {
            $router->get('/', [ModuleController::class, 'index']);
            $router->get('/{id}', [ModuleController::class, 'show']);
        });

        $router->group(['prefix' => 'theme'], function (Registrar $router): void {
            $router->get('/getThemes', [ThemeController::class, 'getThemes']);
            $router->post('/upload', [ThemeController::class, 'upload']);
            $router->post('/delete', [ThemeController::class, 'delete']);
            $router->post('/saveThemeConfig', [ThemeController::class, 'saveThemeConfig']);
            $router->post('/getThemeConfig', [ThemeController::class, 'getThemeConfig']);
        });

        $router->group(['prefix' => 'plugin'], function (Registrar $router): void {
            $router->get('/types', [PluginController::class, 'types']);
            $router->get('/getPlugins', [PluginController::class, 'index']);
            $router->post('/upload', [PluginController::class, 'upload']);
            $router->post('/delete', [PluginController::class, 'delete']);
            $router->post('install', [PluginController::class, 'install']);
            $router->post('uninstall', [PluginController::class, 'uninstall']);
            $router->post('enable', [PluginController::class, 'enable']);
            $router->post('disable', [PluginController::class, 'disable']);
            $router->get('config', [PluginController::class, 'getConfig']);
            $router->post('config', [PluginController::class, 'updateConfig']);
            $router->post('upgrade', [PluginController::class, 'upgrade']);
        });
    }
}
