<?php

namespace App\Domains\Identity;

use App\Domains\Installation\InstallationAuthorityException;
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
        try {
            return app(AccountSqlPhase::class)->run($writer, fn (): bool => $user->save());
        } catch (InstallationAuthorityException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new DomainException('ACCOUNT_CREATION_FAILED');
        }
    }
}
