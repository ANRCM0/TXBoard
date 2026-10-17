<?php

namespace App\Domains\Subscription\Contracts;

interface EntitlementQueries
{
    /**
     * Read current non-secret entitlements in a fresh owned transaction.
     * The ingress must authenticate and authorize the target account.
     */
    public function forAccount(EntitlementQuery $query): EntitlementView;
}
