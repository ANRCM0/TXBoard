<?php

namespace App\Domains\InternalApi;

/** Fixed built-in owner adapters; callers cannot choose a handler or an actor role. */
final class SupportOperationBindings
{
    public static function all(): array
    {
        $result = [];
        foreach (['listing' => 'list', 'find' => 'get', 'messages' => 'messages.list'] as $method => $suffix) {
            $result['support.tickets.'.$suffix] = self::spec('user', 'read', $method, false);
            $result['support.admin-tickets.'.$suffix] = self::spec('admin', 'read', 'admin'.ucfirst($method), false);
        }
        foreach (['create', 'reply', 'close'] as $method) {
            $result['support.tickets.'.$method] = self::spec('user', 'domain', $method, true);
            if ($method !== 'create') {
                $result['support.admin-tickets.'.$method] = self::spec('admin', 'domain', 'admin'.ucfirst($method), true);
            }
        }
        $result['support.admin-tickets.audit.list'] = self::spec('admin', 'read', 'adminAudit', false);
        $result['support.admin-tickets.claim'] = self::spec('admin', 'domain', 'adminClaim', true);
        $result['support.admin-tickets.unassign'] = self::spec('admin', 'domain', 'adminUnassign', true);
        return $result;
    }

    private static function spec(string $domain, string $mode, string $method, bool $write): array
    {
        return ['domains' => [$domain], 'actor' => $domain, 'mode' => $mode,
            'controller' => null, 'method' => $method, 'rules' => [], 'fields' => [], 'list' => false,
            'parameters' => [], 'routeParameters' => [],
            'permission' => $domain === 'admin' ? ($write ? 'support.tickets.write' : 'support.tickets.read') : null,
            'handler' => $write ? null : SupportReadOperations::class,
            'command' => $write ? SupportMutationOperations::class : null];
    }
}
