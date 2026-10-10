<?php

namespace App\Services\Module;

final class ModuleCapability
{
    public const ADMIN_MENU = 'admin.menu';
    public const ADMIN_SETTINGS = 'admin.settings';
    public const ADMIN_CRUD = 'admin.crud';
    public const ADMIN_APP = 'admin.app';

    public const API_ROUTE = 'api.route';
    public const WEB_ROUTE = 'web.route';

    public const HOOK_ACTION = 'hook.action';
    public const HOOK_FILTER = 'hook.filter';

    public const DATABASE_MIGRATION = 'database.migration';
    public const SCHEDULER = 'scheduler';
    public const COMMAND = 'command';

    public const THEME = 'theme';

    public const PAYMENT_PROVIDER = 'payment.provider';
    public const NOTIFICATION_PROVIDER = 'notification.provider';

    public const AGENT_API = 'agent.api';
    public const AGENT_ADMIN = 'agent.admin';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN_MENU,
            self::ADMIN_SETTINGS,
            self::ADMIN_CRUD,
            self::ADMIN_APP,
            self::API_ROUTE,
            self::WEB_ROUTE,
            self::HOOK_ACTION,
            self::HOOK_FILTER,
            self::DATABASE_MIGRATION,
            self::SCHEDULER,
            self::COMMAND,
            self::THEME,
            self::PAYMENT_PROVIDER,
            self::NOTIFICATION_PROVIDER,
            self::AGENT_API,
            self::AGENT_ADMIN,
        ];
    }

    public static function isKnown(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }
}
