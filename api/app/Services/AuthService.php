<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use App\Domains\Identity\AccountSqlPhase as IdentityPhase;
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
            $this->user = User::query()->whereKey($this->user->getKey())->lockForUpdate()->firstOrFail();
            if ($this->user->banned) throw new AuthorizationException('Your account has been suspended');
            if ($requireAdmin && !$this->user->is_admin) throw new AuthorizationException('Administrator access required');
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
            $user = User::query()->whereKey($this->user->getKey())->lockForUpdate()->firstOrFail();
            $user->tokens()->where('id', $sessionId)->delete();
        });
        return true;
    }

    public function removeAllSessions(): bool
    {
        app(IdentityPhase::class)->run(IdentityPhase::SESSION_REVOKE, function (): void {
            $user = User::query()->whereKey($this->user->getKey())->lockForUpdate()->firstOrFail();
            $user->tokens()->delete();
        });
        return true;
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
