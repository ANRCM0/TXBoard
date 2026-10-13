<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Http\Controllers\Txapi\Admin\CommerceReadController;
use App\Services\PlanService;
use Illuminate\Http\Request;

final class InternalAdminOrderListDispatcher
{
    public function execute(Request $request, \stdClass $input)
    {
        return app(InternalAdminAccess::class)->run($request, $input, function (Request $request) use ($input) {
            $params = ['page' => $input->page, 'per_page' => $input->per_page];
            if (property_exists($input, 'status')) $params['status'] = $input->status;
            try {
                $native = app(CommerceReadController::class)->orders(Request::create('/txapi/admin/orders', 'GET', $params));
                $envelope = $native->getData(true);
            } catch (\Throwable) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
            $items = array_map(static fn (array $row): array => [
                'id' => (string) $row['id'], 'trade_no' => $row['trade_no'],
                'user_id' => (string) $row['user_id'], 'plan_id' => (string) $row['plan_id'],
                'plan' => $row['plan'] === null ? null : ['id' => (string) $row['plan']['id'], 'name' => $row['plan']['name']],
                'user' => $row['user'] === null ? null : ['id' => (string) $row['user']['id'], 'email' => $row['user']['email']],
                'period' => PlanService::getPeriodKey($row['period']), 'type' => $row['type'], 'status' => $row['status'],
                'amount_minor' => (string) $row['total_amount'], 'handling_amount_minor' => (string) $row['handling_amount'],
                'balance_amount_minor' => (string) $row['balance_amount'], 'discount_amount_minor' => (string) $row['discount_amount'],
                'paid_at' => $row['paid_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ], $envelope['data']);
            return TxapiResponse::success($request, ['items' => $items, 'pagination' => $envelope['meta']],
                ['current_only' => true, 'slice' => 'internal-admin-order-list/1']);
        });
    }
}
