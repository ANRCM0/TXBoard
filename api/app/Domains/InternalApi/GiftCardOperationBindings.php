<?php

namespace App\Domains\InternalApi;

final class GiftCardOperationBindings
{
    public static function all(): array
    {
        $result = [];
        foreach ([false, true] as $admin) foreach ($admin ? ['list', 'revoke'] : ['list', 'issue', 'redeem', 'revoke'] as $suffix) {
            $write = $suffix !== 'list';
            $result['billing.'.($admin ? 'admin-gift-cards.' : 'gift-cards.').$suffix] = [
                'domains' => [$admin ? 'admin' : 'user'], 'actor' => $admin ? 'admin' : 'user',
                'mode' => $write ? 'domain' : 'read', 'controller' => null, 'method' => ($admin ? 'admin' : '').ucfirst($suffix),
                'rules' => [], 'fields' => [], 'list' => false, 'parameters' => [], 'routeParameters' => [],
                'permission' => $admin ? 'billing.gift-cards.'.($write ? 'manage' : 'read') : null,
                'handler' => $write ? null : GiftCardReadOperations::class, 'command' => $write ? GiftCardMutationOperations::class : null];
        }
        return $result;
    }
    public static function commands(): array
    {
        $result = [];
        foreach (self::all() as $name => $spec) if ($spec['command'] !== null) $result[$name] = [$spec['command'], $spec['method'], 'domain'];
        return $result;
    }
}
