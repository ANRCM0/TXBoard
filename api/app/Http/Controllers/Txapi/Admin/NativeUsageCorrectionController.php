<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\UsageV2\NativeUsageCorrections;
use App\Domains\UsageV2\UsageError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeUsageCorrectionController
{
    public function entries(Request $request, NativeUsageCorrections $corrections): JsonResponse
    {
        $input = $this->page($request);
        return $this->respond($request, fn () => $corrections->entries((int) $request->user()->id, (int) $request->route('id'), $input['before'] ?? null, (int) ($input['limit'] ?? 25)));
    }
    public function history(Request $request, NativeUsageCorrections $corrections): JsonResponse
    {
        $input = $this->page($request);
        return $this->respond($request, fn () => $corrections->history((int) $request->user()->id, (int) $request->route('id'), $input['before'] ?? null, (int) ($input['limit'] ?? 25)));
    }
    public function create(Request $request, NativeUsageCorrections $corrections): JsonResponse
    {
        $fields = ['entry_id', 'expected_entry_revision', 'expected_period_version', 'billed_up_bytes', 'billed_down_bytes', 'reason'];
        if (array_diff(array_keys($request->all()), $fields)) return TxapiResponse::error($request, 'USAGE_CORRECTION_INPUT_INVALID', 'Unsupported correction fields', 422);
        $input = $request->validate(['entry_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'expected_entry_revision' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
            'expected_period_version' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'billed_up_bytes' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,18})\z/D'],
            'billed_down_bytes' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,18})\z/D'], 'reason' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D']]);
        return $this->respond($request, fn () => $corrections->create((int) $request->user()->id, (int) $request->route('id'), $input, (string) $request->header('Idempotency-Key')));
    }
    private function page(Request $request): array { return $request->validate(['before' => ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]); }
    private function respond(Request $request, \Closure $operation): JsonResponse
    {
        try { return TxapiResponse::success($request, $operation())->header('Cache-Control', 'private, no-store'); }
        catch (UsageError $error) { return TxapiResponse::error($request, $error->errorCode, 'Usage correction refused', $error->httpStatus)->header('Cache-Control', 'private, no-store'); }
        catch (\DomainException $error) { return TxapiResponse::error($request, 'USAGE_CORRECTION_BOUNDARY_CONFLICT', 'Usage correction refused', 409)->header('Cache-Control', 'private, no-store'); }
    }
}
