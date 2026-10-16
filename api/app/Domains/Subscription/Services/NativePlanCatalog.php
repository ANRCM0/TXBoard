<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeAdmission;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Published immutable offers; legacy User/Plan counters do not determine sales. */
final class NativePlanCatalog
{
    public const SCHEMA = 'native-plan-offers/v1';

    public function __construct(private readonly PlanPublicationService $publication) {}

    public function available(?int $planId = null): array
    {
        if (DB::transactionLevel() !== 0 || ($planId !== null && $planId <= 0)) {
            throw new DomainException('NATIVE_CATALOG_INPUT_INVALID');
        }
        return DB::transaction(function () use ($planId): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $query = DB::table('tx_plan_offer_availability as availability')
                ->join('tx_plan_offer as offer', 'offer.id', '=', 'availability.offer_id')
                ->where('availability.status', 'published')->where('availability.show', true)
                ->where(static fn ($query) => $query->where('availability.sell', true)->orWhere('availability.renew', true));
            if ($planId !== null) {
                $query->where('offer.plan_id', $planId);
            }
            $rows = $query->orderBy('offer.id')->limit(257)->sharedLock()->get([
                'availability.offer_id', 'availability.show', 'availability.sell', 'availability.renew',
                'availability.capacity_limit', 'availability.row_version',
            ]);
            if ($rows->count() > 256) {
                throw new DomainException('NATIVE_CATALOG_PAGE_LIMIT');
            }
            $result = [];
            foreach ($rows as $row) {
                foreach (['show', 'sell', 'renew'] as $key) {
                    if (!in_array($row->$key, [true, false, 0, 1, '0', '1'], true)) {
                        throw new DomainException('NATIVE_CATALOG_EVIDENCE_INVALID');
                    }
                }
                SubscriptionTermsTimeline::decimal($row->row_version, true);
                $published = $this->publication->verifiedOffer((int) $row->offer_id);
                $offer = $published['offer'];
                $source = $published['source'];
                if (($installation['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION
                    && (string) $offer->price_minor !== '0') {
                    continue;
                }
                $result[] = [
                    'offer_id' => (string) $offer->id, 'plan_id' => (string) $offer->plan_id,
                    'plan_revision_id' => (string) $offer->plan_revision_id,
                    'name' => $source['name'], 'content' => $source['content'], 'tags' => $source['tags'],
                    'offer_code' => $offer->offer_code, 'purchase_kind' => $offer->purchase_kind,
                    'currency' => $offer->currency, 'price_minor' => (string) $offer->price_minor,
                    'currency_minor_exponent' => (int) $offer->currency_minor_exponent,
                    'quota_bytes' => $source['quota_bytes'], 'group_id' => $source['group_id'],
                    'speed_limit_mbps' => $source['speed_limit_mbps'], 'device_limit' => $source['device_limit'],
                    'sell' => (bool) $row->sell, 'renew' => (bool) $row->renew,
                    'availability_version' => (string) $row->row_version,
                    'publication_hash' => $published['revision']->content_hash,
                ];
            }
            return $result;
        }, 3);
    }
}
