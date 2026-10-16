<?php

use App\Services\ThemeService;
use App\Services\AdminFavicon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $themeService = app(ThemeService::class);
    $theme = $themeService->getActiveTheme();

    // v2 themes are isolated, validated static SPAs; they never enter Blade.
    if (app(\App\Services\Theme\ThemeV2Runtime::class)->isInstalled($theme)) {
        try {
            return app(\App\Services\Theme\ThemeV2Runtime::class)->render($theme);
        } catch (\Throwable $e) {
            Log::error('Theme v2 SPA rendering failed', ['theme' => $theme]);
            abort(503, '主题资源或运行时版本不可用');
        }
    }

    // TXBoard's built-in theme is the native Vue user SPA.
    if ($theme === 'TXBoard') {
        $candidates = [
            '/srv/user/index.html',
            base_path('../web/user/dist/index.html'),
            base_path('../web/user/index.html'),
        ];
        foreach ($candidates as $indexPath) {
            if (File::isFile($indexPath)) {
                return response(File::get($indexPath), 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Cache-Control' => 'no-store, private',
                ]);
            }
        }
        Log::error('User SPA index is missing', ['candidates' => $candidates]);
        abort(503, '用户前台资源不可用');
    }

    abort(503, 'Theme resources unavailable');
});

/*
|--------------------------------------------------------------------------
| Runtime admin SPA entry
|--------------------------------------------------------------------------
|
| The admin bundle is built once, but its browser path is deliberately not
| fixed at build time. Caddy forwards non-user-SPA paths here; AdminPath then
| compares {admin_path} with the live secure_path on every request.
|
| This makes /admin and stale secure paths return 404, while a rotated path is
| usable immediately without rebuilding the frontend, regenerating routes, or
| reloading Caddy/Octane.
|
*/
$serveAdminSpa = static function (Request $request, string $admin_path, ?string $admin_route = null) {
    // /srv/admin is the production image location. The checkout fallbacks keep
    // feature tests and local development able to exercise the entry route.
    $candidates = [
        '/srv/admin/index.html',
        base_path('../web/admin/dist/index.html'),
        base_path('../web/admin/index.html'),
    ];

    foreach ($candidates as $indexPath) {
        if (File::exists($indexPath)) {
            return response(AdminFavicon::inject(File::get($indexPath), admin_setting('admin_favicon_url')), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ]);
        }
    }

    Log::error('Admin SPA index is missing', ['candidates' => $candidates]);
    abort(503, '管理后台资源不可用');
};

Route::get('/{admin_path}/{admin_route?}', $serveAdminSpa)
    ->where('admin_path', '[A-Za-z0-9_-]+')
    ->where('admin_route', '.*')
    ->middleware('admin.path');
