<?php

namespace App\Domains\InternalApi;

final class CommissionOperationBindings
{
    public static function all(): array
    {
        $entries = [
            'identity.referrals.get' => ['referrals', false, false],
            'identity.referrals.code.create' => ['code', true, false],
            'identity.referrals.bind' => ['bind', true, false],
            'billing.commissions.policy.get' => ['policy', false, false],
            'billing.commissions.balance.get' => ['balance', false, false],
            'billing.commissions.rewards.list' => ['rewards', false, false],
            'billing.commissions.payouts.list' => ['payouts', false, false],
            'billing.commissions.payouts.request' => ['requestPayout', true, false],
            'billing.admin-commissions.policy.get' => ['adminPolicy', false, true],
            'billing.admin-commissions.policy.update' => ['policy', true, true],
            'billing.admin-commissions.rewards.list' => ['adminRewards', false, true],
            'billing.admin-commissions.payouts.list' => ['adminPayouts', false, true],
            'billing.admin-commissions.payouts.approve' => ['approve', true, true],
            'billing.admin-commissions.payouts.reject' => ['reject', true, true],
        ];
        $result = [];
        foreach ($entries as $name => [$method, $write, $admin]) $result[$name] = [
            'domains' => [$admin ? 'admin' : 'user'], 'actor' => $admin ? 'admin' : 'user',
            'mode' => $write ? 'domain' : 'read', 'controller' => null, 'method' => $method,
            'rules' => [], 'fields' => [], 'list' => false, 'parameters' => [], 'routeParameters' => [],
            'permission' => $admin ? 'billing.commissions.'.($write ? 'manage' : 'read') : null,
            'handler' => $write ? null : CommissionReadOperations::class, 'command' => $write ? CommissionMutationOperations::class : null];
        return $result;
    }
    public static function commands(): array
    {
        $result = [];
        foreach (self::all() as $name => $spec) if ($spec['command'] !== null) $result[$name] = [$spec['command'], $spec['method'], 'domain'];
        return $result;
    }
}
