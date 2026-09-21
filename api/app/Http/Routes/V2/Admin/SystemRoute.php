<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\MailTemplateController;
use App\Http\Controllers\V2\Admin\SystemController;
use App\Http\Controllers\V2\Admin\TrafficResetController;
use Illuminate\Contracts\Routing\Registrar;

class SystemRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'config'], function (Registrar $router): void {
            $router->get('/fetch', [ConfigController::class, 'fetch']);
            $router->post('/save', [ConfigController::class, 'save']);
            $router->get('/getEmailTemplate', [ConfigController::class, 'getEmailTemplate']);
            $router->get('/getThemeTemplate', [ConfigController::class, 'getThemeTemplate']);
            $router->post('/setTelegramWebhook', [ConfigController::class, 'setTelegramWebhook']);
            $router->post('/testSendMail', [ConfigController::class, 'testSendMail']);
        });

        $router->group(['prefix' => 'mail/template'], function (Registrar $router): void {
            $router->get('/list', [MailTemplateController::class, 'list']);
            $router->get('/get', [MailTemplateController::class, 'get']);
            $router->post('/save', [MailTemplateController::class, 'save']);
            $router->post('/reset', [MailTemplateController::class, 'reset']);
            $router->post('/test', [MailTemplateController::class, 'test']);
        });

        $router->group(['prefix' => 'system'], function (Registrar $router): void {
            $router->get('/getSystemStatus', [SystemController::class, 'getSystemStatus']);
            $router->get('/getQueueStats', [SystemController::class, 'getQueueStats']);
            $router->get('/getQueueWorkload', [SystemController::class, 'getQueueWorkload']);
            $router->get('/getQueueMasters', [SystemController::class, 'getQueueMasters']);
            $router->get('/getHorizonFailedJobs', [SystemController::class, 'getHorizonFailedJobs']);
            $router->any('/getAuditLog', [SystemController::class, 'getAuditLog']);
        });

        $router->group(['prefix' => 'traffic-reset'], function (Registrar $router): void {
            $router->get('logs', [TrafficResetController::class, 'logs']);
            $router->get('stats', [TrafficResetController::class, 'stats']);
            $router->get('user/{userId}/history', [TrafficResetController::class, 'userHistory']);
            $router->post('reset-user', [TrafficResetController::class, 'resetUser']);
        });
    }
}
