<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Support\Facades\DB;

/** Source-only closed operations; delegates exactly one original native SQL phase. */
final class AuthorityIdentitySqlPhase
{
    public const USER_REGISTER_INVITE_CONSUME='user.register.invite-consume';
    public const USER_REGISTER_CREATE='user.register.create';
    public const USER_REGISTER_TOUCH='user.register.touch';
    public const INSTALL_ADMIN_CREATE='install.admin.create';
    public const INSTALL_SETTINGS_SEED='install.settings.seed';
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
    public const SETTINGS_WRITE='settings.write';
    public const SETTINGS_SUBSCRIBE_TEMPLATE_WRITE='settings.subscribe-template.write';
    public const SETTINGS_REMOVE='settings.remove';
    public const STATS_USER_APPLY='stats.user.apply';
    public const STATS_SERVER_APPLY='stats.server.apply';
    public const STATS_SERVER_TRAFFIC='stats.server.traffic';
    public const STATS_DAILY_APPLY='stats.daily.apply';
    public const ADMIN_AUDIT_APPEND='admin.audit.append';
    public const MAIL_LOG_APPEND='mail.log.append';

    public static function enabled():bool { return config('authority_writer_fence.enabled')!==false; }
    public static function locksAccount(): bool
    {
        return self::enabled() || config('core_installation.mode') === 'native';
    }

    public function run(string $writer,callable $phase,int $attempts=1):mixed
    {
        if(!in_array($writer,array_diff(AuthorityIdentityInventory::WRITERS,AuthorityNativeInventory::WRITERS),true))
            throw new AuthorityFenceException('AUTHORITY_IDENTITY_WRITER_INVALID');
        if (app(\App\Domains\Installation\InstallationAuthority::class)->inspect()['mode'] === 'native') {
            if (!in_array($writer, [
                self::USER_LOGIN_TOUCH, self::USER_PASSWORD_RECOVER, self::USER_PASSWORD_CHANGE,
                self::USER_ACCOUNT_PREFERENCES, self::SESSION_ISSUE, self::SESSION_REVOKE,
                self::SESSION_LAST_USED, self::ADMIN_AUDIT_APPEND, self::MAIL_LOG_APPEND,
            ], true)) {
                throw new AuthorityFenceException('AUTHORITY_NATIVE_IDENTITY_WRITER_REJECTED');
            }
            return DB::transaction(function () use ($phase): mixed {
                app(\App\Domains\Installation\InstallationAuthority::class)->assertNativeMutation();
                return \App\Models\User::withoutEvents($phase);
            }, $attempts);
        }
        if(!self::enabled())return $phase();
        if(DB::transactionLevel()>0){
            app(AuthorityAdmissionRegistry::class)->current()->assertValid();
            return DB::transaction(function()use($writer,$phase){app(AuthorityWriterFence::class)->admit($writer);return $phase();},$attempts);
        }
        return DB::transaction(function()use($writer,$phase){app(AuthorityWriterFence::class)->admit($writer);return $phase();},$attempts);
    }
}
