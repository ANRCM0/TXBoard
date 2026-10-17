<?php

namespace App\Domains\Billing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Only an original Core dispatch can bind a provider callback to financial authority. */
final class PaymentNotificationProcessor
{
    /** @return array{status:int, body?:mixed, error?:string} */
    public function process(string $method, string $uuid, Request $request): array
    {
        try {
            $settlement = app(NativePaymentCheckout::class)->notify($method, $uuid, $request->input());
            if ($settlement === null) {
                return ['status' => 422, 'error' => 'unrecognized native payment'];
            }
            return ['status' => 200, 'body' => $settlement['acknowledgement']];
        } catch (\Throwable) {
            // Frozen dispatches include credentials; never log adapter exception context.
            Log::error('NATIVE_PAYMENT_NOTIFICATION_REJECTED');
            return ['status' => 500, 'error' => 'fail'];
        }
    }
}
