<?php

namespace App\Domains\Identity;

use App\Domains\Identity\Contracts\AccountSessions;
use App\Models\User;

final class NativeAccountSessions implements AccountSessions
{
    public function __construct(private readonly AccountSqlPhase $phase) {}

    public function listing(int $userId, ?int $limit = null): array
    {
        $query = User::query()->findOrFail($userId)->tokens()->orderByDesc('id');
        if ($limit !== null) $query->limit($limit);
        return $query->get(['id', 'name', 'abilities', 'last_used_at',
                'created_at', 'updated_at', 'expires_at'])
            ->map(static fn ($token): array => [
                'id' => (int) $token->id,
                'name' => (string) $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'updated_at' => $token->updated_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])->all();
    }

    public function revoke(int $userId, int $sessionId): bool
    {
        return (bool) $this->phase->run(AccountSqlPhase::SESSION_REVOKE, function () use ($userId, $sessionId): int {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            return $user->tokens()->whereKey($sessionId)->delete();
        });
    }

    public function end(int $userId, ?int $currentTokenId): void
    {
        if ($currentTokenId === null) return;
        $this->phase->run(AccountSqlPhase::SESSION_REVOKE, function () use ($userId, $currentTokenId): void {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $token = $user->tokens()->whereKey($currentTokenId)->lockForUpdate()->first();
            if ($token) $token->delete();
        });
    }
}
