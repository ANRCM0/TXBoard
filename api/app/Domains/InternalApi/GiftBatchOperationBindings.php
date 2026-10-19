<?php

namespace App\Domains\InternalApi;

/** Batch issuance always reserves the user's wallet; administrators get read-only batch access. */
final class GiftBatchOperationBindings
{
    public static function all(): array
    {
        $result = [];
        foreach (['user' => 'billing.gift-batches.', 'admin' => 'billing.admin-gift-batches.'] as $domain => $prefix) {
            foreach (['list' => 'listing', 'get' => 'find'] as $suffix => $method) {
                $result[$prefix.$suffix] = self::spec($domain, false, ($domain === 'admin' ? 'admin'.ucfirst($method) : $method));
            }
        }
        $result['billing.gift-batches.issue'] = self::spec('user', true, 'issueBatch');
        return $result;
    }

    public static function commands(): array
    {
        return ['billing.gift-batches.issue' => [GiftCardMutationOperations::class, 'issueBatch', 'domain']];
    }

    private static function spec(string $domain, bool $write, string $method): array
    {
        return ['domains' => [$domain], 'actor' => $domain, 'mode' => $write ? 'domain' : 'read',
            'controller' => null, 'method' => $method, 'rules' => [], 'fields' => [], 'list' => false,
            'parameters' => [], 'routeParameters' => [],
            'permission' => $domain === 'admin' ? 'billing.gift-cards.read' : null,
            'handler' => $write ? null : GiftBatchReadOperations::class,
            'command' => $write ? GiftCardMutationOperations::class : null];
    }
}
