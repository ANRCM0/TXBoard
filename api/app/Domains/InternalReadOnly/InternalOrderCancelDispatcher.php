<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Http\Controllers\Txapi\BillingController;
use Illuminate\Http\Request;

/** Native service owns transaction, refund, coupon release and hook timing. */
final class InternalOrderCancelDispatcher
{
    public function execute(Request $request, \stdClass $input)
    {
        $nativeRequest = Request::create('/txapi/orders/'.rawurlencode($input->trade_no).'/cancel', 'POST');
        $nativeRequest->attributes->set('txapi.request_id', TxapiResponse::requestId($request));
        $response = app(BillingController::class)->cancelOrder($nativeRequest, $input->trade_no);
        if ($response->getStatusCode() !== 200) return $response;
        if ($response->getData(true)['data'] !== ['ok' => true]) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        return TxapiResponse::success($request, ['ok' => true],
            ['current_only' => true, 'slice' => 'internal-order-cancel/1']);
    }
}
