<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StripeConfigController
{
    public function publicKey(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $payment = Payment::query()->whereKey($data['id'])
            ->where('payment', 'StripeCredit')->where('enable', true)->first();
        if (!$payment) {
            return TxapiResponse::error($request, 'NOT_FOUND', 'Payment method unavailable', 404);
        }
        $key = $payment->config['stripe_pk_live'] ?? null;
        if (!is_string($key) || $key === '') {
            return TxapiResponse::error($request, 'NOT_FOUND', 'Public key unavailable', 404);
        }
        return TxapiResponse::success($request, $key);
    }
}
