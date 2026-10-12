<?php

namespace App\Services\Subscription;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Current identity evidence only. Never a token verifier or authorization DTO. */
final class LegacySubscriptionIdentityReader
{
    public const ATTRIBUTE = 'core_shadow.subscription_identity';

    public function forLegacyUser(int $userId): array
    {
        if ($userId < 1) {
            return $this->blocked(null, 'IDENTITY_USER_INVALID');
        }
        if (!config('core_shadow.subscription_identity_read') || !config('core_shadow.isolated_copy')
            || !app()->environment('local', 'testing')) {
            return $this->blocked($userId, 'IDENTITY_ISOLATED_COPY_REQUIRED');
        }
        if (DB::transactionLevel() !== 0) {
            return $this->blocked($userId, 'IDENTITY_OUTER_TRANSACTION_UNSUPPORTED');
        }

        try {
            return DB::transaction(function () use ($userId): array {
                // Credentials remain in native SQL: only presence flags and IDs leave it.
                $user = DB::table('tx_user')->where('id', $userId)->select('id')
                    ->selectRaw("CASE WHEN token IS NULL OR LENGTH(TRIM(token)) = 0 THEN 0 ELSE 1 END AS token_present")
                    ->selectRaw("CASE WHEN uuid IS NULL OR LENGTH(TRIM(uuid)) = 0 THEN 0 ELSE 1 END AS uuid_present")
                    ->sharedLock()->first();
                if (!$user) {
                    return $this->blocked($userId, 'IDENTITY_USER_MISSING');
                }
                if (!(int) $user->token_present || !(int) $user->uuid_present) {
                    return $this->blocked($userId, 'IDENTITY_CREDENTIAL_MISSING');
                }
                foreach (['token', 'uuid'] as $field) {
                    $collision = DB::table('tx_user as owner')->join('tx_user as duplicate', function ($join) use ($field) {
                        $join->on('owner.'.$field, '=', 'duplicate.'.$field)->on('owner.id', '<>', 'duplicate.id');
                    })->where('owner.id', $userId)->select('duplicate.id')->limit(1)->sharedLock()->first();
                    if ($collision) {
                        return $this->blocked($userId, 'IDENTITY_'.strtoupper($field).'_AMBIGUOUS');
                    }
                }

                // User-first writers cannot change this aggregate while its shared fence is held.
                $slot = DB::table('tx_subscription_slot')->where('user_id', $userId)
                    ->select(['user_id', 'subscription_id'])->sharedLock()->first();
                $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $userId)
                    ->select(['id', 'legacy_user_id', 'subscription_id', 'terms_id', 'usage_period_id', 'access_subject_id',
                        'migration_key', 'run_key', 'source_hash', 'state', 'error_code', 'row_version'])
                    ->sharedLock()->first();
                if (!$map) {
                    return $this->blocked($userId, 'IDENTITY_MAPPING_MISSING');
                }
                if ($map->state !== 'mapped' || $map->error_code !== null) {
                    return $this->blocked($userId, 'IDENTITY_MAPPING_UNVERIFIED');
                }
                foreach (['id', 'legacy_user_id', 'subscription_id', 'terms_id', 'usage_period_id', 'access_subject_id', 'row_version'] as $field) {
                    if (!$this->positiveDecimal($map->$field)) {
                        return $this->blocked($userId, 'IDENTITY_MAPPING_INVALID');
                    }
                }
                if ($map->migration_key !== LegacySubscriptionSnapshot::VERSION.'/user/'.$userId
                    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $map->run_key)
                    || !preg_match('/^[a-f0-9]{64}$/D', $map->source_hash)) {
                    return $this->blocked($userId, 'IDENTITY_MAPPING_INVALID');
                }
                $ids = [$map->subscription_id];
                if ($slot?->subscription_id !== null) {
                    $ids[] = $slot->subscription_id;
                }
                $subscriptions = DB::table('tx_subscription')->whereIn('id', array_unique($ids))->orderBy('id')
                    ->select(['id', 'user_id'])->limit(2)->sharedLock()->get()->keyBy('id');
                $subscription = $subscriptions->get($map->subscription_id);
                if (!$subscription || (string) $subscription->user_id !== (string) $userId) {
                    return $this->blocked($userId, 'IDENTITY_SUBSCRIPTION_OWNER_INVALID');
                }
                if ($slot?->subscription_id !== null && (string) $slot->subscription_id !== (string) $subscription->id) {
                    return $this->blocked($userId, 'IDENTITY_SLOT_CONFLICT');
                }
                $terms = DB::table('tx_subscription_terms')->where('id', $map->terms_id)
                    ->select(['id', 'subscription_id'])->sharedLock()->first();
                $period = DB::table('tx_usage_period')->where('id', $map->usage_period_id)
                    ->select(['id', 'subscription_id', 'terms_id'])->sharedLock()->first();
                $subject = DB::table('tx_access_subject')->where('id', $map->access_subject_id)
                    ->where('provider_id', 'legacy-shadow')->where('provider_subject_ref', 'user:'.$userId)
                    ->select(['id', 'subscription_id'])->sharedLock()->first();
                if (!$terms || !$period || !$subject
                    || (string) $terms->subscription_id !== (string) $subscription->id
                    || (string) $period->subscription_id !== (string) $subscription->id
                    || (string) $period->terms_id !== (string) $terms->id
                    || (string) $subject->subscription_id !== (string) $subscription->id) {
                    return $this->blocked($userId, 'IDENTITY_PROJECTION_OWNER_INVALID');
                }
                $exception = DB::table('tx_legacy_subscription_exception')->where('legacy_user_id', $userId)
                    ->where('state', 'unresolved')->select('legacy_user_id')->sharedLock()->first();
                if ($exception) {
                    return $this->blocked($userId, 'IDENTITY_EXCEPTION_UNRESOLVED');
                }

                return array_replace($this->blocked($userId, null), [
                    'status' => 'mapped', 'subscription_id' => (string) $subscription->id,
                    'access_subject_id' => (string) $subject->id,
                ]);
            });
        } catch (Throwable) {
            // SQL exceptions may carry bindings; no raw error leaves this optional reader.
            return $this->blocked($userId, 'IDENTITY_READ_FAILED');
        }
    }

    public function blocked(?int $userId, ?string $code): array
    {
        return ['version' => 'legacy-subscription-identity/v1', 'scope' => 'current-legacy-identity',
            'authority' => 'legacy', 'authorizes' => false, 'status' => 'blocked', 'error_code' => $code,
            'legacy_user_id' => $userId === null ? null : (string) $userId,
            'subscription_id' => null, 'access_subject_id' => null];
    }

    private function positiveDecimal(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value)
            && strlen((string) $value) <= 19
            && (strlen((string) $value) < 19 || strcmp((string) $value, (string) PHP_INT_MAX) <= 0);
    }
}
