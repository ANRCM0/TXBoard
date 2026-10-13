<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Http\Controllers\Txapi\Admin\CommerceReadController;
use App\Models\Plan;
use Illuminate\Http\Request;

final class InternalAdminPlanListDispatcher
{
    public function execute(Request $request, \stdClass $input)
    {
        return app(InternalAdminAccess::class)->run($request, $input, function (Request $request) use ($input) {
            try {
                $native = app(CommerceReadController::class)->plans(Request::create('/txapi/admin/plans', 'GET',
                    ['page' => $input->page, 'per_page' => $input->per_page]));
                $envelope = $native->getData(true);
                $items = array_map(static function (array $row): array {
                    if (!is_array($row['prices'])) {
                        throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
                    }
                    $prices = [];
                    foreach ($row['prices'] as $period => $value) {
                        if (!array_key_exists($period, Plan::getAvailablePeriods())) {
                            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
                        }
                        if ($value !== null) {
                            if (is_int($value) || (is_float($value) && is_finite($value))) {
                                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                            }
                            if (!is_string($value) || strlen($value) > 128
                                || !preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]{1,4})?\z/D', $value)) {
                                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
                            }
                        }
                        $prices[$period] = $value;
                    }
                    return [
                        'id' => (string) $row['id'], 'name' => $row['name'], 'content' => $row['content'],
                        'group_id' => $row['group_id'] === null ? null : (string) $row['group_id'],
                        'group' => $row['group'] === null ? null : ['id' => (string) $row['group']['id'], 'name' => $row['group']['name']],
                        'traffic_limit_gib' => (string) $row['transfer_enable'],
                        'speed_limit_mbps' => $row['speed_limit'], 'device_limit' => $row['device_limit'],
                        'capacity_limit' => $row['capacity_limit'], 'reset_traffic_method' => $row['reset_traffic_method'],
                        'native_prices_major' => (object) $prices, 'tags' => $row['tags'],
                        'show' => $row['show'], 'sell' => $row['sell'], 'renew' => $row['renew'],
                        'sort' => $row['sort'], 'users_count' => $row['users_count'], 'active_users_count' => $row['active_users_count'],
                    ];
                }, $envelope['data']);
            } catch (\Throwable) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
            return TxapiResponse::success($request, ['items' => $items, 'pagination' => $envelope['meta']],
                ['current_only' => true, 'slice' => 'internal-admin-plan-list/1']);
        });
    }
}
