<?php

namespace App\Services\Subscription;

use App\Models\User;

final class LegacyRegistrationCreationShadow
{
    public function persist(User $user): bool
    { return app(LegacySelfCreationPersistence::class)->persist($user, 'user.register'); }
    public function verify(int $userId): array
    { return app(LegacyUserCreationEvidence::class)->verify($userId, 'user.register'); }
}
