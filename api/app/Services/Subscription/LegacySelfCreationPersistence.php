<?php

namespace App\Services\Subscription;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Only two fixed native new-User save paths, each with independent strict opt-in. */
final class LegacySelfCreationPersistence
{
    private const POLICIES = [
        'user.register' => ['flag' => 'legacy_registration_creation_shadow.enabled', 'error' => 'REGISTRATION_SHADOW'],
        'install.admin.create' => ['flag' => 'legacy_bootstrap_creation_shadow.enabled', 'error' => 'BOOTSTRAP_SHADOW'],
    ];
    public function persist(User $user, string $kind): bool
    {
        if (!isset(self::POLICIES[$kind])) throw new LegacyCaptureShadowException('CREATION_SHADOW_POLICY_INVALID');
        if (config(self::POLICIES[$kind]['flag']) === false) return $user->save();
        $prefix = self::POLICIES[$kind]['error'];
        try {
            if (DB::transactionLevel() !== 0) throw new LegacyCaptureShadowException($prefix.'_OUTER_TRANSACTION_UNSUPPORTED');
            if ($user->exists || $user->getKey() !== null) throw new LegacyCaptureShadowException($prefix.'_NEW_USER_REQUIRED');
            return DB::transaction(function () use ($user, $kind): bool {
                $evidence = app(LegacyUserCreationEvidence::class);
                $ticket = $evidence->begin($kind, 0);
                if (!$user->save()) return false;
                $evidence->append($ticket, (int) $user->id, $kind);
                return true;
            });
        } catch (LegacyCaptureShadowException $exception) { throw $exception; }
        catch (\Throwable) { throw new LegacyCaptureShadowException($prefix.'_STORAGE_OR_EVIDENCE_FAILED'); }
    }
}
