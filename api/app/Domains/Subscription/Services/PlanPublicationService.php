<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\CatalogValue;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Immutable native publications share one authority during preparation and live management. */
final class PlanPublicationService
{
    public const SCHEMA = 'plan-publication/v1';
    private const CURRENCIES = ['USD', 'CNY', 'EUR', 'GBP', 'HKD', 'SGD', 'CAD', 'AUD', 'NZD', 'TWD'];
    private const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6,
        'yearly' => 12, 'two_yearly' => 24, 'three_yearly' => 36, 'onetime' => null, 'reset_traffic' => null];

    public function __construct(private readonly CatalogValue $values) {}

    public function publish(int $planId, string $publicationKey, string $currency = 'CNY', string $timezone = 'UTC'): array
    {
        if ($planId <= 0 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $publicationKey)) {
            throw new RuntimeException('PUBLICATION_IDENTITY_INVALID');
        }
        if (!in_array($currency, self::CURRENCIES, true)
            || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('PUBLICATION_CONTEXT_INVALID');
        }
        return DB::transaction(function () use ($planId, $publicationKey, $currency, $timezone): array {
            $this->admitLocked();
            $plan = DB::table('tx_plan')->where('id', $planId)->lockForUpdate()->first();
            if (!$plan) {
                throw new RuntimeException('PUBLICATION_PLAN_MISSING');
            }
            $revisions = DB::table('tx_plan_revision')->where('plan_id', $planId)->orderBy('id')->lockForUpdate()->get();
            $offers = DB::table('tx_plan_offer')->where('plan_id', $planId)->orderBy('id')->lockForUpdate()->get();
            $availability = DB::table('tx_plan_offer_availability')->whereIn('offer_id', $offers->pluck('id'))
                ->orderBy('offer_id')->lockForUpdate()->get()->keyBy('offer_id');
            $source = $this->source($plan, $currency, $timezone);
            $hash = $this->values->hash($source);
            $replay = $revisions->firstWhere('publication_key', $publicationKey);
            if ($replay) {
                if ($replay->schema_version !== self::SCHEMA
                    || !hash_equals((string) $replay->publication_request_hash, $hash)) {
                    throw new RuntimeException('PUBLICATION_IDEMPOTENCY_CONFLICT');
                }
                if (!hash_equals($hash, (string) $replay->content_hash)) {
                    throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
                }
                $ownedOffers = $offers->where('plan_revision_id', $replay->id)->values();
                $this->verifyPublication($replay, $ownedOffers->all());
                return $this->result($replay, $ownedOffers->all());
            }
            $initialAvailability = [
                'show' => $this->boolean($plan->show), 'sell' => $this->boolean($plan->sell),
                'renew' => $this->boolean($plan->renew),
                'capacity_limit' => $this->integer($plan->capacity_limit, true, 4294967295),
            ];
            $version = 1;
            foreach ($revisions as $revision) {
                $number = $this->integer($revision->revision_no);
                if ((int) $number === PHP_INT_MAX) {
                    throw new RuntimeException('PUBLICATION_VERSION_OVERFLOW');
                }
                $version = max($version, (int) $number + 1);
            }
            $now = time();
            $revisionId = DB::table('tx_plan_revision')->insertGetId([
                'plan_id' => $planId, 'revision_no' => $version, 'schema_version' => self::SCHEMA,
                'publication_key' => $publicationKey, 'publication_request_hash' => $hash,
                'group_id' => $source['group_id'], 'quota_bytes' => $source['quota_bytes'],
                'speed_limit_mbps' => $source['speed_limit_mbps'], 'device_limit' => $source['device_limit'],
                'reset_policy' => $this->json($source['reset_policy']), 'billing_timezone' => $timezone,
                'source_snapshot' => $this->json($source), 'content_hash' => $hash,
                'published_at' => $now, 'created_at' => $now,
            ]);
            foreach ($offers as $oldOffer) {
                $owner = $revisions->firstWhere('id', $oldOffer->plan_revision_id);
                $oldAvailability = $availability->get($oldOffer->id);
                if ($owner?->schema_version === self::SCHEMA && $oldAvailability?->status === 'published') {
                    if ((int) $oldAvailability->row_version === PHP_INT_MAX) {
                        throw new RuntimeException('PUBLICATION_VERSION_OVERFLOW');
                    }
                    DB::table('tx_plan_offer_availability')->where('offer_id', $oldOffer->id)->update([
                        'status' => 'superseded', 'row_version' => (int) $oldAvailability->row_version + 1,
                        'updated_at' => $now,
                    ]);
                }
            }
            foreach ($source['prices'] as $code => $price) {
                $offer = $this->offer($planId, $revisionId, $version, $code, $price, $source);
                $offerId = DB::table('tx_plan_offer')->insertGetId(array_replace($offer, [
                    'calendar_policy' => $this->json($offer['calendar_policy']),
                ]));
                DB::table('tx_plan_offer_availability')->insert($initialAvailability + [
                    'offer_id' => $offerId, 'status' => 'published', 'row_version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $revision = DB::table('tx_plan_revision')->where('id', $revisionId)->first();
            $created = DB::table('tx_plan_offer')->where('plan_revision_id', $revisionId)->orderBy('id')->get()->all();
            return $this->result($revision, $created);
        }, 3);
    }

    public function setAvailability(int $offerId, array $availability, int $expectedVersion): array
    {
        $keys = array_keys($availability);
        sort($keys, SORT_STRING);
        if ($offerId <= 0 || $expectedVersion <= 0 || $expectedVersion === PHP_INT_MAX
            || $keys !== ['capacity_limit', 'renew', 'sell', 'show']
            || !is_bool($availability['show']) || !is_bool($availability['sell']) || !is_bool($availability['renew'])) {
            throw new RuntimeException('PUBLICATION_AVAILABILITY_INVALID');
        }
        $availability['capacity_limit'] = $this->integer($availability['capacity_limit'], true, 4294967295);
        return DB::transaction(function () use ($offerId, $availability, $expectedVersion): array {
            $this->admitLocked();
            // The locator is immutable; ownership is rechecked after acquiring the Plan lock.
            $locator = DB::table('tx_plan_offer')->where('id', $offerId)->first();
            if (!$locator) {
                throw new RuntimeException('PUBLICATION_OFFER_MISSING');
            }
            if (!DB::table('tx_plan')->where('id', $locator->plan_id)->lockForUpdate()->first()) {
                throw new RuntimeException('PUBLICATION_PLAN_MISSING');
            }
            $revision = DB::table('tx_plan_revision')->where('id', $locator->plan_revision_id)->lockForUpdate()->first();
            $offers = DB::table('tx_plan_offer')->where('plan_revision_id', $locator->plan_revision_id)
                ->orderBy('id')->lockForUpdate()->get();
            $offer = $offers->firstWhere('id', $offerId);
            $current = DB::table('tx_plan_offer_availability')->where('offer_id', $offerId)->lockForUpdate()->first();
            if (!$revision || !$offer || $revision->schema_version !== self::SCHEMA
                || !is_string($revision->publication_key) || $revision->publication_key === ''
                || !is_string($revision->publication_request_hash)
                || !hash_equals($revision->publication_request_hash, (string) $revision->content_hash)
                || (string) $offer->plan_id !== (string) $locator->plan_id
                || (string) $offer->plan_revision_id !== (string) $revision->id
                || (string) $revision->plan_id !== (string) $offer->plan_id
                || !$current || $current->status !== 'published') {
                throw new RuntimeException('PUBLICATION_OFFER_NOT_PUBLISHED');
            }
            $this->verifyPublication($revision, $offers->all());
            if ((string) $current->row_version !== (string) $expectedVersion) {
                throw new RuntimeException('PUBLICATION_AVAILABILITY_VERSION_CONFLICT');
            }
            $changed = DB::table('tx_plan_offer_availability')->where('offer_id', $offerId)
                ->where('row_version', $expectedVersion)->update($availability + [
                    'row_version' => $expectedVersion + 1, 'updated_at' => time(),
                ]);
            if ($changed !== 1) {
                throw new RuntimeException('PUBLICATION_AVAILABILITY_VERSION_CONFLICT');
            }
            return ['offer_id' => (string) $offerId, 'status' => 'published',
                'row_version' => (string) ($expectedVersion + 1)] + $availability;
        }, 3);
    }

    /**
     * Immutable evidence reads do not depend on the current Plan or availability.
     * Purchase preflight requests locks after User/Slot; historical grant reads do not.
     *
     * @return array{revision:object,offer:object,source:array,availability:?object}
     */
    private function admitLocked(): void
    {
        $authority = app(InstallationAuthority::class);
        if (($authority->inspect()['runtime_version'] ?? null) !== null) {
            $authority->assertNativeMutation();
        } else {
            $authority->assertCatalogMutation();
        }
    }

    public function verifiedOffer(int $offerId, bool $lock = false, bool $requirePublished = false): array
    {
        if ($offerId <= 0 || ($requirePublished && !$lock) || ($lock && DB::transactionLevel() === 0)) {
            throw new RuntimeException('PUBLICATION_OFFER_READ_INVALID');
        }
        $locator = DB::table('tx_plan_offer')->where('id', $offerId)->first();
        if (!$locator) {
            throw new RuntimeException('PUBLICATION_OFFER_MISSING');
        }
        if ($lock && !DB::table('tx_plan')->where('id', $locator->plan_id)->lockForUpdate()->first()) {
            throw new RuntimeException('PUBLICATION_PLAN_MISSING');
        }
        $revisions = DB::table('tx_plan_revision')->where('id', $locator->plan_revision_id);
        $offers = DB::table('tx_plan_offer')->where('plan_revision_id', $locator->plan_revision_id)->orderBy('id');
        if ($lock) {
            $revisions->lockForUpdate();
            $offers->lockForUpdate();
        }
        $revision = $revisions->first();
        $ownedOffers = $offers->get();
        $offer = $ownedOffers->firstWhere('id', $offerId);
        if (!$revision || !$offer || $revision->schema_version !== self::SCHEMA
            || (string) $revision->plan_id !== (string) $locator->plan_id
            || (string) $offer->plan_id !== (string) $locator->plan_id) {
            throw new RuntimeException('PUBLICATION_OFFER_NOT_PUBLISHED');
        }
        $this->verifyPublication($revision, $ownedOffers->all());
        $availability = null;
        if ($requirePublished) {
            $availability = DB::table('tx_plan_offer_availability')->where('offer_id', $offerId)
                ->lockForUpdate()->first();
            if (!$availability || $availability->status !== 'published') {
                throw new RuntimeException('PUBLICATION_OFFER_NOT_PUBLISHED');
            }
            foreach (['show', 'sell', 'renew'] as $field) {
                $this->boolean($availability->$field);
            }
            $this->integer($availability->capacity_limit, true, 4294967295);
            if ($this->integer($availability->row_version) === '0') {
                throw new RuntimeException('PUBLICATION_AVAILABILITY_INVALID');
            }
        }
        return ['revision' => $revision, 'offer' => $offer,
            'source' => json_decode($revision->source_snapshot, true, flags: JSON_THROW_ON_ERROR),
            'availability' => $availability];
    }

    /** Owner-verified immutable revision and its complete Offer set, including superseded history. */
    public function verifiedRevision(int $revisionId): array
    {
        if ($revisionId < 1 || DB::transactionLevel() < 1) {
            throw new RuntimeException('PUBLICATION_REVISION_READ_INVALID');
        }
        $revision = DB::table('tx_plan_revision')->useWritePdo()->where('id', $revisionId)->sharedLock()->first();
        if ($revision === null) throw new RuntimeException('PUBLICATION_REVISION_MISSING');
        $offers = DB::table('tx_plan_offer')->useWritePdo()->where('plan_revision_id', $revisionId)
            ->orderBy('id')->sharedLock()->get()->all();
        $this->verifyPublication($revision, $offers);
        return ['revision' => $revision, 'offers' => $offers,
            'source' => json_decode($revision->source_snapshot, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function source(object $plan, string $currency, string $timezone): array
    {
        $quota = $this->integer($plan->transfer_enable);
        if ((int) $quota > intdiv(PHP_INT_MAX, 1073741824)) {
            throw new RuntimeException('PUBLICATION_QUOTA_OUT_OF_RANGE');
        }
        $reset = $this->integer($plan->reset_traffic_method ?? admin_setting('reset_traffic_method', 1));
        if ((int) $reset > 4) {
            throw new RuntimeException('PUBLICATION_RESET_INVALID');
        }
        $prices = [];
        foreach ($this->priceTokens($plan->prices) as $code => $token) {
            if (!array_key_exists($code, self::MONTHS)) {
                throw new RuntimeException('PUBLICATION_PERIOD_INVALID');
            }
            if ($token !== null) {
                $prices[$code] = $this->values->legacyPriceMinor($token);
            }
        }
        if (!$prices) {
            throw new RuntimeException('PUBLICATION_OFFERS_EMPTY');
        }
        $events = [];
        foreach (['new_order_event_id', 'renew_order_event_id', 'change_order_event_id'] as $key) {
            $value = admin_setting($key, 0);
            if (!in_array($value, [0, 1, '0', '1'], true)) {
                throw new RuntimeException('PUBLICATION_EVENT_INVALID');
            }
            $events[$key] = (int) $value;
        }
        $tags = $plan->tags === null ? [] : json_decode($plan->tags, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($tags) || !array_is_list($tags)
            || count(array_filter($tags, 'is_string')) !== count($tags)) {
            throw new RuntimeException('PUBLICATION_TAGS_INVALID');
        }
        return ['schema' => self::SCHEMA, 'plan_id' => (string) $plan->id,
            'name' => (string) $plan->name, 'content' => $plan->content, 'tags' => $tags,
            'group_id' => $this->integer($plan->group_id, true, 2147483647),
            'quota_bytes' => (string) ((int) $quota * 1073741824),
            'speed_limit_mbps' => $this->integer($plan->speed_limit, true, 4294967295),
            'device_limit' => $this->integer($plan->device_limit, true, 4294967295),
            'reset_policy' => ['revision' => 'purchased-reset/v1', 'method' => (int) $reset, 'timezone' => $timezone],
            'billing_timezone' => $timezone, 'currency' => $currency, 'currency_minor_exponent' => 2,
            'calendar_policy' => ['timezone' => 'UTC', 'month_overflow' => Carbon::shouldOverflowMonths()],
            'open_events' => $events, 'prices' => $prices];
    }

    private function offer(int $plan, int $revision, int $version, string $code, string $price, array $source): array
    {
        $data = ['plan_id' => (string) $plan, 'plan_revision_id' => (string) $revision,
            'offer_code' => $code, 'version_no' => (string) $version, 'currency' => $source['currency'],
            'currency_minor_exponent' => 2, 'price_minor' => $price, 'calendar_months' => self::MONTHS[$code],
            'duration_seconds' => null, 'purchase_kind' => match ($code) {
                'onetime' => 'perpetual', 'reset_traffic' => 'traffic_reset', default => 'subscription',
            }, 'calendar_policy' => $source['calendar_policy']];
        return $data + ['content_hash' => $this->values->hash($data), 'created_at' => time()];
    }

    private function verifyPublication(object $revision, array $offers): void
    {
        try {
            $source = json_decode($revision->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $reset = json_decode($revision->reset_policy, true, flags: JSON_THROW_ON_ERROR);
            if ($revision->schema_version !== self::SCHEMA
                || !is_string($revision->publication_key)
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $revision->publication_key)
                || !is_array($source) || ($source['schema'] ?? null) !== self::SCHEMA
                || !hash_equals((string) $revision->content_hash, $this->values->hash($source))
                || !hash_equals((string) $revision->publication_request_hash, (string) $revision->content_hash)) {
                throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
            }
            $this->verifySource($source);
            if ($this->integer($revision->id) === '0' || $this->integer($revision->revision_no) === '0') {
                throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
            }
            foreach (['plan_id', 'group_id', 'quota_bytes', 'speed_limit_mbps', 'device_limit', 'billing_timezone'] as $field) {
                if (!array_key_exists($field, $source)
                    || ($revision->$field === null) !== ($source[$field] === null)
                    || (string) $revision->$field !== (string) $source[$field]) {
                    throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
                }
            }
            if (!is_array($reset) || !is_array($source['reset_policy'] ?? null)
                || !hash_equals($this->values->hash($reset), $this->values->hash($source['reset_policy']))
                || !is_array($source['prices'] ?? null) || !$source['prices']
                || !is_array($source['calendar_policy'] ?? null)) {
                throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
            }
            $this->verifyOffers($revision, $offers, $source);
        } catch (\JsonException|\TypeError|\ValueError $error) {
            throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED', previous: $error);
        }
    }

    private function verifySource(array $source): void
    {
        $this->keys($source, ['schema', 'plan_id', 'name', 'content', 'tags', 'group_id',
            'quota_bytes', 'speed_limit_mbps', 'device_limit', 'reset_policy', 'billing_timezone',
            'currency', 'currency_minor_exponent', 'calendar_policy', 'open_events', 'prices']);
        if (!is_string($source['plan_id']) || $this->integer($source['plan_id']) === '0'
            || !is_string($source['name']) || ($source['content'] !== null && !is_string($source['content']))
            || !is_array($source['tags']) || !array_is_list($source['tags'])
            || count(array_filter($source['tags'], 'is_string')) !== count($source['tags'])
            || !in_array($source['currency'], self::CURRENCIES, true) || $source['currency_minor_exponent'] !== 2
            || !is_string($source['billing_timezone'])
            || !in_array($source['billing_timezone'], DateTimeZone::listIdentifiers(), true)
            || !is_string($source['quota_bytes']) || (int) $this->integer($source['quota_bytes']) % 1073741824 !== 0) {
            throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
        }
        foreach (['group_id' => 2147483647, 'speed_limit_mbps' => 4294967295, 'device_limit' => 4294967295] as $field => $maximum) {
            if ($source[$field] !== $this->integer($source[$field], true, $maximum)) {
                throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
            }
        }
        if (!is_array($source['reset_policy']) || !is_array($source['calendar_policy'])
            || !is_array($source['open_events']) || !is_array($source['prices']) || !$source['prices']) {
            throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
        }
        $this->keys($source['reset_policy'], ['revision', 'method', 'timezone']);
        $this->keys($source['calendar_policy'], ['timezone', 'month_overflow']);
        $this->keys($source['open_events'], ['new_order_event_id', 'renew_order_event_id', 'change_order_event_id']);
        if ($source['reset_policy']['revision'] !== 'purchased-reset/v1'
            || !in_array($source['reset_policy']['method'], [0, 1, 2, 3, 4], true)
            || $source['reset_policy']['timezone'] !== $source['billing_timezone']
            || $source['calendar_policy']['timezone'] !== 'UTC'
            || !is_bool($source['calendar_policy']['month_overflow'])) {
            throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
        }
        foreach ($source['open_events'] as $event) {
            if (!in_array($event, [0, 1], true)) {
                throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
            }
        }
        foreach ($source['prices'] as $code => $price) {
            if (!array_key_exists($code, self::MONTHS) || !is_string($price)
                || $this->integer($price) !== $price) {
                throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
            }
        }
    }

    private function keys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
        }
    }

    private function verifyOffers(object $revision, array $offers, array $source): void
    {
        if (count($offers) !== count($source['prices'])) {
            throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
        }
        $seen = [];
        foreach ($offers as $stored) {
            if (!isset($source['prices'][$stored->offer_code])
                || isset($seen[$stored->offer_code])
                || !array_key_exists($stored->offer_code, self::MONTHS)
                || !is_string($source['prices'][$stored->offer_code])) {
                throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
            }
            $seen[$stored->offer_code] = true;
            $expected = $this->offer((int) $revision->plan_id, (int) $revision->id,
                (int) $revision->revision_no, $stored->offer_code, $source['prices'][$stored->offer_code], $source);
            foreach ($expected as $key => $value) {
                if ($key === 'created_at') {
                    continue;
                }
                $actual = $key === 'calendar_policy' ? json_decode($stored->$key, true, flags: JSON_THROW_ON_ERROR) : $stored->$key;
                if (is_array($value) ? (!is_array($actual) || !hash_equals($this->values->hash($actual), $this->values->hash($value)))
                    : (($value === null) !== ($actual === null) || (string) $value !== (string) $actual)) {
                    throw new RuntimeException('PUBLICATION_EVIDENCE_DAMAGED');
                }
            }
        }
    }

    private function result(object $revision, array $offers): array
    {
        return ['schema' => self::SCHEMA, 'plan_id' => (string) $revision->plan_id,
            'revision_id' => (string) $revision->id, 'revision_no' => (string) $revision->revision_no,
            'publication_key' => $revision->publication_key, 'content_hash' => $revision->content_hash,
            'offers' => array_map(static fn (object $offer): array => [
                'offer_id' => (string) $offer->id, 'offer_code' => $offer->offer_code,
                'price_minor' => (string) $offer->price_minor, 'currency' => $offer->currency,
            ], $offers)];
    }

    private function integer(mixed $value, bool $nullable = false, int $max = PHP_INT_MAX): ?string
    {
        if ($nullable && $value === null) {
            return null;
        }
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)
            || strlen((string) $value) > strlen((string) $max)
            || (strlen((string) $value) === strlen((string) $max) && strcmp((string) $value, (string) $max) > 0)) {
            throw new RuntimeException('PUBLICATION_INTEGER_INVALID');
        }
        return (string) $value;
    }

    private function boolean(mixed $value): bool
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            throw new RuntimeException('PUBLICATION_AVAILABILITY_INVALID');
        }
        return (bool) $value;
    }

    private function priceTokens(mixed $raw): array
    {
        if (!is_string($raw) || strlen($raw) > 16384) {
            throw new RuntimeException('PUBLICATION_PRICES_INVALID');
        }
        try {
            $decoded = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('PUBLICATION_PRICES_INVALID', previous: $error);
        }
        if (!$decoded instanceof \stdClass) {
            throw new RuntimeException('PUBLICATION_PRICES_INVALID');
        }
        $raw = trim($raw);
        if (get_object_vars($decoded) === []) {
            return [];
        }
        $string = '"(?:[^"\\\\]|\\\\.)*"';
        $value = '(?:'.$string.'|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)';
        $pattern = '/\G\s*('.$string.')\s*:\s*('.$value.')\s*(,|\})/A';
        $offset = 1;
        $result = [];
        do {
            if (!preg_match($pattern, $raw, $matches, 0, $offset)) {
                throw new RuntimeException('PUBLICATION_PRICES_INVALID');
            }
            $key = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            if (array_key_exists($key, $result)) {
                throw new RuntimeException('PUBLICATION_DUPLICATE_PRICE');
            }
            $token = $matches[2];
            $result[$key] = $token === 'null' ? null : (str_starts_with($token, '"')
                ? json_decode($token, true, flags: JSON_THROW_ON_ERROR) : $token);
            $offset += strlen($matches[0]);
        } while ($matches[3] === ',');
        if ($offset !== strlen($raw)) {
            throw new RuntimeException('PUBLICATION_PRICES_INVALID');
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
