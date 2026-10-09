<?php

namespace App\Http\Controllers\V1\Guest;

use App\Domains\Billing\PaymentNotificationProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        $result = app(PaymentNotificationProcessor::class)
            ->process((string) $method, (string) $uuid, $request);

        if ($result['status'] !== 200) {
            return $this->fail([$result['status'], $result['error']]);
        }
        // Provider ACK must remain byte-for-byte compatible with the existing
        // V1 route even though verification now lives in a shared domain.
        return $result['body'];
    }
}
