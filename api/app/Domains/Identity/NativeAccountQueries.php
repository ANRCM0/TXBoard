<?php

namespace App\Domains\Identity;

use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\Identity\Contracts\AccountIdentity;
use App\Domains\Identity\Contracts\AccountQueries;
use App\Domains\Subscription\Contracts\EntitlementQueries;
use App\Domains\Subscription\Contracts\EntitlementQuery;
use App\Domains\Identity\Contracts\AdminAccountQueries;
use App\Domains\Identity\Contracts\AdminAccountListQuery;
use App\Models\User;

final class NativeAccountQueries implements AccountQueries, AdminAccountQueries
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

    public function adminListing(AdminAccountListQuery $query): array
    {
        $users = User::query()->useWritePdo();
        if ($query->email !== null) $users->where('email', $query->email);
        if ($query->banned !== null) $users->where('banned', $query->banned);
        $page = $users->orderByDesc('id')->paginate($query->perPage,
            ['id', 'email', 'banned', 'is_admin', 'created_at', 'last_login_at'], 'page', $query->page);
        return ['items' => $page->getCollection()->map(self::adminProfile(...))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }

    public function adminFind(int $userId): ?array
    {
        if ($userId < 1) throw new \InvalidArgumentException('ADMIN_ACCOUNT_QUERY_INVALID');
        $user = User::query()->useWritePdo()->find($userId,
            ['id', 'email', 'banned', 'is_admin', 'created_at', 'last_login_at']);
        if ($user === null) return null;
        return self::adminProfile($user) + [
            'subscription' => $this->entitlements->forAccount(new EntitlementQuery($userId))->toArray(),
            'credential_status' => 'provider_managed',
        ];
    }

    private static function adminProfile(User $user): array
    {
        return ['id' => (int) $user->id, 'email' => (string) $user->email,
            'banned' => (bool) $user->banned, 'is_admin' => (bool) $user->is_admin,
            'created_at' => $user->created_at === null ? null : (int) $user->created_at,
            'last_login_at' => $user->last_login_at === null ? null : (int) $user->last_login_at];
    }
}
