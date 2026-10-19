<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Contracts\AdminCatalogQueries;
use Illuminate\Support\Facades\DB;

/** Current and superseded publications remain distinguishable for administrators. */
final class NativeAdminCatalogQueries implements AdminCatalogQueries
{
    public function __construct(private readonly PlanPublicationService $publications) {}

    public function offers(int $page, int $perPage, ?int $planId = null, ?string $status = null): array
    {
        if (DB::transactionLevel() !== 0 || $page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100
            || $planId !== null && $planId < 1 || $status !== null && !in_array($status, ['published', 'superseded'], true)) throw new \InvalidArgumentException('ADMIN_CATALOG_QUERY_INVALID');
        return DB::transaction(function () use ($page, $perPage, $planId, $status): array {
            app(InstallationAuthority::class)->assertNativeRead();
            $query = DB::table('tx_plan_offer as offer')->useWritePdo()
                ->join('tx_plan_offer_availability as availability', 'availability.offer_id', '=', 'offer.id')
                ->join('tx_plan_revision as revision', 'revision.id', '=', 'offer.plan_revision_id')
                ->where('revision.schema_version', PlanPublicationService::SCHEMA);
            if ($planId !== null) $query->where('offer.plan_id', $planId);
            if ($status !== null) $query->where('availability.status', $status);
            $result = $query->orderByDesc('offer.id')->paginate($perPage, ['offer.id', 'availability.show',
                'availability.sell', 'availability.renew', 'availability.status', 'availability.row_version',
                'availability.capacity_limit'], 'page', $page);
            $items = [];
            foreach ($result->items() as $row) {
                $verified = $this->publications->verifiedOffer((int) $row->id);
                $items[] = $this->offerDto($verified['offer'], $verified['source'], $row);
            }
            return ['items' => $items, 'meta' => ['page' => $result->currentPage(), 'per_page' => $result->perPage(),
                'total' => $result->total(), 'last_page' => $result->lastPage()]];
        });
    }

