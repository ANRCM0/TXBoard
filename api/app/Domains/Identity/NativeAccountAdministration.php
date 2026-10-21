<?php

namespace App\Domains\Identity;

use App\Domains\Identity\Contracts\AccountAdministration;
use App\Domains\Identity\Contracts\CreatedAccount;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Domains\Subscription\Internal\LockedAccountAccess;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use DomainException;
use Illuminate\Support\Facades\DB;

final class NativeAccountAdministration implements AccountAdministration
{
    public function __construct(private readonly NativeIdentityService $identity, private readonly InstallationAdmission $installation,
        private readonly LockedAccountAccess $access) {}

    public function create(int $actorId, string $email, string $password): ?CreatedAccount
    {
        [$created, $user] = $this->identity->create($email, $password, $actorId);
        return $created ? new CreatedAccount((int) $user->id, (string) $user->email) : null;
    }

    public function createBatch(int $actorId, array $accounts, string $key): array
    {
        return $this->identity->createBatch($actorId, $accounts, $key);
    }

    public function profile(int $actorId, int $userId, string $expectedRevision, array $changes, string $key): array
    {
        return $this->identity->updateProfile($actorId, $userId, $expectedRevision, $changes, $key);
    }

    public function status(int $actorId, int $userId, bool $expectedBanned, bool $banned): array
    {
        if (DB::transactionLevel() !== 0) throw new DomainException('NATIVE_ACCOUNT_TRANSACTION_REQUIRED');
        return DB::transaction(function () use ($actorId, $userId, $expectedBanned, $banned): array {
            $installation = $this->installation->assertNativeMutation();
            $actor = User::query()->whereKey($actorId)->sharedLock()->first();
            if ($actor === null || !$actor->is_admin || $actor->banned) throw new DomainException('NATIVE_ACCOUNT_ACTOR_REJECTED');
            [$user, , $owned] = $this->access->lock($installation, $userId, time());
            if ((bool) $user->is_admin || (bool) ($user->is_staff ?? false) || (int) $user->id === $actorId) throw new DomainException('NATIVE_ACCOUNT_PROTECTED');
            if ((bool) $user->banned !== $expectedBanned) throw new DomainException('NATIVE_ACCOUNT_VERSION_CONFLICT');
            if ((bool) $user->banned !== $banned) {
                DB::table('tx_user')->where('id', $user->id)->update(['banned' => $banned, 'updated_at' => time()]);
                $this->access->stage($userId, $owned);
            }
            return ['id' => (int) $user->id, 'banned' => $banned];
        }, 3);
    }
}
