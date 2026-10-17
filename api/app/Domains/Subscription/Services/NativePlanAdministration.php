<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Billing\MoneyValue;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Installation\InstallationAuthority;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Editable drafts and immutable publication are one native transaction. */
final class NativePlanAdministration
{
    public function publish(int $actorId, array $input, string $key): array
    {
        $this->require(DB::transactionLevel() === 0 && $actorId > 0
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $key) === 1, 'INPUT_INVALID');
        $requestHash = hash('sha256', ProviderCodec::canonical($input));
        return DB::transaction(function () use ($actorId, $input, $key, $requestHash): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $actor = DB::table('tx_user')->where('id', $actorId)->lockForUpdate()->first();
            $this->require($actor !== null && (bool) $actor->is_admin && !(bool) $actor->banned, 'FORBIDDEN');
            $existing = DB::table('tx_native_plan_request')->where('installation_id', $installation['installation_id'])
                ->where('actor_id', $actorId)->where('business_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->require(hash_equals($existing->request_hash, $requestHash), 'IDEMPOTENCY_CONFLICT');
                $result = json_decode($existing->result_snapshot, true, 32, JSON_THROW_ON_ERROR);
                $this->require(hash_equals($existing->result_hash, hash('sha256', ProviderCodec::canonical($result))), 'EVIDENCE_INVALID');
                return $result;
            }
            $groupId = isset($input['group_id']) ? (int) $input['group_id'] : null;
            $this->require($groupId === null || DB::table('tx_server_group')->where('id', $groupId)->exists(), 'GROUP_MISSING');
            $prices = [];
            foreach ($input['prices_minor'] as $period => $amount) {
                $minor = MoneyValue::minor($amount);
                $padded = str_pad($minor, 3, '0', STR_PAD_LEFT);
                $prices[$period] = substr($padded, 0, -2).'.'.substr($padded, -2);
            }
            $now = time();
            $attributes = ['name' => $input['name'], 'content' => $input['content'] ?? null,
                'tags' => ProviderCodec::canonical($input['tags'] ?? []), 'transfer_enable' => (int) $input['quota_gib'],
                'group_id' => $groupId, 'speed_limit' => $input['speed_limit_mbps'] ?? null,
                'device_limit' => $input['device_limit'] ?? null, 'capacity_limit' => $input['capacity_limit'] ?? null,
                'reset_traffic_method' => $input['reset_method'],
                'prices' => ProviderCodec::canonical($prices), 'updated_at' => $now];
            $planId = isset($input['plan_id']) ? (int) $input['plan_id'] : null;
            if ($planId !== null) {
                $plan = DB::table('tx_plan')->where('id', $planId)->lockForUpdate()->first();
                $this->require($plan !== null, 'MISSING');
                DB::table('tx_plan')->where('id', $planId)->update($attributes);
            } else {
                $planId = DB::table('tx_plan')->insertGetId($attributes + [
                    'show' => true, 'sell' => true, 'renew' => true, 'created_at' => $now]);
            }
            // The publication service joins this transaction, so draft and
            // immutable Revision/Offers cannot commit separately.
            $publication = app(PlanPublicationService::class)->publish($planId,
                'native-'.substr(hash('sha256', $actorId.':'.$key), 0, 48), $input['currency'], 'UTC');
            $result = ['schema' => 'native-plan-publication/v1', 'plan_id' => (string) $planId,
                'publication' => $publication];
            DB::table('tx_native_plan_request')->insert([
                'installation_id' => $installation['installation_id'], 'actor_id' => $actorId, 'plan_id' => $planId,
                'business_key' => $key, 'request_hash' => $requestHash,
                'result_snapshot' => ProviderCodec::canonical($result),
                'result_hash' => hash('sha256', ProviderCodec::canonical($result)), 'created_at' => $now,
            ]);
            return $result;
        }, 3);
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) { throw new DomainException('NATIVE_PLAN_'.$code); }
    }
}
