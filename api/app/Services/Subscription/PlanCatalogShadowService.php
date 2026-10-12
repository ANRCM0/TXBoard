<?php

namespace App\Services\Subscription;

use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\PlanRevision;
use App\Services\OrderService;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Current catalog evidence only. Does not change legacy quotes, sale state or User Terms. */
final class PlanCatalogShadowService
{
    public const VERSION = 'plan-catalog-shadow/v1';
    private const CURRENCIES = ['USD', 'CNY', 'EUR', 'GBP', 'HKD', 'SGD', 'CAD', 'AUD', 'NZD', 'TWD'];

    public function run(string $runKey, int $limit, array $context, ?int $retryPlan = null): array
    {
        if (!config('core_shadow.isolated_copy') || !app()->environment('local', 'testing')) {
            throw new RuntimeException('ISOLATED_COPY_REQUIRED');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $runKey) || $limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('INVALID_CATALOG_BOUNDS');
        }
        $context = $this->context((string) ($context['billing_timezone'] ?? ''), (string) ($context['currency'] ?? ''));
        return DB::transaction(function () use ($runKey, $limit, $context, $retryPlan) {
            $now = time();
            DB::table('tx_plan_catalog_checkpoint')->insertOrIgnore([
                'run_key' => $runKey, 'phase' => 'processing', 'cursor_plan_id' => 0,
                'high_water_plan_id' => Plan::query()->max('id') ?? 0, 'processed_count' => 0,
                'details' => $this->json(['context' => $context, 'started_at' => $now, 'clock' => 'utc-unix-seconds']),
                'row_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $checkpoint = DB::table('tx_plan_catalog_checkpoint')->where('run_key', $runKey)->lockForUpdate()->first();
            $details = json_decode($checkpoint->details, true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($this->hash($details['context']), $this->hash($context))) {
                throw new RuntimeException('CATALOG_CONTEXT_CHANGED');
            }
            if ($retryPlan !== null) {
                if ($retryPlan < 1 || $retryPlan > $checkpoint->high_water_plan_id) {
                    throw new InvalidArgumentException('CATALOG_RETRY_OUTSIDE_HIGH_WATER');
                }
                $ids = [$retryPlan];
            } else {
                $ids = Plan::query()->where('id', '>', $checkpoint->cursor_plan_id)
                    ->where('id', '<=', $checkpoint->high_water_plan_id)->orderBy('id')->limit($limit)->pluck('id')->all();
            }
            $results = [];
            foreach ($ids as $id) {
                try {
                    $results[] = DB::transaction(fn () => $this->capture((int) $id, $runKey, $context, $now));
                    DB::table('tx_plan_catalog_exception')->where('legacy_plan_id', $id)
                        ->update(['state' => 'resolved', 'updated_at' => $now]);
                } catch (Throwable $error) {
                    $code = $error instanceof RuntimeException && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage())
                        ? $error->getMessage() : 'CATALOG_CAPTURE_FAILED';
                    $previous = DB::table('tx_plan_catalog_exception')->where('legacy_plan_id', $id)->lockForUpdate()->first();
                    DB::table('tx_plan_catalog_exception')->updateOrInsert(['legacy_plan_id' => $id], [
                        'run_key' => $runKey, 'state' => 'unresolved', 'error_code' => $code,
                        'attempts' => ($previous?->attempts ?? 0) + 1,
                        'created_at' => $previous?->created_at ?? $now, 'updated_at' => $now,
                    ]);
                    $results[] = ['legacy_plan_id' => (string) $id, 'status' => 'exception', 'error_code' => $code];
                }
            }
            $cursor = $retryPlan === null && $ids ? (int) end($ids) : (int) $checkpoint->cursor_plan_id;
            $more = Plan::query()->where('id', '>', $cursor)->where('id', '<=', $checkpoint->high_water_plan_id)->exists();
            $processed = (int) $checkpoint->processed_count + ($retryPlan === null ? count($ids) : 0);
            $phase = $more ? 'processing' : 'complete';
            DB::table('tx_plan_catalog_checkpoint')->where('id', $checkpoint->id)->update([
                'cursor_plan_id' => $cursor, 'processed_count' => $processed, 'phase' => $phase,
                'row_version' => $checkpoint->row_version + 1, 'updated_at' => $now,
            ]);
            return ['run_key' => $runKey, 'phase' => $phase, 'cursor_plan_id' => (string) $cursor,
                'high_water_plan_id' => (string) $checkpoint->high_water_plan_id, 'processed_count' => (string) $processed,
                'unresolved_count' => (string) DB::table('tx_plan_catalog_exception')->where('state', 'unresolved')->count(),
                'results' => $results];
        }, 3);
    }

