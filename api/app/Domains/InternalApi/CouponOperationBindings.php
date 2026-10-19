<?php

namespace App\Domains\InternalApi;

final class CouponOperationBindings
{
    public static function all(): array
    {
        $result = [];
        foreach (['list' => 'listing', 'get' => 'find', 'create' => 'create', 'disable' => 'disable'] as $suffix => $method) {
            $write = in_array($suffix, ['create', 'disable'], true);
            $result['billing.coupons.'.$suffix] = ['domains' => ['admin'], 'actor' => 'admin',
                'mode' => $write ? 'domain' : 'read', 'controller' => null, 'method' => $method,
                'rules' => [], 'fields' => [], 'list' => false, 'parameters' => [], 'routeParameters' => [],
                'permission' => $write ? 'billing.coupons.manage' : 'billing.coupons.read',
                'handler' => $write ? null : CouponReadOperations::class,
                'command' => $write ? CouponMutationOperations::class : null];
        }
        return $result;
    }
    public static function commands(): array
    {
        return ['billing.coupons.create' => [CouponMutationOperations::class, 'create', 'domain'],
            'billing.coupons.disable' => [CouponMutationOperations::class, 'disable', 'domain']];
    }
}
