<?php

use App\Services\ThemeService;
use App\Services\VersionService;
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

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'TXBoard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'TXBoard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        $renderParams = [
            'title' => admin_setting('app_name', 'TXBoard'),
            'theme' => $theme,
            'version' => app(VersionService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'TXBoard control plane'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

// Version-matched public onboarding guide for external AI Agents. The guide
// contains no credentials and is intentionally available without an Admin session.
Route::get('/.well-known/txboard-agent-connect.md', static function () {
    $guidePath = resource_path('agent/txboard-agent-connect.md');
    if (!File::exists($guidePath)) {
        abort(404);
    }

    return response(File::get($guidePath), 200, [
        'Content-Type' => 'text/markdown; charset=UTF-8',
        'Cache-Control' => 'public, max-age=300',
        'X-Content-Type-Options' => 'nosniff',
    ]);
});

// Subscription links must win before the dynamic admin catch-all.
Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\SubscriptionController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');

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
            return response(File::get($indexPath), 200, [
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
