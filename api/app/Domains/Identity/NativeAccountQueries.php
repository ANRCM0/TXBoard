<?php

namespace App\Domains\Identity;

use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\Identity\Contracts\AccountIdentity;
use App\Domains\Identity\Contracts\AccountQueries;
use App\Domains\Subscription\Contracts\EntitlementQueries;
use App\Domains\Subscription\Contracts\EntitlementQuery;

final class NativeAccountQueries implements AccountQueries
{
    public function __construct(private readonly EntitlementQueries $entitlements) {}

    public function account(AccountIdentity $identity): array
    {
        return [
            'id' => $identity->userId, 'email' => $identity->email, 'telegram_id' => $identity->telegramId,
            'subscription' => $this->entitlements->forAccount(new EntitlementQuery($identity->userId))->toArray(),
            'credential_status' => 'provider_managed',
        ];
    }

    public function subscription(int $userId): array
    {
        return $this->entitlements->forAccount(new EntitlementQuery($userId))->toArray() + [
            'credential_status' => 'provider_managed', 'delivery_formats' => [RenderGrantCodec::FORMAT],
        ];
    }
}
