<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Domains\Account\AdminUserReader;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

final class InternalAdminUsersDispatcher
{
    public function execute(Request $request, array $operation)
    {
        $input = $operation['body']->input;
        return app(InternalAdminAccess::class)->run($request, $input, function (Request $request) use ($input, $operation) {
            try {
                $reader = app(AdminUserReader::class);
                if ($operation['operation'] === 'admin.user.get') {
                    $data = $this->dto($reader->find((int) $input->id), true);
                } else {
                    $params = ['page' => $input->page, 'per_page' => $input->per_page];
                    foreach (['banned', 'plan_id'] as $key) {
                        if (property_exists($input, $key)) $params[$key] = $key === 'plan_id' ? (int) $input->$key : $input->$key;
                    }
                    $page = $reader->paginate(Request::create('/txapi/admin/users', 'GET', $params));
                    $data = ['items' => $page->getCollection()->map(fn (User $user): array => $this->dto($user))->all(),
                        'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                            'total' => $page->total(), 'last_page' => $page->lastPage()]];
                }
            } catch (ModelNotFoundException $exception) {
                throw $exception;
            } catch (\Throwable) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
            return TxapiResponse::success($request, $data, ['current_only' => true, 'slice' => 'internal-admin-users/1']);
        });
    }

    private function dto(User $user, bool $detail = false): array
    {
        $integer = static function (mixed $value): string {
            $value ??= 0;
            if (is_int($value)) $value = (string) $value;
            if (!is_string($value) || !preg_match('/\A(?:0|-?[1-9][0-9]{0,18})\z/D', $value)
                || bccomp($value, '-9223372036854775808') < 0 || bccomp($value, '9223372036854775807') > 0) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
            return $value;
        };
        $nullableId = static fn (mixed $value): ?string => $value === null ? null : $integer($value);
        $date = static fn (string $key): ?int => $user->getRawOriginal($key) === null ? null : (int) $integer($user->getRawOriginal($key));
        $speed = $user->speed_limit === null ? null : (float) $user->speed_limit;
        if ($speed !== null && !is_finite($speed)) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        $data = [
            'id' => $integer($user->getRawOriginal('id')), 'email' => $user->email,
            'plan_id' => $nullableId($user->getRawOriginal('plan_id')), 'group_id' => $nullableId($user->getRawOriginal('group_id')),
            'plan' => $user->plan ? ['id' => $integer($user->plan->id), 'name' => $user->plan->name] : null,
            'group' => $user->group ? ['id' => $integer($user->group->id), 'name' => $user->group->name] : null,
            'balance_minor' => $integer($user->getRawOriginal('balance')), 'commission_balance_minor' => $integer($user->getRawOriginal('commission_balance')),
            'traffic' => ['upload_bytes' => $integer($user->getRawOriginal('u')), 'download_bytes' => $integer($user->getRawOriginal('d')),
                'limit_bytes' => $integer($user->getRawOriginal('transfer_enable'))],
            'expired_at' => $date('expired_at'), 'created_at' => (int) $integer($user->getRawOriginal('created_at')),
            'next_reset_at' => $date('next_reset_at'), 'last_reset_at' => $date('last_reset_at'), 'last_login_at' => $date('last_login_at'),
            'reset_count' => (int) $integer($user->getRawOriginal('reset_count')),
            'speed_limit_mbps' => $speed, 'device_limit' => $user->device_limit === null ? null : (int) $integer($user->getRawOriginal('device_limit')),
            'banned' => (bool) $user->banned, 'is_admin' => (bool) $user->is_admin, 'is_staff' => (bool) $user->is_staff,
            'remarks' => $user->remarks,
        ];
        if ($detail) $data['invite_user'] = $user->invite_user ? ['id' => $integer($user->invite_user->id), 'email' => $user->invite_user->email] : null;
        return $data;
    }
}
