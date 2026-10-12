<?php

namespace App\Services\Subscription;

use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\PlanRevision;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Bounded read-only evidence; never repairs a pointer, quote, exception or checkpoint. */
final class PlanCatalogShadowReconciler
{
    public function __construct(private PlanCatalogShadowService $catalog) {}

    public function reconcile(string $runKey, int $limit = 100, int $afterId = 0): array
    {
        if (!config('core_shadow.isolated_copy') || !app()->environment('local', 'testing')) {
            throw new RuntimeException('ISOLATED_COPY_REQUIRED');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $runKey)
            || $limit < 1 || $limit > 1000 || $afterId < 0) {
            throw new InvalidArgumentException('INVALID_CATALOG_BOUNDS');
        }
        return DB::transaction(function () use ($runKey, $limit, $afterId) {
            $checkpoint = DB::table('tx_plan_catalog_checkpoint')->where('run_key', $runKey)->first();
            if (!$checkpoint) { throw new RuntimeException('CATALOG_RUN_NOT_FOUND'); }
            $highWater = (int) $checkpoint->high_water_plan_id;
            if ($afterId > $highWater) { throw new InvalidArgumentException('CATALOG_CURSOR_OUTSIDE_HIGH_WATER'); }
            $details = json_decode($checkpoint->details, true, 32, JSON_THROW_ON_ERROR);
            $context = $details['context'] ?? null;
            if (!is_array($context)) { throw new RuntimeException('CATALOG_CONTEXT_INVALID'); }
            $currentContext = $this->catalog->context((string) ($context['billing_timezone'] ?? ''), (string) ($context['currency'] ?? ''));
            $global = [];
            if ($this->catalog->hash($context) !== $this->catalog->hash($currentContext)) { $global[] = 'CATALOG_CONTEXT_CHANGED'; }
            if ($checkpoint->phase !== 'complete') { $global[] = 'CATALOG_SCAN_INCOMPLETE'; }
            $sourceHighWater = (int) (Plan::query()->max('id') ?? 0);
            if ($sourceHighWater > $highWater) { $global[] = 'CATALOG_HIGH_WATER_GROWTH'; }
            $unresolved = DB::table('tx_plan_catalog_exception')->where('legacy_plan_id', '<=', $highWater)->where('state', 'unresolved')->count();
            if ($unresolved > 0) { $global[] = 'CATALOG_UNRESOLVED_EXCEPTIONS'; }

            // Include evidence whose legacy row or current pointer has been deleted.
            $ids = DB::table('tx_plan')->selectRaw('id as plan_id');
            foreach ([['tx_plan_catalog_shadow', 'legacy_plan_id'], ['tx_plan_catalog_exception', 'legacy_plan_id'],
                ['tx_plan_revision', 'plan_id'], ['tx_plan_offer', 'plan_id']] as [$table, $column]) {
                $ids->union(DB::table($table)->selectRaw($column.' as plan_id'));
            }
            $page = DB::query()->fromSub($ids, 'catalog_ids')->where('plan_id', '>', $afterId)
                ->where('plan_id', '<=', $highWater)->orderBy('plan_id')->limit($limit + 1)->pluck('plan_id');
            $hasMore = $page->count() > $limit;
            $page = $page->take($limit);
            $results = []; $mismatches = 0;
            foreach ($page as $id) {
                try { $issues = $this->inspect((int) $id, $context); }
                catch (Throwable $error) {
                    $issues = [preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage())
                        ? $error->getMessage() : 'CATALOG_EVIDENCE_INVALID'];
                }
                $issues = array_values(array_unique($issues));
                if ($issues) { $mismatches++; }
                $results[] = ['legacy_plan_id' => (string) $id, 'ok' => $issues === [], 'issues' => $issues];
            }
            return ['run_key' => $runKey, 'scope' => 'current-pointer-and-offers', 'read_only' => true,
                'high_water_plan_id' => (string) $highWater, 'source_high_water_plan_id' => (string) $sourceHighWater,
                'after_id' => (string) $afterId, 'next_after_id' => (string) ($page->last() ?? $afterId),
                'has_more' => $hasMore, 'checked_count' => (string) count($results),
                'mismatch_count' => (string) $mismatches, 'unresolved_count' => (string) $unresolved,
                'global_issues' => $global, 'page_ok' => $global === [] && $mismatches === 0, 'results' => $results];
        });
    }

    private function inspect(int $id, array $context): array
    {
        $issues = [];
        $plan = Plan::query()->find($id);
        $pointer = DB::table('tx_plan_catalog_shadow')->where('legacy_plan_id', $id)->first();
        $exception = DB::table('tx_plan_catalog_exception')->where('legacy_plan_id', $id)->first();
        if ($exception?->state === 'unresolved') { $issues[] = 'CATALOG_UNRESOLVED_EXCEPTION'; }
        if (!$plan) { $issues[] = 'CATALOG_LEGACY_PLAN_MISSING'; }
        if (!$pointer) { $issues[] = 'CATALOG_POINTER_MISSING'; return $issues; }
        $revision = PlanRevision::query()->find($pointer->current_revision_id);
        if (!$revision || $revision->plan_id !== $id) { $issues[] = 'CATALOG_POINTER_MISMATCH'; return $issues; }
        if ($revision->schema_version !== PlanCatalogShadowService::VERSION) { $issues[] = 'CATALOG_REVISION_VERSION_INVALID'; }
        if ($revision->revision_no !== (string) PlanRevision::query()->where('plan_id', $id)->max('revision_no')) {
            $issues[] = 'CATALOG_POINTER_NOT_LATEST';
        }
        $snapshot = $revision->source_snapshot;
        if (!is_array($snapshot) || $this->catalog->hash($snapshot) !== $revision->content_hash) {
            $issues[] = 'CATALOG_REVISION_HASH_MISMATCH';
            return $issues;
        }
        if ($pointer->source_hash !== $revision->content_hash) { $issues[] = 'CATALOG_POINTER_HASH_MISMATCH'; }
        $fields = ['group_id', 'quota_bytes', 'speed_limit_mbps', 'device_limit'];
        foreach ($fields as $field) {
            if (!$this->sameScalar($revision->$field, $snapshot[$field] ?? null)) { $issues[] = 'CATALOG_REVISION_FIELDS_MISMATCH'; }
        }
        if ($this->catalog->hash($revision->reset_policy) !== $this->catalog->hash($snapshot['reset_policy'] ?? [])
            || $revision->billing_timezone !== ($snapshot['context']['billing_timezone'] ?? null)
            || ($snapshot['plan_id'] ?? null) !== (string) $id) { $issues[] = 'CATALOG_REVISION_FIELDS_MISMATCH'; }
        if ($plan && $this->catalog->hash($this->catalog->snapshot($plan, $context)) !== $pointer->source_hash) {
            $issues[] = 'CATALOG_SOURCE_CHANGED';
        }

        $offers = PlanOffer::query()->where('plan_revision_id', $revision->id)->orderBy('id')->limit(65)->get();
        if ($offers->count() > 64) { $issues[] = 'CATALOG_OFFER_EVIDENCE_LIMIT'; return $issues; }
        $expectedPrices = $snapshot['prices'] ?? null;
        $snapshotContext = $snapshot['context'] ?? null;
        if (!is_array($expectedPrices) || !is_array($snapshotContext)) {
            $issues[] = 'CATALOG_REVISION_FIELDS_MISMATCH'; return $issues;
        }
        $expectedCodes = array_keys($expectedPrices); $actualCodes = $offers->pluck('offer_code')->all();
        sort($expectedCodes); sort($actualCodes);
        if ($expectedCodes !== $actualCodes) { $issues[] = 'CATALOG_OFFERS_MISMATCH'; }
        $availability = $plan ? $this->catalog->availabilitySnapshot($plan) : null;
        foreach ($offers as $offer) {
            $actual = ['plan_id' => (string) $offer->plan_id, 'plan_revision_id' => (string) $offer->plan_revision_id,
                'offer_code' => $offer->offer_code, 'version_no' => $offer->version_no, 'currency' => $offer->currency,
                'currency_minor_exponent' => $offer->currency_minor_exponent, 'calendar_policy' => $offer->calendar_policy,
                'price_minor' => $offer->price_minor, 'calendar_months' => $offer->calendar_months,
                'duration_seconds' => $offer->duration_seconds, 'purchase_kind' => $offer->purchase_kind];
            if ($this->catalog->hash($actual) !== $offer->content_hash) { $issues[] = 'CATALOG_OFFER_HASH_MISMATCH'; }
            $expected = ['plan_id' => (string) $id, 'plan_revision_id' => (string) $revision->id,
                'offer_code' => $offer->offer_code, 'version_no' => $revision->revision_no,
                'currency' => $snapshotContext['currency'] ?? null, 'currency_minor_exponent' => 2,
                'calendar_policy' => $snapshotContext['calendar_policy'] ?? null] + ($expectedPrices[$offer->offer_code] ?? []);
            if ($this->catalog->hash($actual) !== $this->catalog->hash($expected)) { $issues[] = 'CATALOG_OFFER_FIELDS_MISMATCH'; }
            $state = DB::table('tx_plan_offer_availability')->where('offer_id', $offer->id)->first();
            if (!$state) { $issues[] = 'CATALOG_AVAILABILITY_MISSING'; continue; }
            if ($availability) {
                foreach ($availability as $field => $value) {
                    if (!$this->sameScalar($state->$field, $value)) { $issues[] = 'CATALOG_AVAILABILITY_CHANGED'; }
                }
            }
        }
        return $issues;
    }

    private function sameScalar(mixed $actual, mixed $expected): bool
    {
        return ($actual === null ? null : (string) $actual) === ($expected === null ? null : (string) $expected);
    }
}
