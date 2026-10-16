<?php

namespace App\Domains\Identity;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Identity writes share the current installation and managed transaction. */
final class AccountSqlPhase
{
    public const USER_REGISTER_INVITE_CONSUME='user.register.invite-consume';
    public const USER_REGISTER_CREATE='user.register.create';
    public const USER_REGISTER_TOUCH='user.register.touch';
    public const INSTALL_ADMIN_CREATE='install.admin.create';
    public const USER_LOGIN_TOUCH='user.login.touch';
    public const USER_PASSWORD_RECOVER='user.password.recover';
    public const USER_PASSWORD_CHANGE='user.password.change';
    public const USER_ACCOUNT_PREFERENCES='user.account.preferences';
    public const USER_ACCOUNT_CREDENTIALS_ROTATE='user.account.credentials.rotate';
    public const SESSION_ISSUE='session.issue';
    public const SESSION_REVOKE='session.revoke';
    public const SESSION_LAST_USED='session.last-used';
    public const CLI_PASSWORD_RESET='cli.password.reset';
    public const CLI_USER_CREDENTIALS_ROTATE='cli.user.credentials.rotate';
    public const INVITE_CREATE='invite.create';
    public const INVITE_PAGE_VIEW='invite.page-view';
    public const WITHDRAWAL_REQUEST='withdrawal.request';
    public const ADMIN_AUDIT_APPEND='admin.audit.append';
    public const MAIL_LOG_APPEND='mail.log.append';

    private const WRITERS = [
        self::USER_REGISTER_INVITE_CONSUME, self::USER_REGISTER_CREATE, self::USER_REGISTER_TOUCH,
        self::INSTALL_ADMIN_CREATE, self::USER_LOGIN_TOUCH,
        self::USER_PASSWORD_RECOVER, self::USER_PASSWORD_CHANGE, self::USER_ACCOUNT_PREFERENCES,
        self::USER_ACCOUNT_CREDENTIALS_ROTATE, self::SESSION_ISSUE, self::SESSION_REVOKE,
        self::SESSION_LAST_USED, self::CLI_PASSWORD_RESET, self::CLI_USER_CREDENTIALS_ROTATE,
        self::INVITE_CREATE, self::INVITE_PAGE_VIEW, self::WITHDRAWAL_REQUEST, self::ADMIN_AUDIT_APPEND, self::MAIL_LOG_APPEND,
    ];

    public function run(string $writer, callable $phase, int $attempts = 1): mixed
    {
        if (!in_array($writer, self::WRITERS, true)) {
            throw new DomainException('ACCOUNT_WRITER_INVALID');
        }
        if (app(InstallationAuthority::class)->inspect()['mode'] === 'native') {
            if (!in_array($writer, [
                self::USER_LOGIN_TOUCH, self::USER_PASSWORD_RECOVER, self::USER_PASSWORD_CHANGE,
                self::USER_ACCOUNT_PREFERENCES, self::SESSION_ISSUE, self::SESSION_REVOKE,
                self::SESSION_LAST_USED, self::ADMIN_AUDIT_APPEND, self::MAIL_LOG_APPEND,
            ], true)) {
                throw new InstallationAuthorityException('INSTALLATION_NATIVE_IDENTITY_WRITER_REJECTED');
            }
            return DB::transaction(function () use ($phase): mixed {
                app(InstallationAuthority::class)->assertNativeMutation();
                return User::withoutEvents($phase);
            }, $attempts);
        }
        return DB::transaction(function () use ($phase): mixed {
            app(InstallationAuthority::class)->assertLegacyWriter();
            return $phase();
        }, $attempts);
    }
}
