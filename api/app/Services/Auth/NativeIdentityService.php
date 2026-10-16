<?php

namespace App\Services\Auth;

use App\Domains\Installation\InstallationAuthority;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Native accounts carry identity only; Subscription and Provider own access. */
final class NativeIdentityService
{
    public function register(Request $request): array
    {
        if ($request->filled('invite_code') || (int) admin_setting('invite_force', 0) !== 0) {
            return [false, [422, 'Native invitations are unavailable']];
        }
        return $this->create((string) $request->input('email'), $request->input('password'));
    }

    public function create(string $email, mixed $password): array
    {
        $email = strtolower(trim($email));
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 1024
            || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [false, [422, 'Native registration input is unsupported']];
        }
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('NATIVE_IDENTITY_TRANSACTION_OWNERSHIP_REQUIRED');
        }
        try {
            $user = DB::transaction(function () use ($email, $password): User {
                app(InstallationAuthority::class)->assertNativeMutation();
                if (User::byEmail($email)->lockForUpdate()->exists()) {
                    throw new DomainException('NATIVE_IDENTITY_CONFLICT');
                }
                return User::withoutEvents(fn (): User => User::query()->create([
                    'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT),
                    'uuid' => '', 'token' => '', 'plan_id' => null, 'group_id' => null,
                    'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'balance' => 0, 'commission_balance' => 0,
                    'expired_at' => null, 'next_reset_at' => null, 'last_login_at' => time(),
                    'banned' => false, 'is_admin' => false, 'is_staff' => false,
                ]));
            }, 3);
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)
                && User::byEmail($email)->useWritePdo()->exists()) {
                return [false, [400201, 'Registration conflict']];
            }
            throw $error;
        } catch (DomainException $error) {
            if ($error->getMessage() === 'NATIVE_IDENTITY_CONFLICT') {
                return [false, [400201, 'Registration conflict']];
            }
            throw $error;
        }
        return [true, $user];
    }
}