    public function plans(int $page, int $perPage): array
    {
        $this->page($page, $perPage);
        return $this->read(function () use ($page, $perPage): array {
            $result = DB::table('tx_plan')->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('tx_plan_revision')->whereColumn('tx_plan_revision.plan_id', 'tx_plan.id')
                ->where('tx_plan_revision.schema_version', PlanPublicationService::SCHEMA))
                ->orderByDesc('id')->sharedLock()->paginate($perPage, ['id'], 'page', $page);
            $items = [];
            foreach ($result->items() as $plan) {
                $head = $this->head((int) $plan->id);
                if ($head === null) throw new \DomainException('ADMIN_CATALOG_EVIDENCE_INVALID');
                $verified = $this->publications->verifiedRevision((int) $head->id);
                $summary = $this->summary($verified);
                $summary['current_revision_id'] = $summary['revision_id'];
                unset($summary['revision_id']);
                $items[] = $summary;
            }
            return ['items' => $items, 'meta' => $this->meta($result)];
        });
    }

    public function publication(int $planId, ?int $revisionId = null): ?array
    {
        if ($planId < 1 || $revisionId !== null && $revisionId < 1) throw new \InvalidArgumentException('ADMIN_CATALOG_QUERY_INVALID');
        return $this->read(function () use ($planId, $revisionId): ?array {
            if (DB::table('tx_plan')->where('id', $planId)->sharedLock()->first(['id']) === null) return null;
            $head = $this->head($planId);
            if ($head === null) return null;
            $selected = $revisionId ?? (int) $head->id;
            if (!DB::table('tx_plan_revision')->where('id', $selected)->where('plan_id', $planId)
                ->where('schema_version', PlanPublicationService::SCHEMA)->sharedLock()->exists()) return null;
            $verified = $this->publications->verifiedRevision($selected);
            $source = $verified['source'];
            $revision = $verified['revision'];
            $offers = [];
            foreach ($verified['offers'] as $offer) {
                $availability = DB::table('tx_plan_offer_availability')->where('offer_id', $offer->id)->sharedLock()->first();
                if ($availability === null) throw new \DomainException('ADMIN_CATALOG_EVIDENCE_INVALID');
                $offers[] = $this->offerDto($offer, $source, $availability);
            }
            return ['plan_id' => (string) $planId, 'current_revision_id' => (string) $head->id,
                'revision' => ['revision_id' => (string) $revision->id, 'revision_no' => (string) $revision->revision_no,
                    'content_hash' => $revision->content_hash, 'published_at' => (int) $revision->published_at,
                    'terms' => ['name' => $source['name'], 'content' => $source['content'], 'tags' => $source['tags'],
                        'quota_gib' => intdiv((int) $source['quota_bytes'], 1073741824), 'group_id' => $source['group_id'],
                        'speed_limit_mbps' => $source['speed_limit_mbps'] === null ? null : (int) $source['speed_limit_mbps'],
                        'device_limit' => $source['device_limit'] === null ? null : (int) $source['device_limit'],
                        'reset_method' => $source['reset_policy']['method'], 'currency' => $source['currency'],
                        'prices_minor' => $source['prices']]], 'offers' => $offers];
        });
    }

    public function history(int $planId, int $page, int $perPage): ?array
    {
        $this->page($page, $perPage);
        if ($planId < 1) throw new \InvalidArgumentException('ADMIN_CATALOG_QUERY_INVALID');
        return $this->read(function () use ($planId, $page, $perPage): ?array {
            if (DB::table('tx_plan')->where('id', $planId)->sharedLock()->first(['id']) === null) return null;
            $result = DB::table('tx_plan_revision')->where('plan_id', $planId)->where('schema_version', PlanPublicationService::SCHEMA)->orderByDesc('id')
                ->sharedLock()->paginate($perPage, ['id'], 'page', $page);
            return ['items' => array_map(fn (object $revision): array => $this->summary(
                $this->publications->verifiedRevision((int) $revision->id)), $result->items()), 'meta' => $this->meta($result)];
        });
    }

    private function offerDto(object $offer, array $source, object $availability): array
    {
        foreach (['show', 'sell', 'renew'] as $field) {
            if (!in_array($availability->$field, [true, false, 0, 1, '0', '1'], true)) throw new \DomainException('ADMIN_CATALOG_EVIDENCE_INVALID');
        }
        if (!in_array($availability->status, ['published', 'superseded'], true)
            || !preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $availability->row_version)
            || $availability->capacity_limit !== null && (!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $availability->capacity_limit)
                || (int) $availability->capacity_limit > 4294967295)) throw new \DomainException('ADMIN_CATALOG_EVIDENCE_INVALID');
        return ['offer_id' => (string) $offer->id, 'plan_id' => (string) $offer->plan_id,
            'revision_id' => (string) $offer->plan_revision_id, 'name' => $source['name'],
            'offer_code' => $offer->offer_code, 'price_minor' => (string) $offer->price_minor,
            'currency' => $offer->currency, 'status' => $availability->status, 'show' => (bool) $availability->show,
            'sell' => (bool) $availability->sell, 'renew' => (bool) $availability->renew,
            'capacity_limit' => $availability->capacity_limit === null ? null : (string) $availability->capacity_limit,
            'row_version' => (string) $availability->row_version];
    }

    private function head(int $planId): ?object
    {
        return DB::table('tx_plan_revision')->where('plan_id', $planId)->orderByDesc('id')->sharedLock()->first();
    }

    private function summary(array $verified): array
    {
        $revision = $verified['revision'];
        return ['plan_id' => (string) $revision->plan_id, 'revision_id' => (string) $revision->id,
            'revision_no' => (string) $revision->revision_no, 'name' => $verified['source']['name'],
            'currency' => $verified['source']['currency'], 'published_at' => (int) $revision->published_at];
    }

    private function page(int $page, int $perPage): void
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new \InvalidArgumentException('ADMIN_CATALOG_QUERY_INVALID');
    }

    private function read(\Closure $operation): mixed
    {
        if (DB::transactionLevel() !== 0) throw new \InvalidArgumentException('ADMIN_CATALOG_QUERY_INVALID');
        return DB::transaction(function () use ($operation): mixed {
            app(InstallationAuthority::class)->assertNativeRead();
            return $operation();
        }, 3);
    }

    private function meta(object $page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
