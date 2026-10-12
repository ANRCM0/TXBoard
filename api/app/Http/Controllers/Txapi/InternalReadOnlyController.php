<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Http\Controllers\Txapi\Admin\OrderAdminController;
use App\Http\Middleware\Admin;
use App\Http\Middleware\AdminPath;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class InternalReadOnlyController
{
    public function execute(Request $request)
    {
        $operation = $request->attributes->get('internal.readonly.operation');
        $input = $operation['body']->input;
        $version = $operation['body']->contract_version;
        $actor = Auth::guard('sanctum')->user();
        if ($operation['definition']['actor'] === 'user') {
            if ($actor->is_admin) {
                abort(403);
            }
            if ($operation['operation'] === 'user.dashboard.get') {
                $result = app(AccountController::class)->dashboardStats($request)->getData(true)['data'];
            } elseif ($operation['operation'] === 'user.payment-methods.list') {
                $result = app(BillingController::class)->methods($request)->getData(true)['data'];
                foreach ($result as &$method) {
                    $method['id'] = (string) $method['id'];
                    $method['fee_fixed_minor'] = (string) $method['fee_fixed_minor'];
                }
                unset($method);
            } elseif ($operation['operation'] === 'user.order.list') {
                // Use the existing owner/filter/order query. A fresh GET input bag
                // prevents the Internal envelope from becoming native query input.
                $native = app(AccountController::class)->orders(Request::create('/txapi/orders', 'GET', (array) $input));
                $envelope = $native->getData(true);
                $result = [
                    'items' => array_map(self::order(...), $envelope['data']),
                    'pagination' => $envelope['meta'],
                ];
            } else {
                $native = $operation['operation'] === 'user.profile.get'
                    ? app(AccountController::class)->me($request)
                    : ($version === '2'
                        ? app(AccountController::class)->orderDetail($request, $input->trade_no)
                        : app(AccountController::class)->order($request, $input->trade_no));
                $data = $native->getData(true)['data'];
                $result = $operation['operation'] === 'user.profile.get' ? self::profile($data) : self::order($data);
                if ($version === '2') {
                    if ($operation['operation'] === 'user.profile.get') {
                        $result['telegram_id'] = $data['telegram_id'] === null ? null : (string) $data['telegram_id'];
                    } else {
                        $result['plan']['traffic_limit_bytes'] = (string) $data['plan']['traffic_limit_bytes'];
                        $result['payment_id'] = $data['payment_id'] === null ? null : (string) $data['payment_id'];
                        $result['balance_amount_minor'] = (string) $data['balance_amount_minor'];
                        $result['discount_amount_minor'] = (string) $data['discount_amount_minor'];
                    }
                }
            }
        } else {
            // Existing Core admin role + dynamic path are the authority. No invented RBAC permissions.
            $request->route()->setParameter('admin_path', $input->admin_path);
            $request->route()->setParameter('id', $input->id);
            $native = app(AdminPath::class)->handle($request, fn ($request) =>
                app(Admin::class)->handle($request, fn ($request) => app(OrderAdminController::class)->detail($request)));
            if ($native->getStatusCode() !== 200) {
                abort(403);
            }
            $data = $native->getData(true)['data'];
            $data['amount_minor'] = $data['total_amount'];
            $result = self::order($data);
            $result['user_id'] = (string) $data['user_id'];
        }
        return TxapiResponse::success($request, $result, ['current_only' => true, 'slice' => 'internal-readonly/'.$version]);
    }

    private static function profile(array $data): array
    {
        return [
            'id' => (string) $data['id'], 'email' => $data['email'],
            'plan_id' => $data['plan_id'] === null ? null : (string) $data['plan_id'],
            'balance_minor' => (string) $data['balance_minor'],
            'commission_balance_minor' => (string) $data['commission_balance_minor'],
            'expired_at' => self::timestamp($data['expired_at']),
            'traffic' => [
                'upload_bytes' => (string) $data['traffic']['upload_bytes'],
                'download_bytes' => (string) $data['traffic']['download_bytes'],
                'limit_bytes' => (string) $data['traffic']['limit_bytes'],
            ],
        ];
    }

    private static function order(array $data): array
    {
        return [
            'id' => (string) $data['id'], 'trade_no' => $data['trade_no'],
            'plan_id' => (string) $data['plan_id'], 'period' => PlanService::getPeriodKey($data['period']),
            'type' => $data['type'], 'status' => $data['status'], 'amount_minor' => (string) $data['amount_minor'],
            'plan' => $data['plan'] === null ? null : ['id' => (string) $data['plan']['id'], 'name' => $data['plan']['name']],
            'created_at' => self::timestamp($data['created_at']), 'paid_at' => self::timestamp($data['paid_at']),
        ];
    }

    private static function timestamp(mixed $value): ?int
    {
        return $value === null ? null : (is_int($value) ? $value : strtotime($value));
    }
}
