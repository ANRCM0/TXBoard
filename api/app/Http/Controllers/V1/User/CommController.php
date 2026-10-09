<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class CommController extends Controller
{
    public function config(\App\Services\SiteConfigService $service)
    {
        return $this->success($service->user());
    }

    public function getStripePublicKey(Request $request)
    {
        $payment = Payment::where('id', $request->input('id'))
            ->where('payment', 'StripeCredit')
            ->first();
        if (!$payment) throw new ApiException('payment is not found');
        return $this->success($payment->config['stripe_pk_live']);
    }
}
