<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\AdminCouponSafety;
use App\Models\Coupon;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CouponAdminController
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'code' => ['sometimes', 'nullable', 'string', 'max:128'],
            'type' => ['sometimes', 'integer', 'in:1,2'],
        ]);
        $query = Coupon::query();
        if (!empty($input['code'])) {
            $query->where('code', 'like', '%' . $input['code'] . '%');
        }
        if (isset($input['type'])) {
            $query->where('type', (int) $input['type']);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($input['per_page'] ?? 10), ['*'], 'page', (int) ($input['page'] ?? 1));

        return TxapiResponse::success($request,
            $page->getCollection()->map(static fn (Coupon $c): array => self::dto($c))->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
             'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = self::validateCoupon($request);
        $routeId = $request->route('id');
        $creating = $routeId === null;
        $id = DB::transaction(static function () use ($data, $routeId): int {
            if ($routeId === null) {
                $attributes = $data;
                $attributes['code'] = trim((string) ($data['code'] ?? '')) ?: Helper::randomChar(12);
                $attributes['started_at'] = $data['started_at'] ?? 0;
                $attributes['ended_at'] = $data['ended_at'] ?? 0;
                if (Coupon::query()->where('code', $attributes['code'])->exists()) {
                    throw ValidationException::withMessages(['code' => 'Coupon code already exists']);
                }
                return (int) Coupon::query()->create($attributes)->id;
            }
            $coupon = Coupon::query()->lockForUpdate()->findOrFail((int) $routeId);
            if (isset($data['code']) && $data['code'] !== '' && $data['code'] !== $coupon->code) {
                throw ValidationException::withMessages(['code' => 'Coupon code cannot be modified']);
            }
            unset($data['code']);
            foreach (['started_at', 'ended_at'] as $key) {
                if (array_key_exists($key, $data) && $data[$key] === null) {
                    $data[$key] = 0;
                }
            }
            $coupon->fill($data)->saveOrFail();
            return (int) $coupon->id;
        });

        return TxapiResponse::success($request, ['id' => $id], status: $creating ? 201 : 200);
    }

    public function toggle(Request $request): JsonResponse
    {
        $visible = DB::transaction(static function () use ($request): bool {
            $coupon = Coupon::query()->lockForUpdate()->findOrFail((int) $request->route('id'));
            $coupon->show = !$coupon->show;
            $coupon->saveOrFail();
            return (bool) $coupon->show;
        });
        return TxapiResponse::success($request, ['show' => $visible]);
    }

    public function delete(Request $request, AdminCouponSafety $safety): JsonResponse
    {
        if (!$safety->deleteUnused((int) $request->route('id'))) {
            return TxapiResponse::error($request, 'COUPON_IN_USE',
                'Coupon has order history; disable it instead', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    /**
     * A CSV download deliberately isn't JSON-wrapped. Insert and render only
     * bounded generated codes, never serialize an order or user's data.
     */
    public function generateCsv(Request $request): StreamedResponse
    {
        $data = self::validateCoupon($request, true);
        $count = (int) $data['generate_count'];
        unset($data['generate_count'], $data['code']);
        $now = time();
        $data['started_at'] = $data['started_at'] ?? 0;
        $data['ended_at'] = $data['ended_at'] ?? 0;
        $data['show'] = true;
        $data['created_at'] = $data['updated_at'] = $now;

        $rows = [];
        $used = [];
        for ($i = 0; $i < $count; $i++) {
            do {
                $code = Helper::randomChar(12);
            } while (isset($used[$code]));
            $used[$code] = true;
            $rows[] = $data + ['code' => $code];
        }
        DB::transaction(static function () use ($rows): void {
            $insert = array_map(static function (array $row): array {
                foreach (['limit_plan_ids', 'limit_period'] as $key) {
                    if (isset($row[$key]) && is_array($row[$key])) {
                        $row[$key] = json_encode($row[$key]);
                    }
                }
                return $row;
            }, $rows);
            Coupon::query()->insert($insert);
        });

        return response()->streamDownload(static function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['名称', '类型', '金额或比例', '开始时间', '结束时间',
                '可用次数', '可用于订阅', '券码', '生成时间']);
            foreach ($rows as $coupon) {
                fputcsv($out, [
                    self::safeCsvText((string) $coupon['name']),
                    $coupon['type'] === 1 ? '金额' : '比例',
                    $coupon['type'] === 1
                        ? number_format($coupon['value'] / 100, 2, '.', '')
                        : (string) $coupon['value'],
                    $coupon['started_at'] ? date('Y-m-d H:i:s', $coupon['started_at']) : '不限',
                    $coupon['ended_at'] ? date('Y-m-d H:i:s', $coupon['ended_at']) : '不限',
                    $coupon['limit_use'] ?? '不限制',
                    !empty($coupon['limit_plan_ids'])
                        ? implode('/', $coupon['limit_plan_ids']) : '不限制',
                    $coupon['code'],
                    date('Y-m-d H:i:s', $coupon['created_at']),
                ]);
            }
            fclose($out);
        }, 'coupons.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Request-Id' => TxapiResponse::requestId($request),
        ]);
    }

    private static function validateCoupon(Request $request, bool $batch = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:128'],
            'type' => ['required', 'integer', 'in:1,2'],
            'value' => ['required', 'integer', 'min:1'],
            'limit_use' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limit_use_with_user' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limit_plan_ids' => ['sometimes', 'nullable', 'array', 'max:15'],
            'limit_plan_ids.*' => ['integer', 'min:1', 'distinct'],
            'limit_period' => ['sometimes', 'nullable', 'array', 'max:8'],
            'limit_period.*' => ['string', 'max:32', 'distinct'],
            'started_at' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ended_at' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
        if ($batch) {
            $rules['generate_count'] = ['required', 'integer', 'min:1', 'max:500'];
        }
        $data = $request->validate($rules);
        if ((int) $data['type'] === 2 && (int) $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'Percent discount cannot exceed 100']);
        }
        if (($data['started_at'] ?? 0) > 0 && ($data['ended_at'] ?? 0) > 0
            && $data['ended_at'] <= $data['started_at']) {
            throw ValidationException::withMessages(['ended_at' => 'End time must be after start time']);
        }
        return $data;
    }

    private static function safeCsvText(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        return preg_match('/^\s*[=+\-@]/u', $text) ? "'" . $text : $text;
    }

    private static function dto(Coupon $coupon): array
    {
        return [
            'id' => (int) $coupon->id,
            'name' => (string) $coupon->name,
            'code' => (string) $coupon->code,
            'type' => (int) $coupon->type,
            'value' => (int) $coupon->value,
            'limit_use' => $coupon->limit_use === null ? null : (int) $coupon->limit_use,
            'limit_use_with_user' => $coupon->limit_use_with_user === null
                ? null : (int) $coupon->limit_use_with_user,
            'limit_plan_ids' => $coupon->limit_plan_ids ?? [],
            'limit_period' => $coupon->limit_period ?? [],
            'show' => (bool) $coupon->show,
            'started_at' => (int) ($coupon->started_at ?? 0),
            'ended_at' => (int) ($coupon->ended_at ?? 0),
            'created_at' => $coupon->created_at,
            'updated_at' => $coupon->updated_at,
        ];
    }
}
