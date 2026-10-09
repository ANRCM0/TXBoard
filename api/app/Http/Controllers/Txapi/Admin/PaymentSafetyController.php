<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\AdminPaymentSafety;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentSafetyController
{
    public function delete(Request $request, AdminPaymentSafety $service): JsonResponse
    {
        if (!$service->deleteUnused((int) $request->route('id'))) {
            return TxapiResponse::error($request, 'PAYMENT_IN_USE',
                'Payment method has order or wallet recharge history; disable it instead', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }
}