    public function context(string $timezone, string $currency): array
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('INVALID_CATALOG_TIMEZONE');
        }
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new InvalidArgumentException('CATALOG_CURRENCY_UNSUPPORTED');
        }
        return ['mapping_version' => self::VERSION, 'billing_timezone' => $timezone, 'currency' => $currency,
            'currency_minor_exponent' => 2, 'legacy_price_multiplier' => 100,
            'calendar_policy' => ['revision' => 'legacy-carbon-addMonths/v1',
                'expiry_timezone' => 'UTC', 'month_overflow' => Carbon::shouldOverflowMonths()]];
    }

    /** Exact arithmetic precedes the parity check against the current authoritative quote. */
    public function legacyPriceMinor(mixed $value): string
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new RuntimeException('CATALOG_PRICE_INVALID');
            }
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || strlen($value) > 128
            || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]{1,4}))?$/D', $value, $parts)) {
            throw new RuntimeException('CATALOG_PRICE_INVALID');
        }
        $fraction = $parts[2] ?? '';
        $exponent = isset($parts[3]) ? (int) $parts[3] : 0;
        if (abs($exponent) > 128) {
            throw new RuntimeException('CATALOG_PRICE_OUT_OF_RANGE');
        }
        $digits = $parts[1].$fraction;
        $minorPoint = strlen($parts[1]) + $exponent + 2;
        if ($minorPoint < strlen($digits)) {
            $discarded = $minorPoint < 0 ? $digits : substr($digits, $minorPoint);
            if (trim($discarded, '0') !== '') {
                throw new RuntimeException('CATALOG_PRICE_PRECISION_UNSUPPORTED');
            }
            $digits = $minorPoint <= 0 ? '0' : substr($digits, 0, $minorPoint);
        } else {
            $digits .= str_repeat('0', $minorPoint - strlen($digits));
        }
        $minor = ltrim($digits, '0') ?: '0';
        if (strlen($minor) > strlen((string) PHP_INT_MAX)
            || (strlen($minor) === strlen((string) PHP_INT_MAX) && strcmp($minor, (string) PHP_INT_MAX) > 0)) {
            throw new RuntimeException('CATALOG_PRICE_OUT_OF_RANGE');
        }
        if ((string) (int) round((float) $value * 100) !== $minor) {
            throw new RuntimeException('CATALOG_LEGACY_QUOTE_PRECISION_MISMATCH');
        }
        return $minor;
    }

    public function previewCalendarEnd(PlanOffer $offer, int $anchor): ?int
    {
        if ($offer->purchase_kind !== 'subscription') {
            return null;
        }
        if ($anchor < 0 || $offer->calendar_months === null) {
            throw new InvalidArgumentException('INVALID_CATALOG_CALENDAR_ANCHOR');
        }
        $policy = $offer->calendar_policy;
        return Carbon::createFromTimestamp($anchor, $policy['expiry_timezone'])
            ->settings(['monthOverflow' => $policy['month_overflow']])->addMonths($offer->calendar_months)->timestamp;
    }

    /** Pure current-source projection shared by capture and read-only reconciliation. */
    public function snapshot(Plan $plan, array $context): array
    {
        $id = (int) $plan->id;
        $prices = $this->priceTokens($plan->getRawOriginal('prices'));
        $quotaGiB = $this->integer($plan->getRawOriginal('transfer_enable'), true);
        if ($quotaGiB !== null && (int) $quotaGiB > intdiv(PHP_INT_MAX, 1073741824)) {
            throw new RuntimeException('CATALOG_QUOTA_OUT_OF_RANGE');
        }
        $quotaBytes = $quotaGiB === null ? null : (string) ((int) $quotaGiB * 1073741824);
        $rawReset = $plan->getRawOriginal('reset_traffic_method');
        $reset = $rawReset === null ? admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY) : $rawReset;
        $reset = $this->integer($reset);
        if (!in_array($reset, ['0', '1', '2', '3', '4'], true)) {
            throw new RuntimeException('CATALOG_RESET_POLICY_INVALID');
        }
        $offers = [];
        foreach ($prices as $code => $token) {
            if (!array_key_exists($code, Plan::getAvailablePeriods())) {
                throw new RuntimeException('CATALOG_PERIOD_UNSUPPORTED');
            }
            if ($token !== null) {
                $offers[$code] = ['price_minor' => $this->legacyPriceMinor($token),
                    'calendar_months' => OrderService::STR_TO_TIME[$code] ?? null,
                    'duration_seconds' => null, 'purchase_kind' => $code === Plan::PERIOD_ONETIME ? 'perpetual'
                        : ($code === Plan::PERIOD_RESET_TRAFFIC ? 'traffic_reset' : 'subscription')];
            }
        }
        $source = ['capture_kind' => 'current_catalog_not_historical', 'plan_id' => (string) $id,
            'name' => (string) $plan->name, 'content' => $plan->content, 'tags' => $plan->tags,
            'group_id' => $this->integer($plan->getRawOriginal('group_id'), true), 'quota_gib' => $quotaGiB,
            'quota_bytes' => $quotaBytes, 'quota_semantics' => $quotaGiB === null ? 'legacy_null_unknown' : 'finite',
            'speed_limit_mbps' => $this->integer($plan->getRawOriginal('speed_limit'), true),
            'device_limit' => $this->integer($plan->getRawOriginal('device_limit'), true),
            'reset_policy' => ['revision' => 'legacy-current/v1', 'method' => (int) $reset,
                'plan_method' => $rawReset === null ? null : (int) $rawReset, 'indefinite_expiry_resets' => false],
            'prices' => $offers, 'context' => $context];
        return $source;
    }

    private function capture(int $id, string $runKey, array $context, int $now): array
    {
        $plan = Plan::query()->lockForUpdate()->find($id);
        if ($plan === null) {
            throw new RuntimeException('CATALOG_LEGACY_PLAN_MISSING');
        }
        $source = $this->snapshot($plan, $context);
        $offers = $source['prices'];
        $quotaBytes = $source['quota_bytes'];
        $hash = $this->hash($source);
        $pointer = DB::table('tx_plan_catalog_shadow')->where('legacy_plan_id', $id)->lockForUpdate()->first();
        if ($pointer !== null && hash_equals($pointer->source_hash, $hash)) {
            // The run's high-water read may have established a REPEATABLE READ
            // snapshot before another run acquired and committed the Plan lock.
            $revision = PlanRevision::query()->where('plan_id', $id)->sharedLock()->find($pointer->current_revision_id);
            if ($revision === null || !hash_equals($revision->content_hash, $hash)) {
                throw new RuntimeException('CATALOG_POINTER_MISMATCH');
            }
            $this->availability($revision, $plan, $now);
            return ['legacy_plan_id' => (string) $id, 'status' => 'unchanged', 'revision_id' => (string) $revision->id];
        }
        $version = ((int) PlanRevision::query()->where('plan_id', $id)
            ->orderByDesc('revision_no')->lockForUpdate()->value('revision_no')) + 1;
        $revision = PlanRevision::create([
            'plan_id' => $id, 'revision_no' => $version, 'schema_version' => self::VERSION,
            'group_id' => $source['group_id'], 'quota_bytes' => $quotaBytes, 'speed_limit_mbps' => $source['speed_limit_mbps'],
            'device_limit' => $source['device_limit'], 'reset_policy' => $source['reset_policy'],
            'billing_timezone' => $context['billing_timezone'], 'source_snapshot' => $source,
            'content_hash' => $hash, 'published_at' => $now, 'created_at' => $now,
        ]);
        foreach ($offers as $code => $terms) {
            $offer = ['plan_id' => (string) $id, 'plan_revision_id' => (string) $revision->id, 'offer_code' => $code,
                'version_no' => (string) $version, 'currency' => $context['currency'], 'currency_minor_exponent' => 2,
                'calendar_policy' => $context['calendar_policy']] + $terms;
            PlanOffer::create($offer + ['content_hash' => $this->hash($offer), 'created_at' => $now]);
        }
        if ($pointer !== null) {
            $oldOffers = PlanOffer::query()->where('plan_revision_id', $pointer->current_revision_id)->sharedLock()->pluck('id');
            DB::table('tx_plan_offer_availability')->whereIn('offer_id', $oldOffers)->update([
                'status' => 'superseded', 'row_version' => DB::raw('row_version + 1'), 'updated_at' => $now,
            ]);
        }
        $this->availability($revision, $plan, $now);
        DB::table('tx_plan_catalog_shadow')->updateOrInsert(['legacy_plan_id' => $id], [
            'current_revision_id' => $revision->id, 'source_hash' => $hash, 'run_key' => $pointer?->run_key ?? $runKey,
            'row_version' => ($pointer?->row_version ?? 0) + 1, 'created_at' => $pointer?->created_at ?? $now, 'updated_at' => $now,
        ]);
        return ['legacy_plan_id' => (string) $id, 'status' => $pointer === null ? 'mapped' : 'revised',
            'revision_id' => (string) $revision->id, 'revision_no' => (string) $version];
    }

    private function availability(PlanRevision $revision, Plan $plan, int $now): void
    {
        $values = $this->availabilitySnapshot($plan);
        foreach ($revision->offers()->orderBy('id')->sharedLock()->get() as $offer) {
            $old = DB::table('tx_plan_offer_availability')->where('offer_id', $offer->id)->lockForUpdate()->first();
            $changed = $old === null;
            foreach ($values as $field => $value) {
                if ($old !== null && ($old->$field === null ? null : (string) $old->$field) !== ($value === null ? null : (string) $value)) {
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('tx_plan_offer_availability')->updateOrInsert(['offer_id' => $offer->id], $values + [
                    'row_version' => ($old?->row_version ?? 0) + 1, 'created_at' => $old?->created_at ?? $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function availabilitySnapshot(Plan $plan): array
    {
        return ['status' => 'shadow', 'show' => (int) (bool) $plan->show, 'sell' => (int) (bool) $plan->sell,
            'renew' => (int) (bool) $plan->renew, 'capacity_limit' => $this->integer($plan->getRawOriginal('capacity_limit'), true)];
    }

    private function priceTokens(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_string($raw) || strlen($raw) > 16384) {
            throw new RuntimeException('CATALOG_PRICES_JSON_INVALID');
        }
        try {
            $decoded = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('CATALOG_PRICES_JSON_INVALID');
        }
        if ($decoded === []) {
            return [];
        }
        if (!$decoded instanceof \stdClass) {
            throw new RuntimeException('CATALOG_PRICES_JSON_INVALID');
        }
        $raw = trim($raw);
        if (get_object_vars($decoded) === []) {
            return [];
        }
        $stringToken = '"(?:[^"\\\\]|\\\\.)*"';
        $valueToken = '(?:'.$stringToken.'|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)';
        $pattern = '/\G\s*('.$stringToken.')\s*:\s*('.$valueToken.')\s*(,|\})/A';
        $offset = 1;
        $result = [];
        do {
            if (!preg_match($pattern, $raw, $matches, 0, $offset)) {
                throw new RuntimeException('CATALOG_PRICES_JSON_INVALID');
            }
            $key = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            if (array_key_exists($key, $result)) {
                throw new RuntimeException('CATALOG_DUPLICATE_PRICE_KEY');
            }
            $token = $matches[2];
            $result[$key] = $token === 'null' ? null : (str_starts_with($token, '"')
                ? json_decode($token, true, flags: JSON_THROW_ON_ERROR) : $token);
            $offset += strlen($matches[0]);
        } while ($matches[3] === ',');
        if ($offset !== strlen($raw)) {
            throw new RuntimeException('CATALOG_PRICES_JSON_INVALID');
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function integer(mixed $value, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('CATALOG_INTEGER_INVALID');
        }
        $value = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || (string) (int) $value !== $value) {
            throw new RuntimeException('CATALOG_INTEGER_OUT_OF_RANGE');
        }
        return $value;
    }

    public function hash(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function json(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
