<?php

namespace App\Domains\Identity;

use App\Domains\Identity\AccountSqlPhase as IdentityPhase;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Guard;

/** Preserve Sanctum authentication while serializing session metadata with its owner. */
final class AccountSessionGuard extends Guard
{
    protected function updateLastUsedAt($accessToken)
    {
        $connection = $accessToken->getConnection();
        $owner = $accessToken->tokenable;
        if (!$owner instanceof User || $connection->getName() !== DB::connection()->getName()
            || $owner->getConnection()->getName() !== $connection->getName()) {
            throw new \LogicException('ACCOUNT_SESSION_CONNECTION_UNSUPPORTED');
        }
        $tracksModification = method_exists($connection, 'hasModifiedRecords') && method_exists($connection, 'setRecordModificationState');
        $modified = $tracksModification ? $connection->hasModifiedRecords() : null;
        try {
            app(IdentityPhase::class)->run(IdentityPhase::SESSION_LAST_USED, function () use ($accessToken, $owner): void {
                // Session roots use this same authority -> User -> token order.
                $currentOwner = User::query()->whereKey($owner->getKey())->lockForUpdate()->first();
                if (!$currentOwner) throw new AuthenticationException();
                $current = $currentOwner->tokens()->whereKey($accessToken->getKey())->lockForUpdate()->first();
                if (!$current || !$this->isValidAccessToken($current)) throw new AuthenticationException();
                // Native TokenAuthenticated hooks already ran. Retain their dirty
                // fields while refreshing current stored metadata and account state.
                $tokenChanges = $accessToken->getDirty();
                $ownerChanges = $owner->getDirty();
                $accessToken->setRawAttributes($current->getAttributes(), true);
                $accessToken->forceFill($tokenChanges);
                $owner->setRawAttributes($currentOwner->getAttributes(), true);
                $owner->forceFill($ownerChanges);
                parent::updateLastUsedAt($accessToken);
            });
        } finally {
            if ($tracksModification) $connection->setRecordModificationState($modified);
        }
    }
}
