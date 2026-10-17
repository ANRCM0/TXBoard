<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\PaymentMethods;
use App\Domains\Billing\Contracts\Recharges;
use App\Domains\Billing\Contracts\WalletPayments;
use App\Models\User;

final class BillingReadOperations
{
    public function __construct(private readonly PaymentMethods $methods, private readonly Recharges $recharges,
        private readonly WalletPayments $wallet) {}

    public function wallet(User $actor, array $input): ReadOperationResult
    {
        return new ReadOperationResult(['schema' => 'native-wallet/v1', 'balances' => $this->wallet->balance((int) $actor->id)]);
    }

    public function methods(User $actor, array $input): ReadOperationResult
    {
        return new ReadOperationResult(array_map(static fn (array $method): array => [
            'id' => $method['id'], 'name' => $method['name'], 'payment' => $method['provider'], 'icon' => $method['icon'],
            'currency' => $method['currency'], 'handling_fee_fixed' => $method['fee_fixed_minor'],
            'handling_fee_percent' => $method['fee_percent'], 'external_refund_supported' => $method['external_refund_supported'],
        ], $this->methods->available()));
    }

    public function recharges(User $actor, array $input): ReadOperationResult
    {
        $rows = $this->recharges->listing((int) $actor->id, (int) ($input['limit'] ?? 20), (int) ($input['after_id'] ?? 0));
        return new ReadOperationResult($rows, ['next_after_id' => $rows === [] ? null : $rows[array_key_last($rows)]['recharge_id']]);
    }

    public function recharge(User $actor, array $input): ReadOperationResult
    {
        return new ReadOperationResult($this->recharges->show((int) $actor->id, $input['trade_no']));
    }
}
