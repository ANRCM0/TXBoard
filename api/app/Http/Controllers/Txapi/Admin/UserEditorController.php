<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UserEditorController
{
    /**
     * Admin editor uses major currency units. Accept changes to existing
     * absolute balances only with an exact expected balance snapshot, so a
     * stale browser tab cannot overwrite intervening order settlements.
     */
    public function update(Request $request): JsonResponse
    {
        $fields = $request->validate([
            'email' => ['sometimes', 'email:strict', 'max:254'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:255'],
            'plan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expired_at' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'transfer_enable' => ['sometimes', 'integer', 'min:0'],
            'u' => ['sometimes', 'integer', 'min:0'],
            'd' => ['sometimes', 'integer', 'min:0'],
            'banned' => ['sometimes', 'boolean'],
            'balance' => ['sometimes', 'required_with:expected_balance_minor', 'numeric', 'min:0'],
            'commission_balance' => ['sometimes', 'required_with:expected_commission_balance_minor', 'numeric', 'min:0'],
            'expected_balance_minor' => ['required_with:balance', 'integer', 'min:0'],
            'expected_commission_balance_minor' => ['required_with:commission_balance', 'integer', 'min:0'],
            'expected_transfer_enable' => ['required_with:transfer_enable', 'nullable', 'integer', 'min:0'],
            'expected_u' => ['required_with:u', 'nullable', 'integer', 'min:0'],
            'expected_d' => ['required_with:d', 'nullable', 'integer', 'min:0'],
            'expected_plan_id' => ['required_with:plan_id', 'nullable', 'integer', 'min:1'],
            'expected_expired_at' => ['required_with:expired_at', 'nullable', 'integer', 'min:0'],
            'commission_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'discount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'speed_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'device_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'invite_user_email' => ['sometimes', 'nullable', 'email:strict', 'max:254'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Permission promotion remains a separately reviewed operation.
            'is_admin' => ['prohibited'],
            'is_staff' => ['prohibited'],
            'id' => ['prohibited'],
        ]);
        $id = (int) $request->route('id');

        foreach (['balance' => 'expected_balance_minor',
                     'commission_balance' => 'expected_commission_balance_minor'] as $key => $expectedKey) {
            if (array_key_exists($key, $fields)) {
                $fields[$key] = self::minorUnits($fields[$key], $key);
            }
        }
        unset($fields['expected_balance_minor'], $fields['expected_commission_balance_minor']);
        foreach (['transfer_enable', 'u', 'd', 'plan_id', 'expired_at'] as $field) {
            unset($fields['expected_' . $field]);
        }

        [$user, $applied] = DB::transaction(static function () use ($request, $id, $fields): array {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            foreach (['balance' => 'expected_balance_minor',
                         'commission_balance' => 'expected_commission_balance_minor'] as $key => $expectedKey) {
                if (array_key_exists($key, $fields) &&
                    (int) $user->$key !== (int) $request->input($expectedKey)) {
                    throw ValidationException::withMessages([
                        $key => 'Account balance changed since this editor opened. Reload before saving.',
                    ]);
                }
            }
            foreach (['transfer_enable', 'u', 'd', 'plan_id', 'expired_at'] as $field) {
                if (array_key_exists($field, $fields) &&
                    ($user->$field === null ? null : (int) $user->$field)
                    !== ($request->input('expected_' . $field) === null
                        ? null : (int) $request->input('expected_' . $field))) {
                    throw ValidationException::withMessages([
                        $field => 'Subscription or usage changed while editing. Reload before saving.',
                    ]);
                }
            }
            $changes = $fields;
            if (array_key_exists('email', $changes)) {
                $email = strtolower(trim($changes['email']));
                if (User::byEmail($email)->where('id', '!=', $id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'Email already in use']);
                }
                $changes['email'] = $email;
            }
            if (array_key_exists('plan_id', $changes)) {
                if ($changes['plan_id'] !== null) {
                    $plan = Plan::findOrFail((int) $changes['plan_id']);
                    $changes['group_id'] = $plan->group_id;
                } else {
                    $changes['group_id'] = null;
                }
            }
            if (array_key_exists('invite_user_email', $changes)) {
                $referral = $changes['invite_user_email']
                    ? User::byEmail($changes['invite_user_email'])->first() : null;
                if ($changes['invite_user_email'] && !$referral) {
                    throw ValidationException::withMessages(['invite_user_email' => 'Inviter not found']);
                }
                if ($referral && (int) $referral->id === $id) {
                    throw ValidationException::withMessages(['invite_user_email' => 'Cannot invite yourself']);
                }
                $changes['invite_user_id'] = $referral?->id;
                unset($changes['invite_user_email']);
            }
            if (array_key_exists('password', $changes)) {
                if (empty($changes['password'])) {
                    unset($changes['password']);
                } else {
                    $changes['password'] = Hash::make($changes['password']);
                    $changes['password_algo'] = null;
                }
            }

            // Existing plugin hooks must survive, but an extension must not
            // smuggle role/credential columns through a validated DTO.
            $allowed = array_keys($changes);
            $changes = HookManager::filter('admin.user.update.params', $changes, $request, $user);
            if (!is_array($changes) || array_diff(array_keys($changes), $allowed)) {
                throw ValidationException::withMessages(['user' => 'Unsupported plugin modification to account fields']);
            }
            HookManager::call('admin.user.update.before', [
                'user' => $user, 'params' => $changes, 'request' => $request,
            ]);
            $wasBanned = (bool) $user->banned;
            $user->fill($changes)->saveOrFail();
            if ((!$wasBanned && (bool) $user->banned) || array_key_exists('password', $changes)) {
                (new AuthService($user))->removeAllSessions();
            }
            return [$user, $changes];
        });

        HookManager::call('admin.user.update.after', [
            'user' => $user->refresh(), 'params' => $applied, 'request' => $request,
        ]);
        return TxapiResponse::success($request, ['ok' => true, 'id' => (int) $user->id]);
    }

    public function create(Request $request, UserService $service): JsonResponse
    {
        $fields = $request->validate([
            'email_prefix' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9._+-]+$/'],
            'email_suffix' => ['required', 'string', 'max:170'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'plan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expired_at' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'generate_count' => ['sometimes', 'integer', 'in:1'],
            'return_credentials' => ['sometimes', 'boolean'],
        ]);
        $email = strtolower($fields['email_prefix'] . '@' . $fields['email_suffix']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email_suffix' => 'Invalid email address']);
        }
        if (!empty($fields['plan_id']) && !Plan::query()->whereKey($fields['plan_id'])->exists()) {
            throw ValidationException::withMessages(['plan_id' => 'Subscription plan not found']);
        }
        $user = DB::transaction(static function () use ($fields, $email, $service): User {
            if (User::byEmail($email)->exists()) {
                throw ValidationException::withMessages(['email_prefix' => 'Email already exists']);
            }
            $user = $service->createUser([
                'email' => $email,
                'password' => $fields['password'],
                'plan_id' => $fields['plan_id'] ?? null,
                'expired_at' => $fields['expired_at'] ?? null,
            ]);
            $user->saveOrFail();
            return $user;
        });
        // Do not echo credentials, UUID or subscription token in a bulk
        // administrative response; operators already supplied the password.
        return TxapiResponse::success($request, ['id' => (int) $user->id], [], 201);
    }

    private static function minorUnits(mixed $amount, string $key): int
    {
        $number = (string) $amount;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', $number)) {
            throw ValidationException::withMessages([$key => 'Amount must be a non-negative number with at most two decimals']);
        }
        [$whole, $fraction] = array_pad(explode('.', $number, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
