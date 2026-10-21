<?php

namespace App\Services\Auth;

use App\Domains\Installation\InstallationAuthority;
use App\Models\User;
use App\Domains\Identity\NativeIdentityRevision;
use App\Domains\Identity\NativeIdentityAdminReceipts;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
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

    public function create(string $email, mixed $password, ?int $actorId = null): array
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
            $user = DB::transaction(function () use ($email, $password, $actorId): User {
                app(InstallationAuthority::class)->assertNativeMutation();
                if ($actorId !== null) {
                    $actor = User::query()->whereKey($actorId)->sharedLock()->first();
                    if ($actor === null || !$actor->is_admin || $actor->banned) throw new DomainException('NATIVE_ACCOUNT_ACTOR_REJECTED');
                }
                if (User::byEmail($email)->lockForUpdate()->exists()) {
                    throw new DomainException('NATIVE_IDENTITY_CONFLICT');
                }
                return self::insertAccount($email, password_hash($password, PASSWORD_DEFAULT));
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

    public function createBatch(int $actorId, array $accounts, string $key): array
    {
        if (DB::transactionLevel() !== 0 || !array_is_list($accounts) || count($accounts) < 1 || count($accounts) > 20) {
            throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
        }
        $rows = [];
        foreach ($accounts as $account) {
            if (!is_array($account) || count($account) !== 2 || array_diff(array_keys($account), ['email', 'password'])) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
            $email = self::email($account['email'] ?? null); $password = $account['password'] ?? null;
            if (!is_string($password) || strlen($password) < 8 || strlen($password) > 255 || isset($rows[$email])) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
            $rows[$email] = password_hash($password, PASSWORD_DEFAULT);
        }
        ksort($rows, SORT_STRING);
        try {
            return DB::transaction(function () use ($actorId, $rows, $key, $accounts): array {
                $installation = app(InstallationAuthority::class)->assertNativeMutation();
                self::adminActor($actorId);
                $fingerprint = NativeIdentityAdminReceipts::fingerprint($installation['installation_id'], $actorId, 'batch-create', $key, ['accounts' => $accounts]);
                if (($old = NativeIdentityAdminReceipts::recover($fingerprint)) !== null) return $old;
                foreach (array_keys($rows) as $email) if (User::byEmail($email)->lockForUpdate()->exists()) throw new DomainException('NATIVE_ACCOUNT_CONFLICT');
                $created = [];
                foreach ($rows as $email => $hash) {
                    $user = self::insertAccount($email, $hash);
                    $created[] = ['id' => (int) $user->id, 'email' => (string) $user->email];
                }
                return NativeIdentityAdminReceipts::append($fingerprint, ['items' => $created, 'created_count' => count($created)]);
            }, 3);
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) throw new DomainException('NATIVE_ACCOUNT_CONFLICT');
            throw $error;
        }
    }

    public function updateProfile(int $actorId, int $userId, string $expectedRevision, array $changes, string $key): array
    {
        if (DB::transactionLevel() !== 0 || !preg_match('/\A[0-9a-f]{64}\z/D', $expectedRevision)
            || $changes === [] || array_diff(array_keys($changes), ['email', 'password'])) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
        $normalized = [];
        if (array_key_exists('email', $changes)) $normalized['email'] = self::email($changes['email']);
        if (array_key_exists('password', $changes)) {
            if (!is_string($changes['password']) || strlen($changes['password']) < 8 || strlen($changes['password']) > 255) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
            $normalized['password'] = password_hash($changes['password'], PASSWORD_DEFAULT);
        }
        try {
            return DB::transaction(function () use ($actorId, $userId, $expectedRevision, $normalized, $changes, $key): array {
                $installation = app(InstallationAuthority::class)->assertNativeMutation();
                self::adminActor($actorId);
                $fingerprint = NativeIdentityAdminReceipts::fingerprint($installation['installation_id'], $actorId, 'profile', $key,
                    ['user_id' => (string) $userId, 'expected_revision' => $expectedRevision, 'changes' => $changes]);
                if (($old = NativeIdentityAdminReceipts::recover($fingerprint)) !== null) return $old;
                $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
                if ($user->is_admin || $user->is_staff || $userId === $actorId) throw new DomainException('NATIVE_ACCOUNT_PROTECTED');
                if (!hash_equals(NativeIdentityRevision::forUser($user), $expectedRevision)) throw new DomainException('NATIVE_ACCOUNT_VERSION_CONFLICT');
                if (isset($normalized['email']) && User::byEmail($normalized['email'])->where('id', '<>', $userId)->exists()) throw new DomainException('NATIVE_ACCOUNT_CONFLICT');
                $changed = isset($normalized['password']) || (isset($normalized['email']) && $normalized['email'] !== $user->email);
                $revoked = 0;
                if ($changed) {
                    DB::table('tx_user')->where('id', $userId)->update($normalized + ['updated_at' => time()]);
                    $revoked = $user->tokens()->delete();
                    $user = User::query()->whereKey($userId)->firstOrFail();
                }
                return NativeIdentityAdminReceipts::append($fingerprint, ['id' => $userId, 'email' => (string) $user->email,
                    'identity_revision' => NativeIdentityRevision::forUser($user), 'sessions_revoked' => $revoked]);
            }, 3);
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) throw new DomainException('NATIVE_ACCOUNT_CONFLICT');
            throw $error;
        }
    }

    private static function email(mixed $value): string
    {
        if (!is_string($value)) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
        $email = strtolower(trim($value));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
        return $email;
    }

    private static function adminActor(int $actorId): void
    {
        $actor = User::query()->whereKey($actorId)->lockForUpdate()->first();
        if ($actor === null || !$actor->is_admin || $actor->banned) throw new DomainException('NATIVE_ACCOUNT_ACTOR_REJECTED');
        app(CurrentSessionAuthorization::class)->assertCurrentLocked($actorId, 'identity.accounts.manage');
    }

    private static function insertAccount(string $email, string $passwordHash): User
    {
        return User::withoutEvents(fn (): User => User::query()->create([
            'email' => $email, 'password' => $passwordHash, 'uuid' => '', 'token' => '', 'plan_id' => null, 'group_id' => null,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'balance' => 0, 'commission_balance' => 0,
            'expired_at' => null, 'next_reset_at' => null, 'last_login_at' => time(), 'banned' => false, 'is_admin' => false, 'is_staff' => false,
        ]));
    }
}
