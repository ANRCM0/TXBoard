<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsagePolicy;
use App\Domains\UsageV2\UsageReceipts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NodeUsageV2Controller
{
    public function submit(Request $request, UsagePolicy $policy, UsagePayload $parser, UsageReceipts $receipts): JsonResponse
    {
        try {
            $policy->requireNative();
            return TxapiResponse::success($request, $receipts->submit(
                (int) $request->attributes->get('txnode.node')->id, $parser->submission($request->getContent()), time()
            ))->header('Cache-Control', 'no-store');
        } catch (UsageError $error) {
            return TxapiResponse::error($request, $error->errorCode, $error->errorCode, $error->httpStatus)->header('Cache-Control', 'no-store');
        }
    }

    public function get(Request $request, UsagePolicy $policy, UsagePayload $parser, UsageReceipts $receipts): JsonResponse
    {
        try {
            $policy->requireNative();
            return TxapiResponse::success($request, $receipts->query(
                (int) $request->attributes->get('txnode.node')->id, $parser->query($request->getContent())
            ))->header('Cache-Control', 'no-store');
        } catch (UsageError $error) {
            return TxapiResponse::error($request, $error->errorCode, $error->errorCode, $error->httpStatus)->header('Cache-Control', 'no-store');
        }
    }
}
