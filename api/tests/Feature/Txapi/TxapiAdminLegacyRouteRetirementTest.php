<?php

namespace Tests\Feature\Txapi;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TxapiAdminLegacyRouteRetirementTest extends TestCase
{
    public function test_native_content_and_settings_replace_tx_admin_routes_without_redirect_shims(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route) => $route->uri())->all();

        foreach (['notice/fetch', 'notice/save', 'notice/update', 'notice/drop',
            'notice/show', 'notice/sort', 'knowledge/fetch', 'knowledge/getCategory',
            'knowledge/save', 'knowledge/show', 'knowledge/drop', 'knowledge/sort',
            'config/fetch', 'config/save', 'config/getEmailTemplate',
            'config/getThemeTemplate', 'config/setTelegramWebhook',
            'config/testSendMail', 'mail/template/list', 'mail/template/get',
            'mail/template/save', 'mail/template/reset', 'mail/template/test',
            'traffic-reset/logs', 'traffic-reset/stats', 'traffic-reset/user/{userId}/history',
            'traffic-reset/reset-user'] as $legacy) {
            $this->assertNotContains('api/v2/{admin_path}/' . $legacy, $routes);
        }

        foreach (['txapi/public/site-config', 'txapi/auth/admin/login',
            'txapi/admin/{admin_path}/content/notices',
            'txapi/admin/{admin_path}/content/knowledge',
            'txapi/admin/{admin_path}/settings',
            'txapi/admin/{admin_path}/mail-templates',
            'txapi/admin/{admin_path}/traffic-resets'] as $native) {
            $this->assertContains($native, $routes);
        }
    }
}
