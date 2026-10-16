<?php

namespace App\Domains\Identity;

use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Models\User;
use DomainException;

/** Owns persistence for registration and installation's initial administrator. */
final class AccountCreationService
{
    public function persist(User $user, string $writer): bool
    {
        if (!in_array($writer, [
            AccountSqlPhase::USER_REGISTER_CREATE,
            AccountSqlPhase::INSTALL_ADMIN_CREATE,
        ], true)) {
            throw new DomainException('ACCOUNT_CREATION_WRITER_INVALID');
        }
        if ($user->exists || $user->getKey() !== null) {
            throw new DomainException('ACCOUNT_CREATION_NEW_USER_REQUIRED');
        }
        if (AccountSqlPhase::enabled() && $user->getConnection()->transactionLevel() !== 0) {
            throw new DomainException('ACCOUNT_CREATION_OUTER_TRANSACTION_UNSUPPORTED');
        }

        try {
            $saved = app(AccountSqlPhase::class)->run($writer, fn (): bool => $user->save());
            // Commit-time scheduling may have saved a separately locked model.
            if ($saved && AccountSqlPhase::enabled()) {
                $user->refresh();
            }
            return $saved;
        } catch (AuthorityFenceException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new DomainException('ACCOUNT_CREATION_FAILED');
        }
    }
}
