<?php

namespace App\Domains\Subscription\Internal;

/** Internal transaction participant; never expose through a public operation registry. */
interface LockedEntitlementQueries
{
    /**
     * Requires the current account-first transaction and admission.
     * Invalid evidence must abort the caller's transaction, not become a denied view.
     * The exact scalar array is retained for signed pre/post projection comparison.
     */
    public function resolveLocked(int $userId, int $at): array;
}
