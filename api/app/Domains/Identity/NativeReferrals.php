<?php

namespace App\Domains\Identity;

use App\Domains\Identity\Contracts\Referrals;
use App\Domains\Identity\Internal\ReferralSnapshot;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One immutable sponsor per account, effective only for future purchase snapshots. */
final class NativeReferrals implements Referrals, ReferralSnapshot
{
    public function __construct(private readonly CurrentSessionAuthorization $sessions) {}

    public function read(int $userId): array
    {
        return $this->owned($userId, false, [], fn (array $installation): array => $this->view($installation, $userId));
    }

    public function execute(int $userId, string $action, array $input, string $key): array
    {
        if (!Str::isUuid($key) || !in_array($action, ['code', 'bind'], true)) self::invalid();
        $locator = null;
        if ($action === 'code') { if ($input !== []) self::invalid(); }
        else {
            if (count($input) !== 1 || !is_string($input['invite_code'] ?? null) || preg_match('/\A[R]1-[A-F0-9]{32}\z/D', $input['invite_code']) !== 1) self::invalid();
            $locator = DB::table('tx_native_referral_code')->useWritePdo()->where('code', $input['invite_code'])->first();
            if ($locator === null) throw new \DomainException('REFERRAL_UNAVAILABLE');
        }
        return $this->owned($userId, true, $locator === null ? [] : [(int) $locator->user_id], function (array $installation) use ($userId, $action, $input, $locator): array {
            if ($action === 'code') {
                $current = DB::table('tx_native_referral_code')->where('user_id', $userId)->lockForUpdate()->first();
                if ($current === null) DB::table('tx_native_referral_code')->insert(['user_id' => $userId, 'installation_id' => $installation['installation_id'],
                    'code' => 'R1-'.strtoupper(bin2hex(random_bytes(16))), 'created_at' => time()]);
            } else {
                $code = DB::table('tx_native_referral_code')->where('code', $input['invite_code'])->sharedLock()->first();
                if ($code === null || $code->installation_id !== $installation['installation_id'] || (int) $code->user_id !== (int) $locator->user_id
                    || (int) $code->user_id === $userId) throw new \DomainException('REFERRAL_UNAVAILABLE');
                $sponsor = DB::table('tx_user')->where('id', $code->user_id)->sharedLock()->first();
                if ($sponsor === null || $sponsor->banned) throw new \DomainException('REFERRAL_UNAVAILABLE');
                $existing = $this->forAccountLocked($installation['installation_id'], $userId);
                if ($existing !== null && $existing['sponsor_id'] !== (string) $code->user_id) throw new \DomainException('REFERRAL_ALREADY_BOUND');
                if ($existing === null) {
                    $payload = ['installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
                        'sponsor_id' => (string) $code->user_id, 'invite_code' => $code->code, 'created_at' => (string) time()];
                    DB::table('tx_native_referral')->insert($payload + ['content_hash' => self::hash($payload)]);
                }
            }
            return $this->view($installation, $userId);
        });
    }

    public function forAccountLocked(string $installationId, int $userId): ?array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('REFERRAL_TRANSACTION_REQUIRED');
        $row = DB::table('tx_native_referral')->where('user_id', $userId)->sharedLock()->first();
        if ($row === null) return null;
        $payload = [];
        foreach (['installation_id', 'user_id', 'sponsor_id', 'invite_code', 'created_at'] as $field) $payload[$field] = (string) $row->$field;
        if ($payload['installation_id'] !== $installationId || $payload['user_id'] !== (string) $userId
            || (int) $payload['sponsor_id'] < 1 || $payload['sponsor_id'] === $payload['user_id']
            || !hash_equals($row->content_hash, self::hash($payload))) throw new \LogicException('REFERRAL_EVIDENCE_INVALID');
        return $payload + ['content_hash' => $row->content_hash];
    }

    private function view(array $installation, int $userId): array
    {
        $code = DB::table('tx_native_referral_code')->where('user_id', $userId)->sharedLock()->first();
        if ($code !== null && $code->installation_id !== $installation['installation_id']) throw new \LogicException('REFERRAL_EVIDENCE_INVALID');
        $binding = $this->forAccountLocked($installation['installation_id'], $userId);
        return ['invite_code' => $code?->code, 'sponsor_id' => $binding['sponsor_id'] ?? null,
            'bound_at' => $binding === null ? null : (int) $binding['created_at']];
    }
    private function owned(int $userId, bool $write, array $users, \Closure $operation): mixed
    {
        if ($userId < 1 || DB::transactionLevel() !== 0) throw new \LogicException('REFERRAL_TRANSACTION_INVALID');
        return DB::transaction(function () use ($userId, $write, $users, $operation): mixed {
            $installation = $write ? app(InstallationAuthority::class)->assertNativeMutation() : app(InstallationAuthority::class)->assertNativeRead();
            $users[] = $userId; $users = array_unique($users); sort($users, SORT_NUMERIC);
            foreach ($users as $id) if (DB::table('tx_user')->where('id', $id)->lockForUpdate()->first() === null) throw new \DomainException('REFERRAL_UNAVAILABLE');
            $this->sessions->assertCurrentLocked($userId);
            return $operation($installation);
        }, 3);
    }
    private static function hash(array $payload): string { return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
    private static function invalid(): never { throw new \InvalidArgumentException('REFERRAL_INPUT_INVALID'); }
}
