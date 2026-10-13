<?php

namespace App\Services\Subscription;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class LegacyRegistrationCreationShadow
{
    public function persist(User $user): bool
    {
        if (config('legacy_registration_creation_shadow.enabled') === false) return $user->save();
        try {
            if (DB::transactionLevel() !== 0) throw new LegacyCaptureShadowException('REGISTRATION_SHADOW_OUTER_TRANSACTION_UNSUPPORTED');
            if ($user->exists || $user->getKey() !== null) throw new LegacyCaptureShadowException('REGISTRATION_SHADOW_NEW_USER_REQUIRED');
            return DB::transaction(function () use ($user): bool {
                $evidence = app(LegacyUserCreationEvidence::class);
                $ticket = $evidence->begin('user.register', 0);
                if (!$user->save()) return false;
                $evidence->append($ticket, (int) $user->id, 'user.register');
                return true;
            });
        } catch (LegacyCaptureShadowException $exception) { throw $exception; }
        catch (\Throwable) { throw new LegacyCaptureShadowException('REGISTRATION_SHADOW_STORAGE_OR_EVIDENCE_FAILED'); }
    }
    public function verify(int $userId): array
    { return app(LegacyUserCreationEvidence::class)->verify($userId, 'user.register'); }
}
