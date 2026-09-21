<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\CouponController;
use App\Http\Controllers\V2\Admin\GiftCardController;
use App\Http\Controllers\V2\Admin\OrderController;
use App\Http\Controllers\V2\Admin\PaymentController;
use App\Http\Controllers\V2\Admin\PlanController;
use Illuminate\Contracts\Routing\Registrar;

class CommerceRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'plan'], function (Registrar $router): void {
            $router->get('/fetch', [PlanController::class, 'fetch']);
            $router->post('/save', [PlanController::class, 'save']);
            $router->post('/drop', [PlanController::class, 'drop']);
            $router->post('/update', [PlanController::class, 'update']);
            $router->post('/sort', [PlanController::class, 'sort']);
        });

        $router->group(['prefix' => 'order'], function (Registrar $router): void {
            $router->any('/fetch', [OrderController::class, 'fetch']);
            $router->post('/update', [OrderController::class, 'update']);
            $router->post('/assign', [OrderController::class, 'assign']);
            $router->post('/paid', [OrderController::class, 'paid']);
            $router->post('/cancel', [OrderController::class, 'cancel']);
            $router->post('/detail', [OrderController::class, 'detail']);
        });

        $router->group(['prefix' => 'coupon'], function (Registrar $router): void {
            $router->any('/fetch', [CouponController::class, 'fetch']);
            $router->post('/generate', [CouponController::class, 'generate']);
            $router->post('/drop', [CouponController::class, 'drop']);
            $router->post('/show', [CouponController::class, 'show']);
            $router->post('/update', [CouponController::class, 'update']);
        });

        $router->group(['prefix' => 'gift-card'], function (Registrar $router): void {
            $router->any('/templates', [GiftCardController::class, 'templates']);
            $router->post('/create-template', [GiftCardController::class, 'createTemplate']);
            $router->post('/update-template', [GiftCardController::class, 'updateTemplate']);
            $router->post('/delete-template', [GiftCardController::class, 'deleteTemplate']);
            $router->post('/generate-codes', [GiftCardController::class, 'generateCodes']);
            $router->any('/codes', [GiftCardController::class, 'codes']);
            $router->post('/toggle-code', [GiftCardController::class, 'toggleCode']);
            $router->get('/export-codes', [GiftCardController::class, 'exportCodes']);
            $router->post('/update-code', [GiftCardController::class, 'updateCode']);
            $router->post('/delete-code', [GiftCardController::class, 'deleteCode']);
            $router->any('/usages', [GiftCardController::class, 'usages']);
            $router->any('/statistics', [GiftCardController::class, 'statistics']);
            $router->get('/types', [GiftCardController::class, 'types']);
        });

        $router->group(['prefix' => 'payment'], function (Registrar $router): void {
            $router->get('/fetch', [PaymentController::class, 'fetch']);
            $router->get('/getPaymentMethods', [PaymentController::class, 'getPaymentMethods']);
            $router->post('/getPaymentForm', [PaymentController::class, 'getPaymentForm']);
            $router->post('/save', [PaymentController::class, 'save']);
            $router->post('/drop', [PaymentController::class, 'drop']);
            $router->post('/show', [PaymentController::class, 'show']);
            $router->post('/sort', [PaymentController::class, 'sort']);
        });
    }
}
