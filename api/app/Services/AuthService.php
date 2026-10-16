<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use App\Domains\AuthorityMigration\AuthorityIdentitySqlPhase as IdentityPhase;
use App\Domains\AuthorityMigration\AuthorityAdmissionRegistry;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Domains\AuthorityMigration\AuthorityInventory;
use App\Domains\AuthorityMigration\AuthorityFinanceInventory;
use App\Domains\AuthorityMigration\AuthorityNativeInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;

class AuthService
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(bool $requireAdmin = false): array
    {
        // Create a new Sanctum token with device info
        $token = app(IdentityPhase::class)->run(IdentityPhase::SESSION_ISSUE, function () use ($requireAdmin) {
            if (IdentityPhase::locksAccount()) {
                $this->user = User::query()->whereKey($this->user->getKey())->lockForUpdate()->firstOrFail();
                if ($this->user->banned) throw new AuthorizationException('Your account has been suspended');
                if ($requireAdmin && !$this->user->is_admin) throw new AuthorizationException('Administrator access required');
            }
            return $this->user->createToken(
                Str::random(20), // token name (device identifier)
                ['*'], // abilities
                now()->addYear() // expiration
            );
        });

        // Format token: remove ID prefix and add Bearer
        $tokenParts = explode('|', $token->plainTextToken);
        $formattedToken = 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);

        $data = [
            'token' => $this->user->token,
            'auth_data' => $formattedToken,
            'is_admin' => $this->user->is_admin,
        ];

        // The secure admin entry is not user-facing configuration. Returning it
        // to every successful login let any ordinary account discover the
        // supposedly hidden management path. Admin sign-in still needs it to
        // bootstrap the protected API prefix.
        if ($this->user->is_admin) {
            $data['secure_path'] = admin_setting(
                'secure_path',
                admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
            );
        }

        return $data;
    }

    public function getSessions(): array
    {
        return $this->user->tokens()->get()->toArray();
    }

    public function removeSession(string $sessionId): bool
    {
        app(IdentityPhase::class)->run(IdentityPhase::SESSION_REVOKE, function () use ($sessionId): void {
            $user = IdentityPhase::locksAccount() ? User::query()->whereKey($this->user->getKey())->lockForUpdate()->firstOrFail() : $this->user;
            $user->tokens()->where('id', $sessionId)->delete();
        });
        return true;
    }

    public function removeAllSessions(): bool
    {
        if ($this->hasInheritedNativeSessionDeletion()) {
            // These reviewed old roots already admitted authority and locked
            // this User. Preserve their original session deletion in that phase.
            $this->user->tokens()->delete();
            return true;
        }
        app(IdentityPhase::class)->run(IdentityPhase::SESSION_REVOKE, function (): void {
            $user = IdentityPhase::locksAccount() ? User::query()->whereKey($this->user->getKey())->lockForUpdate()->firstOrFail() : $this->user;
            $user->tokens()->delete();
        });
        return true;
    }

    private function hasInheritedNativeSessionDeletion(): bool
    {
        if (!IdentityPhase::enabled() || DB::transactionLevel() === 0) return false;
        $proof = app(AuthorityAdmissionRegistry::class)->current();
        $proof->assertValid();
        $declaration = match ($proof->scope) {
            AuthorityWriterFence::SCOPE => [AuthorityWriterFence::VERSION, '1', ['admin.user.update']],
            AuthorityInventory::SCOPE => [AuthorityInventory::VERSION, '2', ['admin.user.update', 'admin.user.delete', 'admin.user.bulk-ban']],
            AuthorityFinanceInventory::SCOPE => [AuthorityFinanceInventory::VERSION, '3', ['admin.user.update', 'admin.user.delete', 'admin.user.bulk-ban']],
            AuthorityNativeInventory::SCOPE => [AuthorityNativeInventory::VERSION, '4', ['admin.user.update', 'admin.user.delete', 'admin.user.bulk-ban']],
            default => null,
        };
        if ($declaration === null || $proof->version !== $declaration[0] || $proof->epoch !== $declaration[1]
            || !in_array($proof->writer, $declaration[2], true)) return false;
        $settings = config('authority_writer_fence');
        if (!is_array($settings) || !in_array(count($settings), [3, 5], true)
            || array_diff(array_keys($settings), count($settings) === 3
                ? ['enabled', 'worker_epoch', 'worker_role']
                : ['enabled', 'worker_epoch', 'worker_role', 'worker_scope', 'worker_version'])
            || $settings['enabled'] !== true || $settings['worker_role'] !== 'legacy'
            || !is_int($settings['worker_epoch']) && !is_string($settings['worker_epoch'])
            || (string) $settings['worker_epoch'] !== $proof->epoch
            || !app()->environment(['local', 'testing']) || config('core_shadow.isolated_copy') !== true) return false;
        $scope = count($settings) === 3 ? AuthorityWriterFence::SCOPE : $settings['worker_scope'];
        $version = count($settings) === 3 ? AuthorityWriterFence::VERSION : $settings['worker_version'];
        return $scope === $proof->scope && $version === $proof->version;
    }

    public static function findUserByBearerToken(string $bearerToken): ?User
    {
        $token = str_replace('Bearer ', '', $bearerToken);
        
        $accessToken = PersonalAccessToken::findToken($token);
        
        $tokenable = $accessToken?->tokenable;
        
        return $tokenable instanceof User ? $tokenable : null;
    }

    /**
     * 解密认证数据
     *
     * @param string $authorization
     * @return array|null 用户数据或null
     */
    public static function decryptAuthData(string $authorization): ?array
    {
        $user = self::findUserByBearerToken($authorization);
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool)$user->is_admin,
            'is_staff' => (bool)$user->is_staff
        ];
    }
}
