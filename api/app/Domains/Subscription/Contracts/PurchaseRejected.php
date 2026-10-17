<?php

namespace App\Domains\Subscription\Contracts;

/** A confirmed policy rejection after the owner rolled back the complete purchase transaction. */
final class PurchaseRejected extends \RuntimeException
{
    public function __construct(public readonly string $errorCode) { parent::__construct($errorCode); }
}
