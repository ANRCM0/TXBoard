<?php

namespace App\Services\Subscription;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Domains\AuthorityMigration\AuthorityIdentitySqlPhase as IdentityPhase;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Domains\AuthorityMigration\AuthorityFenceException;

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
        $capture = config(self::POLICIES[$kind]['flag']) !== false;
        $fenced = IdentityPhase::enabled();
        if (!$capture && !$fenced) return $user->save();
        $prefix = self::POLICIES[$kind]['error'];
        try {
            if (DB::transactionLevel() !== 0) throw new LegacyCaptureShadowException($prefix.'_OUTER_TRANSACTION_UNSUPPORTED');
            if ($user->exists || $user->getKey() !== null) throw new LegacyCaptureShadowException($prefix.'_NEW_USER_REQUIRED');
            $saved = DB::transaction(function () use ($user, $kind, $capture, $fenced): bool {
                if ($fenced) app(AuthorityWriterFence::class)->admit($kind === 'user.register' ? IdentityPhase::USER_REGISTER_CREATE : IdentityPhase::INSTALL_ADMIN_CREATE);
                if (!$capture) return $user->save();
                $evidence = app(LegacyUserCreationEvidence::class);
                $ticket = $evidence->begin($kind, 0);
                if (!$user->save()) return false;
                $evidence->append($ticket, (int) $user->id, $kind);
                return true;
            });
            // The native created observer refreshed this same instance before
            // registration's after hook. Its fenced schedule now commits first.
            if ($saved && $fenced) $user->refresh();
            return $saved;
        } catch (LegacyCaptureShadowException|AuthorityFenceException $exception) { throw $exception; }
        catch (\Throwable) { throw new LegacyCaptureShadowException($prefix.'_STORAGE_OR_EVIDENCE_FAILED'); }
    }
}
