<?php

namespace App\Services\Subscription;

/** Dedicated Admin facade retains its existing policy, gate and ticket shape. */
final class LegacyAdminCreationShadow
{
    public const VERSION = 'admin-creation-shadow/v1';
    public function begin(int $adminId): ?array
    { return app(LegacyUserCreationEvidence::class)->begin('admin.user.create', $adminId); }
    public function append(?array $ticket, int $userId): void
    { app(LegacyUserCreationEvidence::class)->append($ticket, $userId, 'admin.user.create'); }
    public function verify(int $userId): array
    { return app(LegacyUserCreationEvidence::class)->verify($userId, 'admin.user.create'); }
}
