<?php

namespace App\Domains\Identity;

use App\Domains\Installation\InstallationAuthority;
use App\Models\Order;
use App\Models\WalletRecharge;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Shared V2/native Admin boundary: never erase financial, support or usage history
 * merely because an administrator clicked "delete".
 */
final class AdminAccountProtection
{
    public function deleteUnused(int $userId, Request $request): bool
    {
        $deleted = DB::transaction(static function () use ($userId, $request): ?User {
            app(InstallationAuthority::class)->assertLegacyWriter();
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            // A staff account, account with money/traffic or any linked domain
            // history must be deactivated, not physically removed.
            if ($user->is_admin || $user->is_staff || $user->plan_id !== null
                || (int) $user->balance !== 0 || (int) $user->commission_balance !== 0
                || (int) $user->u !== 0 || (int) $user->d !== 0
                || $user->orders()->exists() || $user->tickets()->exists()
                || WalletRecharge::query()->where('user_id', $userId)->exists()
                || $user->codes()->exists() || $user->stat()->exists()
                || $user->trafficResetLogs()->exists()
                || Order::query()->where('invite_user_id', $userId)->exists()
                || User::query()->where('invite_user_id', $userId)->exists()
                || (Schema::hasColumn('tx_user', 'parent_id')
                    && User::query()->where('parent_id', $userId)->exists())
            ) {
                return null;
            }

            HookManager::call('admin.user.destroy.before', [
                'user' => $user, 'request' => $request,
            ]);

            // A deleted account must not leave active admin/user API sessions.
            (new AuthService($user))->removeAllSessions();
            if ($user->delete() !== true) {
                throw ValidationException::withMessages(['user' => 'The account could not be deleted']);
            }
            return $user;
        });
        if ($deleted === null) {
            return false;
        }
        HookManager::call('admin.user.destroy.after', [
            'user' => $deleted, 'request' => $request,
        ]);
        return true;
    }

}
