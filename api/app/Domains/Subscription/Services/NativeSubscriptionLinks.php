<?php

namespace App\Domains\Subscription\Services;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\Installation\InstallationAuthority;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Link verifiers authorize only finite Provider delivery policies, never proxy material. */
final class NativeSubscriptionLinks
{
    public function __construct(
        private readonly NativeSubscriptionStore $store,
        private readonly SubscriptionEntitlementResolver $entitlements,
        private readonly NativeSubscriptionDelivery $delivery,
    ) {}

    public function issue(int $userId, int $subjectId, int $expiresAt, string $key): array
    {
        $this->fresh();
        $this->require($userId > 0 && $subjectId > 0
            && preg_match('/\A[A-Za-z0-9._~:-]{1,128}\z/D', $key) === 1, 'INPUT_INVALID');
        $requestHash = $this->hash(['user_id' => (string) $userId, 'subject_id' => (string) $subjectId,
            'expires_at' => $expiresAt, 'business_key' => $key]);
        return DB::transaction(function () use ($userId, $subjectId, $expiresAt, $key, $requestHash): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $now = time();
            [$user] = $this->store->lockAccount($userId, $now, $installation['installation_id']);
            $existing = DB::table('tx_subscription_link')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('business_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->verify($existing);
                $this->require(hash_equals($existing->request_hash, $requestHash), 'IDEMPOTENCY_CONFLICT');
                return $this->publicLink($existing) + ['link_token' => null, 'token_delivered' => false];
            }
            $this->require(!(bool) $user->banned && $expiresAt > $now && $expiresAt <= $now + 365 * 86400, 'INPUT_INVALID');
            $entitlement = $this->entitlements->resolveLocked($userId, $now);
            $this->require($entitlement['authorized'] === true, 'ENTITLEMENT_DENIED');
            $subject = DB::table('tx_access_subject')->where('id', $subjectId)->sharedLock()->first();
            $this->require($subject !== null && (string) $subject->subscription_id === $entitlement['subscription_id']
                && $subject->installation_id === $installation['installation_id']
                && $subject->status === 'active' && (int) $subject->credential_expires_at > $now, 'SUBJECT_MISSING');
            $this->require(DB::table('tx_subscription_link')->where('user_id', $userId)->count() < 1000, 'LIMIT_REACHED');
            $token = 'slt_'.RenderGrantCodec::base64url(random_bytes(32));
            $row = [
                'installation_id' => $installation['installation_id'], 'user_id' => $userId,
                'subscription_id' => (int) $subject->subscription_id, 'access_subject_id' => $subjectId,
                'link_ref' => 'link_'.bin2hex(random_bytes(16)), 'bearer_hash' => hash('sha256', $token),
                'business_key' => $key, 'request_hash' => $requestHash, 'status' => 'active', 'row_version' => 1,
                'expires_at' => $expiresAt, 'revoked_at' => null, 'created_at' => $now,
            ];
            $row['content_hash'] = $this->contentHash((object) $row);
            $id = DB::table('tx_subscription_link')->insertGetId($row);
            return $this->publicLink(DB::table('tx_subscription_link')->find($id))
                + ['link_token' => $token, 'token_delivered' => true];
        }, 3);
    }

    public function listing(int $userId, int $afterId = 0): array
    {
        $this->fresh();
        $this->require($userId > 0 && $afterId >= 0, 'INPUT_INVALID');
        return DB::transaction(function () use ($userId, $afterId): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $this->store->lockAccount($userId, time(), $installation['installation_id']);
            $rows = DB::table('tx_subscription_link')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('id', '>', $afterId)->orderBy('id')->limit(101)->sharedLock()->get();
            $items = [];
            foreach ($rows->take(100) as $row) {
                $this->verify($row);
                $items[] = $this->publicLink($row);
            }
            return ['schema' => 'native-subscription-links/v1', 'items' => $items,
                'next_after_id' => $rows->count() > 100 ? (string) $rows[99]->id : null];
        }, 3);
    }

    public function revoke(int $userId, string $reference): array
    {
        $this->fresh();
        $this->reference($reference);
        return DB::transaction(function () use ($userId, $reference): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $this->store->lockAccount($userId, time(), $installation['installation_id']);
            $row = DB::table('tx_subscription_link')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('link_ref', $reference)->lockForUpdate()->first();
            $this->require($row !== null, 'MISSING');
            $this->verify($row);
            if ($row->status === 'active') {
                $row->status = 'revoked';
                $row->revoked_at = time();
                $row->row_version = (int) $row->row_version + 1;
                DB::table('tx_subscription_link')->where('id', $row->id)->update([
                    'status' => $row->status, 'row_version' => $row->row_version, 'revoked_at' => $row->revoked_at,
                    'content_hash' => $this->contentHash($row),
                ]);
            }
            return $this->publicLink($row);
        }, 3);
    }

    public function redeem(string $reference, #[\SensitiveParameter] string $token, string $recipientKey): array
    {
        $this->fresh();
        $this->reference($reference);
        $this->require(preg_match('/\Aslt_[A-Za-z0-9_-]{43}\z/D', $token) === 1, 'MISSING');
        RenderGrantCodec::publicKey($recipientKey);
        $locator = DB::table('tx_subscription_link')->useWritePdo()->where('link_ref', $reference)->first();
        $this->require($locator !== null && hash_equals($locator->bearer_hash, hash('sha256', $token)), 'MISSING');
        $version = (string) $locator->row_version;
        $guard = function () use ($locator, $token, $version): int {
            $row = DB::table('tx_subscription_link')->where('id', $locator->id)->sharedLock()->first();
            $this->require($row !== null, 'MISSING');
            $this->verify($row);
            $this->require($row->installation_id === $locator->installation_id
                && (string) $row->user_id === (string) $locator->user_id
                && (string) $row->subscription_id === (string) $locator->subscription_id
                && (string) $row->access_subject_id === (string) $locator->access_subject_id
                && $row->status === 'active' && (string) $row->row_version === $version
                && (int) $row->expires_at > time()
                && hash_equals($row->bearer_hash, hash('sha256', $token)), 'MISSING');
            return (int) $row->expires_at;
        };
        return $this->delivery->authorize((int) $locator->user_id, (int) $locator->access_subject_id, $recipientKey, $guard);
    }

    private function verify(object $row): void
    {
        $this->require(hash_equals($row->content_hash, $this->contentHash($row))
            && in_array($row->status, ['active', 'revoked'], true)
            && ($row->status === 'revoked') === ($row->revoked_at !== null)
            && (int) $row->row_version > 0, 'EVIDENCE_INVALID');
    }

    private function contentHash(object $row): string
    {
        $payload = [];
        foreach (['installation_id', 'link_ref', 'bearer_hash', 'business_key', 'request_hash', 'status'] as $field) {
            $payload[$field] = $row->$field;
        }
        foreach (['user_id', 'subscription_id', 'access_subject_id', 'row_version'] as $field) {
            $payload[$field] = (string) $row->$field;
        }
        foreach (['expires_at', 'revoked_at', 'created_at'] as $field) {
            $payload[$field] = $row->$field === null ? null : (int) $row->$field;
        }
        return $this->hash($payload);
    }

    private function publicLink(object $row): array
    {
        return ['schema' => 'native-subscription-link/v1', 'id' => (string) $row->id, 'link_ref' => $row->link_ref,
            'subscription_id' => (string) $row->subscription_id, 'access_subject_id' => (string) $row->access_subject_id,
            'status' => $row->status, 'version' => (string) $row->row_version, 'expires_at' => (int) $row->expires_at,
            'revoked_at' => $row->revoked_at === null ? null : (int) $row->revoked_at, 'created_at' => (int) $row->created_at];
    }

    private function reference(string $reference): void
    {
        $this->require(preg_match('/\Alink_[a-f0-9]{32}\z/D', $reference) === 1, 'MISSING');
    }

    private function hash(array $payload): string
    {
        return hash('sha256', ProviderCodec::canonical($payload));
    }

    private function fresh(): void
    {
        $this->require(DB::transactionLevel() === 0, 'OUTER_TRANSACTION_UNSUPPORTED');
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new DomainException('NATIVE_SUBSCRIPTION_LINK_'.$code);
        }
    }
}
