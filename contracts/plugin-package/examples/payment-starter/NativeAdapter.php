<?php

namespace Plugin\ExamplePay;

use App\Contracts\NativePaymentPluginInterface;
use App\Contracts\NativePaymentProviderInterface;
use LogicException;

/** Deliberately unavailable until the actual protocol and its tests are implemented. */
final class NativeAdapter implements NativePaymentProviderInterface
{
    public function identity(): array
    {
        return ['provider_id' => 'ExamplePay', 'contract_version' => NativePaymentPluginInterface::CONTRACT,
            'snapshot_schema' => 'examplepay-dispatch/1'];
    }

    public function capabilities(): array
    {
        return ['checkout' => false, 'verify_notification' => false, 'query' => false,
            'external_refund' => false, 'query_refund' => false, 'currencies' => ['CNY'],
            'currency_minor_exponent' => '2'];
    }

    public function accountRef(object $payment): string
    {
        throw new LogicException('Implement canonical non-secret merchant identity');
    }

    public function freeze(object $payment, array $attempt): array
    {
        throw new LogicException('Implement immutable protocol configuration and exact attempt binding');
    }

    public function checkout(array $snapshot): array
    {
        throw new LogicException('Implement side-effect-free HTTPS redirect generation');
    }

    public function notificationReference(array $params): ?string
    {
        return null;
    }

    public function verifyNotification(array $snapshot, array $params): array
    {
        throw new LogicException('Implement strict authenticated callback verification');
    }

    public function query(array $snapshot): ?array
    {
        throw new LogicException('Implement bounded authenticated provider query');
    }

    public function acknowledgement(): string
    {
        return 'success';
    }
}
