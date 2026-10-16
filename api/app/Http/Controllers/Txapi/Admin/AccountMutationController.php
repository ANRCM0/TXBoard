<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Identity\AdminAccountModeration;
use App\Domains\Identity\AdminAccountProtection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AccountMutationController
{
    public function rotate(Request $request, AdminAccountProtection $accounts): JsonResponse
    {
        $accounts->rotateCredentials((int) $request->route('id'), $request);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function delete(Request $request, AdminAccountProtection $accounts): JsonResponse
    {
        $id = (int) $request->route('id');
        if ($id === (int) $request->user()->id) {
            return TxapiResponse::error($request, 'ACCOUNT_IN_USE',
                'Cannot delete your own administrator account', 409);
        }
        if (!$accounts->deleteUnused($id, $request)) {
            return TxapiResponse::error($request, 'ACCOUNT_IN_USE',
                'Account holds roles, funds, subscription or historical records', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function ban(Request $request, AdminAccountModeration $accounts): JsonResponse
    {
        $params = $request->validate([
            'scope' => ['required', 'in:selected,filtered'],
            'user_ids' => ['required_if:scope,selected', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'distinct', 'min:1'],
            'filter' => ['required_if:scope,filtered', 'array', 'min:1', 'max:4'],
            'filter.*.id' => ['required_with:filter', 'in:email,plan_id,banned'],
            'filter.*.value' => ['required_with:filter'],
        ]);
        $scope = $params['scope'];
        if ($scope === 'filtered') {
            $query = User::query();
            foreach ($params['filter'] as $filter) {
                if ($filter['id'] === 'email' && is_string($filter['value'])
                    && mb_strlen($filter['value']) <= 254) {
                    $query->where('email', 'like', '%' . $filter['value'] . '%');
                } elseif (in_array($filter['id'], ['plan_id', 'banned'], true)
                    && is_string($filter['value']) && preg_match('/^eq:\d+$/', $filter['value'])) {
                    $query->where($filter['id'], (int) substr($filter['value'], 3));
                } else {
                    throw ValidationException::withMessages(['filter' => 'Unsupported admin user filter']);
                }
            }
            $ids = $query->orderBy('id')->limit(502)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($ids) > 500) {
                return TxapiResponse::error($request, 'TOO_MANY_USERS', 'Narrow selection to 500 users', 422);
            }
        } else {
            $ids = array_map('intval', $params['user_ids']);
        }
        if ($ids === []) return TxapiResponse::success($request, ['updated' => 0]);

        $updated = $accounts->ban($ids, (int) $request->user()->id);
        return TxapiResponse::success($request, ['updated' => $updated]);
    }
}
