<?php

namespace App\Services\Subscription;

use App\Models\User;

final class LegacyBootstrapCreationShadow
{
    public function persist(User $user): bool
    { return app(LegacySelfCreationPersistence::class)->persist($user, 'install.admin.create'); }
    public function verify(int $userId): array
    { return app(LegacyUserCreationEvidence::class)->verify($userId, 'install.admin.create'); }
}
