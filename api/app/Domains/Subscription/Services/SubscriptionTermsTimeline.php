<?php

namespace App\Domains\Subscription\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only half-open Terms projection. Original Terms and transitions are never rewritten. */
final class SubscriptionTermsTimeline
{
    public function resolve(string $subscriptionId, bool $currentRead = false): array
    {
        self::decimal($subscriptionId, true);
        $subQuery = DB::table('tx_subscription')->where('id', $subscriptionId);
        $sub = ($currentRead ? $subQuery->sharedLock() : $subQuery)->first();
        if (!$sub) {
            throw new DomainException('TERMS_SUBSCRIPTION_MISSING');
        }
        if ($sub->current_terms_id === null) {
            throw new DomainException('TERMS_TIMELINE_TIP_MISSING');
        }
        $termsQuery = DB::table('tx_subscription_terms')->where('subscription_id', $subscriptionId)->orderBy('version_no')->limit(1001);
        $transitionsQuery = DB::table('tx_subscription_terms_transition')->where('subscription_id', $subscriptionId)->orderBy('id')->limit(1001);
        $terms = ($currentRead ? $termsQuery->sharedLock() : $termsQuery)->get()->map(fn ($row) => (array) $row)->all();
        $transitions = ($currentRead ? $transitionsQuery->sharedLock() : $transitionsQuery)->get()->map(fn ($row) => (array) $row)->all();
        if (count($terms) > 1000 || count($transitions) > 1000) {
            throw new DomainException('TERMS_TIMELINE_LIMIT');
        }
        return $this->fromRows($subscriptionId, $terms, $transitions, $sub->current_terms_id === null ? null : (string) $sub->current_terms_id);
    }

    public function fromRows(string $subscriptionId, array $terms, array $transitions, ?string $tipId = null): array
    {
        self::decimal($subscriptionId, true);
        if (!$terms) {
            throw new DomainException('TERMS_TIMELINE_EMPTY');
        }
        $nodes = []; $versions = []; $out = []; $in = [];
        foreach ($terms as $term) {
            $id = self::decimal($term['id'], true);
            $version = self::decimal($term['version_no'], true);
            $from = self::timestamp($term['effective_from']);
            $until = self::timestamp($term['effective_until'], true);
            if ((string) $term['subscription_id'] !== $subscriptionId || isset($nodes[$id]) || isset($versions[$version])
                || ($until !== null && $until <= $from)) {
                throw new DomainException('TERMS_TIMELINE_INVALID');
            }
            $nodes[$id] = ['terms_id' => $id, 'subscription_id' => $subscriptionId, 'version_no' => $version,
                'effective_from' => $from, 'effective_until' => $until, 'ending_transition_id' => null];
            $versions[$version] = true;
        }
        foreach ($transitions as $transition) {
            $previous = self::decimal($transition['previous_terms_id'], true);
            $next = self::decimal($transition['next_terms_id'], true);
            $at = self::timestamp($transition['effective_at']);
            if ((string) $transition['subscription_id'] !== $subscriptionId || !isset($nodes[$previous], $nodes[$next])
                || $previous === $next || isset($out[$previous]) || isset($in[$next])
                || (int) $nodes[$next]['version_no'] <= (int) $nodes[$previous]['version_no']
                || $nodes[$next]['effective_from'] !== $at || $at <= $nodes[$previous]['effective_from']) {
                throw new DomainException('TERMS_TIMELINE_FORK_CYCLE_OR_BOUNDARY');
            }
            $out[$previous] = $next;
            $in[$next] = $previous;
            $end = $nodes[$previous]['effective_until'];
            $nodes[$previous]['effective_until'] = $end === null ? $at : min($end, $at);
            $nodes[$previous]['ending_transition_id'] = self::decimal($transition['id'], true);
        }
        $roots = array_diff(array_keys($nodes), array_keys($in));
        if (count($roots) !== 1) {
            throw new DomainException('TERMS_TIMELINE_DISCONNECTED');
        }
        $cursor = (string) reset($roots); $seen = []; $segments = [];
        while (isset($nodes[$cursor])) {
            if (isset($seen[$cursor])) {
                throw new DomainException('TERMS_TIMELINE_CYCLE');
            }
            $seen[$cursor] = true;
            $segments[] = $nodes[$cursor];
            if (!isset($out[$cursor])) {
                break;
            }
            $cursor = $out[$cursor];
        }
        if (count($seen) !== count($nodes) || ($tipId !== null && $cursor !== $tipId)) {
            throw new DomainException('TERMS_TIMELINE_DISCONNECTED_OR_STALE_POINTER');
        }
        for ($index = 1; $index < count($segments); $index++) {
            if ($segments[$index - 1]['effective_until'] === null
                || $segments[$index - 1]['effective_until'] > $segments[$index]['effective_from']) {
                throw new DomainException('TERMS_TIMELINE_OVERLAP');
            }
        }
        return $segments;
    }

    public function at(string $subscriptionId, int $at, bool $currentRead = false): ?array
    {
        self::timestamp($at);
        foreach ($this->resolve($subscriptionId, $currentRead) as $segment) {
            if ($at >= $segment['effective_from'] && ($segment['effective_until'] === null || $at < $segment['effective_until'])) {
                return $segment;
            }
        }
        return null;
    }

    public function covers(string $subscriptionId, string $termsId, int $start, int $end, bool $currentRead = false): bool
    {
        self::timestamp($start); self::timestamp($end); self::decimal($termsId, true);
        if ($start >= $end) {
            return false;
        }
        foreach ($this->resolve($subscriptionId, $currentRead) as $segment) {
            if ($segment['terms_id'] === $termsId) {
                return $start >= $segment['effective_from'] && ($segment['effective_until'] === null || $end <= $segment['effective_until']);
            }
        }
        return false;
    }

    public static function decimal(mixed $value, bool $positive = false, bool $nullable = false): ?string
    {
        if ($nullable && $value === null) {
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)
            || strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)
            || ($positive && $value === '0')) {
            throw new DomainException('TERMS_DECIMAL_INVALID');
        }
        return $value;
    }

    public static function timestamp(mixed $value, bool $nullable = false): ?int
    {
        $number = self::decimal($value, nullable: $nullable);
        if ($number === null) {
            return null;
        }
        if ((int) $number > 9007199254740991) {
            throw new DomainException('TERMS_TIME_INVALID');
        }
        return (int) $number;
    }

    public static function json(array $value): string
    {
        $normalize = function (array $item) use (&$normalize): array {
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as &$element) {
                if (is_array($element)) {
                    $element = $normalize($element);
                }
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::json($value));
    }
}
