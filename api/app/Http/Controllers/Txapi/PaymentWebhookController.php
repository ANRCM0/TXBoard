<?php

namespace App\Http\Controllers\Txapi;

use App\Domains\Billing\PaymentNotificationProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PaymentWebhookController
{
    public function notify(string $method, string $uuid, Request $request,
        PaymentNotificationProcessor $processor): Response
    {
        $result = $processor->process($method, $uuid, $request);
        if ($result['status'] !== 200) {
            // Do not leak gateway configuration, order existence or errors.
            return response('fail', $result['status']);
        }
        // Payment providers require the raw ACK, not the standard JSON envelope.
        return response((string) ($result['body'] ?? 'success'), 200);
    }
}
