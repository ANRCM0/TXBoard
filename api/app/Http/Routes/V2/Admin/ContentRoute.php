<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\KnowledgeController;
use App\Http\Controllers\V2\Admin\NoticeController;
use Illuminate\Contracts\Routing\Registrar;

class ContentRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'notice'], function (Registrar $router): void {
            $router->get('/fetch', [NoticeController::class, 'fetch']);
            $router->post('/save', [NoticeController::class, 'save']);
            $router->post('/update', [NoticeController::class, 'update']);
            $router->post('/drop', [NoticeController::class, 'drop']);
            $router->post('/show', [NoticeController::class, 'show']);
            $router->post('/sort', [NoticeController::class, 'sort']);
        });

        $router->group(['prefix' => 'knowledge'], function (Registrar $router): void {
            $router->get('/fetch', [KnowledgeController::class, 'fetch']);
            $router->get('/getCategory', [KnowledgeController::class, 'getCategory']);
            $router->post('/save', [KnowledgeController::class, 'save']);
            $router->post('/show', [KnowledgeController::class, 'show']);
            $router->post('/drop', [KnowledgeController::class, 'drop']);
            $router->post('/sort', [KnowledgeController::class, 'sort']);
        });
    }
}
