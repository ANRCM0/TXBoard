<?php

namespace App\Contracts;

/** Trusted in-process protocol implementation; never owns Core money or entitlement writes. */
interface NativePaymentProviderInterface
{
    public function identity(): array;
    public function capabilities(): array;
    public function accountRef(object $payment): string;
    public function freeze(object $payment, array $attempt): array;
    public function checkout(array $snapshot): array;
    public function notificationReference(array $params): ?string;
    public function verifyNotification(array $snapshot, array $params): array;
    public function query(array $snapshot): ?array;
    public function acknowledgement(): string;
}
