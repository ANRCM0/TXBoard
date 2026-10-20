<?php

namespace App\Domains\Identity;

use App\Domains\Identity\Internal\ReferralSnapshot;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Independent admin notes and immutable sponsor projection; no entitlement or money writes. */
final class NativeAccountMetadata
{
    public function __construct(private readonly ReferralSnapshot $referrals) {}

    public function read(int $userId): array
    {
        if ($userId < 1 || DB::transactionLevel() !== 0) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
        return DB::transaction(function () use ($userId): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            User::query()->whereKey($userId)->sharedLock()->firstOrFail();
            $metadata = $this->snapshot($installation['installation_id'], $userId);
            $referral = $this->referrals->forAccountLocked($installation['installation_id'], $userId);
            $sponsor = $referral === null ? null : User::query()->whereKey($referral['sponsor_id'])->first(['id', 'email']);
            if ($referral !== null && $sponsor === null) throw new DomainException('NATIVE_ACCOUNT_REFERRAL_INVALID');
            return ['remarks' => $metadata['remarks'], 'metadata_revision' => $metadata['revision'],
                'referral' => $referral === null ? null : ['sponsor_id' => (int) $sponsor->id, 'sponsor_email' => (string) $sponsor->email,
                    'bound_at' => (int) $referral['created_at']]];
        });
    }

    public function update(int $actorId, int $userId, string $expectedRevision, ?string $remarks, string $key): array
    {
        if ($userId < 1 || DB::transactionLevel() !== 0 || !preg_match('/\A[0-9a-f]{64}\z/D', $expectedRevision)
            || ($remarks !== null && (mb_strlen($remarks) > 2000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $remarks)))) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
        $remarks = $remarks === null || trim($remarks) === '' ? null : trim($remarks);
        return DB::transaction(function () use ($actorId, $userId, $expectedRevision, $remarks, $key): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $actor = User::query()->whereKey($actorId)->lockForUpdate()->first();
            if ($actor === null || !$actor->is_admin || $actor->banned) throw new DomainException('NATIVE_ACCOUNT_ACTOR_REJECTED');
            app(CurrentSessionAuthorization::class)->assertCurrentLocked($actorId, 'identity.accounts.manage');
            $fingerprint = NativeIdentityAdminReceipts::fingerprint($installation['installation_id'], $actorId, 'metadata', $key,
                ['user_id' => (string) $userId, 'expected_revision' => $expectedRevision, 'remarks' => $remarks]);
            if (($old = NativeIdentityAdminReceipts::recover($fingerprint)) !== null) return $old;
            $target = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($target->is_admin || $target->is_staff || $actorId === $userId) throw new DomainException('NATIVE_ACCOUNT_PROTECTED');
            $old = $this->snapshot($installation['installation_id'], $userId);
            if (!hash_equals($old['revision'], $expectedRevision)) throw new DomainException('NATIVE_ACCOUNT_VERSION_CONFLICT');
            if ($old['remarks'] === $remarks) return NativeIdentityAdminReceipts::append($fingerprint,
                ['id' => $userId, 'remarks' => $remarks, 'metadata_revision' => $old['revision']]);
            $version = $old['version'] + 1;
            $payload = ['user_id' => (string) $userId, 'version' => (string) $version, 'installation_id' => $installation['installation_id'],
                'actor_id' => (string) $actorId, 'remarks' => $remarks, 'created_at' => (string) time()];
            DB::table('tx_native_account_metadata_revision')->insert($payload + ['content_hash' => $this->hash($payload)]);
            DB::table('tx_native_account_metadata_head')->updateOrInsert(['user_id' => $userId], ['installation_id' => $installation['installation_id'], 'version' => $version]);
            $current = $this->snapshot($installation['installation_id'], $userId);
            return NativeIdentityAdminReceipts::append($fingerprint, ['id' => $userId, 'remarks' => $remarks, 'metadata_revision' => $current['revision']]);
        }, 3);
    }

    private function snapshot(string $installation, int $userId): array
    {
        $head = DB::table('tx_native_account_metadata_head')->where('user_id', $userId)->sharedLock()->first();
        $latest = DB::table('tx_native_account_metadata_revision')->where('user_id', $userId)->orderByDesc('version')->sharedLock()->first();
        if ($head === null && $latest === null) return ['version' => 0, 'remarks' => null, 'revision' => $this->hash([$installation, (string) $userId, '0'])];
        if ($head === null || $latest === null || $head->installation_id !== $installation || $latest->installation_id !== $installation
            || (string) $head->version !== (string) $latest->version || !ctype_digit((string) $head->version)
            || (int) $head->version < 1 || (int) $head->version >= PHP_INT_MAX) throw new DomainException('NATIVE_ACCOUNT_METADATA_INVALID');
        $payload = [];
        foreach (['user_id', 'version', 'installation_id', 'actor_id', 'remarks', 'created_at'] as $field) $payload[$field] = $field === 'remarks' ? $latest->$field : (string) $latest->$field;
        if (!hash_equals($latest->content_hash, $this->hash($payload))) throw new DomainException('NATIVE_ACCOUNT_METADATA_INVALID');
        return ['version' => (int) $head->version, 'remarks' => $latest->remarks, 'revision' => $this->hash([$installation, (string) $userId, (string) $head->version, $latest->content_hash])];
    }

    private function hash(array $payload): string { return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key')); }
}
