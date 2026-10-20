<?php

namespace App\Domains\Analytics;

use App\Domains\Billing\MoneyValue;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Network\Contracts\NodeIdentities;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A complete bounded native snapshot. An oversized scan fails instead of publishing partial totals. */
final class NativeDashboardRead
{
    private const LIMIT = 100000;

    public function __construct(private readonly NodeIdentities $nodes) {}

    public static function range(array $input, int $now): array
    {
        if (isset($input['start_date']) !== isset($input['end_date'])) throw new DomainException('DASHBOARD_RANGE_INVALID');
        $today = gmdate('Y-m-d', $now);
        $end = $input['end_date'] ?? $today;
        $start = $input['start_date'] ?? gmdate('Y-m-d', strtotime($today.'T00:00:00Z') - 29 * 86400);
        foreach ([$start, $end] as $date) {
            if (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date)
                || gmdate('Y-m-d', strtotime($date.'T00:00:00Z')) !== $date) throw new DomainException('DASHBOARD_RANGE_INVALID');
        }
        $first = strtotime($start.'T00:00:00Z');
        $exclusiveEnd = strtotime($end.'T00:00:00Z') + 86400;
        if ($first < 0 || $start > $end || $end > $today || $exclusiveEnd - $first > 366 * 86400) throw new DomainException('DASHBOARD_RANGE_INVALID');
        return [$start, $end, $first, min($exclusiveEnd, $now + 1)];
    }

    public function snapshot(array $input, int $now): array
    {
        [$start, $end, $first, $exclusiveEnd] = self::range($input, $now);
        return DB::transaction(function () use ($start, $end, $first, $exclusiveEnd, $now): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead()['installation_id'];
            $today = strtotime(gmdate('Y-m-d', $now).'T00:00:00Z');
            $month = strtotime(gmdate('Y-m-01', $now).'T00:00:00Z');
            $previous = strtotime('-1 month', $month);
            $cards = ['today_income' => [], 'month_income' => [], 'last_month_income' => [],
                'month_commission_transfer' => [], 'last_month_commission_transfer' => [],
                'month_new_accounts' => (string) DB::table('tx_user')->whereBetween('created_at', [$month, $now])->count(),
                'pending_tickets' => (string) DB::table('tx_ticket')->where('status', 0)->where('reply_status', 0)->count(),
                'pending_commission_payouts' => (string) DB::table('tx_native_commission_payout')->where('installation_id', $installation)->where('state', 'requested')->count()];
            $points = [];
            for ($at = $first; gmdate('Y-m-d', $at) <= $end; $at += 86400) {
                $date = gmdate('Y-m-d', $at);
                $points[$date] = ['date' => $date, 'income' => [], 'earned_commission' => [], 'commission_transfer' => []];
            }
            // One allocation per paid purchase order includes provider and wallet portions once.
            // Recharge receipts and unmatched/refund-liability receipts have no purchase allocation.
            $payments = DB::table('tx_order_payment as payment')->join('tx_order as orders', 'orders.id', '=', 'payment.order_id')
                ->where('payment.installation_id', $installation)->where('orders.paid_at', '>=', min($first, $previous))
                ->where('orders.paid_at', '<=', $now)->orderBy('payment.id')
                ->select(['payment.amount_minor', 'payment.currency', 'orders.paid_at']);
            foreach ($this->scan($payments) as $payment) {
                $at = (int) $payment->paid_at;
                if ($at >= $today) self::money($cards['today_income'], $payment->currency, (string) $payment->amount_minor);
                if ($at >= $month) self::money($cards['month_income'], $payment->currency, (string) $payment->amount_minor);
                if ($at >= $previous && $at < $month) self::money($cards['last_month_income'], $payment->currency, (string) $payment->amount_minor);
                if ($at >= $first && $at < $exclusiveEnd) self::money($points[gmdate('Y-m-d', $at)]['income'], $payment->currency, (string) $payment->amount_minor);
            }
            $rewards = DB::table('tx_native_commission_reward as reward')->join('tx_native_commission_order as commission', 'commission.id', '=', 'reward.commission_id')
                ->where('reward.installation_id', $installation)->where('commission.installation_id', $installation)
                ->where('commission.state', 'accrued')->orderBy('reward.commission_id')->select(['reward.payload_json', 'reward.content_hash']);
            foreach ($this->scan($rewards) as $reward) {
                $payload = self::payload($reward->payload_json, $reward->content_hash);
                $at = (int) $payload['settled_at'];
                if ($at >= $first && $at < $exclusiveEnd) self::money($points[gmdate('Y-m-d', $at)]['earned_commission'], 'CNY', $payload['amount_minor']);
            }
            $payouts = DB::table('tx_native_commission_payout')->where('installation_id', $installation)->where('state', 'approved')
                ->where('closed_at', '>=', min($first, $previous))->where('closed_at', '<=', $now)->orderBy('id');
            foreach ($this->scan($payouts) as $payout) {
                $terms = self::payload($payout->terms_json, $payout->terms_hash);
                $at = (int) $payout->closed_at;
                if ($at >= $month) self::money($cards['month_commission_transfer'], $terms['currency'], $terms['amount_minor']);
                if ($at >= $previous && $at < $month) self::money($cards['last_month_commission_transfer'], $terms['currency'], $terms['amount_minor']);
                if ($at >= $first && $at < $exclusiveEnd) self::money($points[gmdate('Y-m-d', $at)]['commission_transfer'], $terms['currency'], $terms['amount_minor']);
            }
            foreach (['today_income', 'month_income', 'last_month_income', 'month_commission_transfer', 'last_month_commission_transfer'] as $key) $cards[$key] = self::currencies($cards[$key]);
            foreach ($points as &$point) foreach (['income', 'earned_commission', 'commission_transfer'] as $kind) $point[$kind] = self::currencies($point[$kind]);
            unset($point);
            return ['time_zone' => 'UTC', 'basis' => 'native-paid-purchase-and-settled-usage/v1', 'observed_at' => $now,
                'start_date' => $start, 'end_date' => $end, 'starts_at' => $first, 'ends_before' => $exclusiveEnd,
                'cards' => $cards, 'online' => ['nodes' => null, 'users' => null, 'devices' => null],
                'trends' => array_values($points), 'rankings' => $this->rankings($first, min($exclusiveEnd, $now), $installation)];
        });
    }

    private function rankings(int $first, int $end, string $installation): array
    {
        $nodes = []; $users = [];
        $query = DB::table('tx_usage_entry as entry')->join('tx_usage_batch as batch', 'batch.id', '=', 'entry.batch_id')
            ->join('tx_auth_snapshot as snapshot', 'snapshot.id', '=', 'entry.auth_snapshot_id')
            ->join('tx_native_usage_grant as grant', 'grant.grant_id', '=', 'snapshot.id')
            ->where('grant.installation_id', $installation)->where('batch.core_instance_id', $installation)
            ->where('batch.status', 'settled')->where('batch.ended_at', '>', $first)->where('batch.ended_at', '<=', $end)
            ->orderBy('entry.id')->select(['batch.node_id', 'snapshot.user_id', 'entry.raw_up_bytes', 'entry.raw_down_bytes']);
        foreach ($this->scan($query) as $entry) {
            $total = self::add(self::decimal((string) $entry->raw_up_bytes), self::decimal((string) $entry->raw_down_bytes));
            $node = (string) $entry->node_id; $user = (string) $entry->user_id;
            $nodes[$node] = self::add($nodes[$node] ?? '0', $total);
            $users[$user] = self::add($users[$user] ?? '0', $total);
        }
        $rank = static function (array $totals): array {
            uksort($totals, static fn ($a, $b): int => bccomp($totals[$b], $totals[$a], 0) ?: bccomp((string) $a, (string) $b, 0));
            $rows = [];
            foreach (array_slice($totals, 0, 10, true) as $id => $total) $rows[] = ['id' => (string) $id, 'name' => null, 'total_bytes' => $total];
            return $rows;
        };
        $nodeRows = $rank($nodes);
        $names = $nodeRows ? array_column($this->nodes->findMany(array_column($nodeRows, 'id')), 'name', 'id') : [];
        foreach ($nodeRows as &$row) $row['name'] = $names[$row['id']] ?? null;
        unset($row);
        return ['nodes' => $nodeRows, 'users' => $rank($users)];
    }

    private function scan(Builder $query): \Generator
    {
        $count = 0;
        foreach ($query->limit(self::LIMIT + 1)->cursor() as $row) {
            if (++$count > self::LIMIT) throw new DomainException('DASHBOARD_SCAN_LIMIT');
            yield $row;
        }
    }

    private static function payload(string $json, string $hash): array
    {
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !hash_equals($hash, MoneyValue::hash($value))) throw new DomainException('DASHBOARD_EVIDENCE_INVALID');
        return $value;
    }

    public static function decimal(string $value): string
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,39})\z/D', $value)) throw new DomainException('DASHBOARD_VALUE_INVALID');
        return $value;
    }

    private static function add(string $a, string $b): string { return self::decimal(bcadd($a, $b, 0)); }

    private static function money(array &$totals, string $currency, string $amount): void
    {
        MoneyValue::currency($currency); self::decimal($amount);
        if (!isset($totals[$currency]) && count($totals) >= 64) throw new DomainException('DASHBOARD_CURRENCY_LIMIT');
        $old = $totals[$currency] ?? ['currency' => $currency, 'amount_minor' => '0', 'count' => '0'];
        $totals[$currency] = ['currency' => $currency, 'amount_minor' => self::add($old['amount_minor'], $amount), 'count' => self::add($old['count'], '1')];
    }

    private static function currencies(array $totals): array { ksort($totals, SORT_STRING); return array_values($totals); }
}
