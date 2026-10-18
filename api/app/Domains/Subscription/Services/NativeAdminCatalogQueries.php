<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Contracts\AdminCatalogQueries;
use Illuminate\Support\Facades\DB;

/** Current and superseded publications remain distinguishable for administrators. */
final class NativeAdminCatalogQueries implements AdminCatalogQueries
{
    public function __construct(private readonly PlanPublicationService $publications) {}

    public function offers(int $page, int $perPage, ?int $planId = null): array
    {
        if (DB::transactionLevel() !== 0 || $page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100
            || $planId !== null && $planId < 1) throw new \InvalidArgumentException('ADMIN_CATALOG_QUERY_INVALID');
        return DB::transaction(function () use ($page, $perPage, $planId): array {
            app(InstallationAuthority::class)->assertNativeRead();
            $query = DB::table('tx_plan_offer as offer')->useWritePdo()
                ->join('tx_plan_offer_availability as availability', 'availability.offer_id', '=', 'offer.id');
            if ($planId !== null) $query->where('offer.plan_id', $planId);
            $result = $query->orderByDesc('offer.id')->paginate($perPage, ['offer.id', 'availability.show',
                'availability.sell', 'availability.renew', 'availability.status', 'availability.row_version',
                'availability.capacity_limit'], 'page', $page);
            $items = [];
            foreach ($result->items() as $row) {
                foreach (['show', 'sell', 'renew'] as $field) {
                    if (!in_array($row->$field, [true, false, 0, 1, '0', '1'], true)) {
                        throw new \DomainException('ADMIN_CATALOG_EVIDENCE_INVALID');
                    }
                }
                $verified = $this->publications->verifiedOffer((int) $row->id);
                $offer = $verified['offer'];
                $items[] = ['offer_id' => (string) $offer->id, 'plan_id' => (string) $offer->plan_id,
                    'revision_id' => (string) $offer->plan_revision_id, 'name' => $verified['source']['name'],
                    'offer_code' => $offer->offer_code, 'price_minor' => (string) $offer->price_minor,
                    'currency' => $offer->currency, 'status' => $row->status, 'show' => (bool) $row->show,
                    'sell' => (bool) $row->sell, 'renew' => (bool) $row->renew,
                    'capacity_limit' => $row->capacity_limit === null ? null : (string) $row->capacity_limit,
                    'row_version' => (string) $row->row_version];
            }
            return ['items' => $items, 'meta' => ['page' => $result->currentPage(), 'per_page' => $result->perPage(),
                'total' => $result->total(), 'last_page' => $result->lastPage()]];
        });
    }
}
