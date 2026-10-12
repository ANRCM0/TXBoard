<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\Content\ContentReader;
use App\Http\Controllers\Txapi\Admin\OrderAdminController;
use App\Http\Middleware\Admin;
use App\Http\Middleware\AdminPath;
use App\Services\PlanService;
use App\Services\UserService;
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
            if ($operation['operation'] === 'user.order.create') {
                $params = ['plan_id' => (int) $input->plan_id, 'period' => $input->period];
                if (property_exists($input, 'coupon_code')) $params['coupon_code'] = $input->coupon_code;
                $nativeRequest = Request::create('/txapi/orders', 'POST', $params);
                $nativeRequest->headers->set('Idempotency-Key', $input->idempotency_key);
                $nativeRequest->attributes->set('txapi.request_id', TxapiResponse::requestId($request));
                $native = app(BillingController::class)->createOrder($nativeRequest);
                if ($native->getStatusCode() !== 201) return $native;
                return TxapiResponse::success($request, $native->getData(true)['data'],
                    ['current_only' => true, 'slice' => 'internal-order-write/1'], 201);
            } elseif ($operation['operation'] === 'user.order-status.get') {
                $data = app(AccountController::class)->order($request, $input->trade_no)->getData(true)['data'];
                $result = ['trade_no' => $data['trade_no'], 'status' => $data['status']];
            } elseif ($operation['operation'] === 'user.subscription-summary.get') {
                $native = app(AccountController::class)->subscription($request, app(UserService::class));
                if ($native->getStatusCode() !== 200) {
                    return $native;
                }
                $data = $native->getData(true)['data'];
                // Closed display projection. The native private subscription URL
                // remains inside Core and never enters this Internal response.
                $result = [
                    'plan' => $data['plan'] === null ? null : [
                        'id' => (string) $data['plan']['id'], 'name' => $data['plan']['name'],
                        'traffic_limit_bytes' => (string) $data['plan']['traffic_limit_bytes'],
                    ],
                    'reset_day' => $data['reset_day'], 'upload_bytes' => (string) $data['upload_bytes'],
                    'download_bytes' => (string) $data['download_bytes'],
                    'traffic_limit_bytes' => (string) $data['traffic_limit_bytes'],
                    'device_limit' => $data['device_limit'] === null ? null : (string) $data['device_limit'],
                    'speed_limit_mbps' => $data['speed_limit_mbps'],
                    'expired_at' => self::timestamp($data['expired_at']),
                    'next_reset_at' => self::timestamp($data['next_reset_at']),
                ];
            } elseif ($operation['operation'] === 'user.notice.list') {
                $native = app(ContentController::class)->notices(Request::create('/txapi/notices', 'GET', (array) $input), app(ContentReader::class));
                $envelope = $native->getData(true);
                $result = ['items' => array_map(static fn (array $row): array => [
                    'id' => (string) $row['id'], 'title' => $row['title'], 'content' => $row['content'],
                    'img_url' => $row['img_url'], 'tags' => $row['tags'], 'created_at' => self::timestamp($row['created_at']),
                ], $envelope['data']), 'pagination' => $envelope['meta']];
            } elseif ($operation['operation'] === 'user.dashboard.get') {
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
