<?php

namespace App\Domains\InternalApi;

/** Explicit built-in adapters preserve separate user and administrator grants. */
final class SupportAttachmentBindings
{
    public static function all(): array
    {
        $result = [];
        foreach (['user' => 'support.tickets.attachments.', 'admin' => 'support.admin-tickets.attachments.'] as $domain => $prefix) {
            foreach (['begin', 'append', 'finish', 'cancel', 'get', 'download'] as $verb) {
                $write = !in_array($verb, ['get', 'download'], true);
                $result[$prefix.$verb] = ['domains' => [$domain], 'actor' => $domain,
                    'mode' => $write ? 'domain' : 'read', 'controller' => null,
                    'method' => $domain.ucfirst($verb), 'rules' => [], 'fields' => [], 'list' => false,
                    'parameters' => [], 'routeParameters' => [],
                    'permission' => $domain === 'admin' ? ($write ? 'support.tickets.write' : 'support.tickets.read') : null,
                    'handler' => $write ? null : SupportAttachmentOperations::class,
                    'command' => $write ? SupportAttachmentOperations::class : null];
            }
        }
        return $result;
    }

    public static function commands(): array
    {
        $commands = [];
        foreach (self::all() as $name => $spec) {
            if ($spec['command'] !== null) $commands[$name] = [$spec['command'], $spec['method'], $spec['mode']];
        }
        return $commands;
    }
}
