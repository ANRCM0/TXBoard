<?php

namespace App\Domains\UsageV2;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Unsettled proven bytes consume their original Period's quota without becoming billed usage. */
final class NativeUsageReservations
{
    public static function consumed(object $period): string
    {
        $consumed = (string) $period->billable_bytes;
        UsagePayload::decimal($consumed, true);
        if (!NativeUsageLedger::enabled()) { return $consumed; }
        self::transaction();
        $rows = DB::table('tx_native_usage_reservation')->where('usage_period_id', $period->id)
            ->orderBy('batch_id')->sharedLock()->cursor(['reserved_bytes']);
        foreach ($rows as $row) {
            UsagePayload::decimal((string) $row->reserved_bytes, true);
            $consumed = bcadd($consumed, (string) $row->reserved_bytes, 0);
            // A damaged or exceptionally large backlog must never reopen quota through overflow.
            if (bccomp($consumed, (string) PHP_INT_MAX, 0) >= 0) { return (string) PHP_INT_MAX; }
        }
        return $consumed;
    }

    public static function reserveLocked(int $batchId, array $entries, int $now): void
    {
        self::transaction();
        $totals = [];
        foreach ($entries as $entry) {
            $periodId = $entry['usage_period_id'];
            $totals[$periodId] = UsageMath::add($totals[$periodId] ?? '0', $entry['billed_up_bytes'], $entry['billed_down_bytes']);
        }
        uksort($totals, static fn ($a, $b): int => UsagePayload::compare((string) $a, (string) $b));
        foreach ($totals as $periodId => $bytes) {
            DB::table('tx_native_usage_reservation')->insert([
                'batch_id' => $batchId, 'usage_period_id' => $periodId, 'reserved_bytes' => $bytes, 'created_at' => $now,
            ]);
        }
    }

    public static function releaseLocked(int $batchId, array $totals): void
    {
        self::transaction();
        $rows = DB::table('tx_native_usage_reservation')->where('batch_id', $batchId)
            ->orderBy('usage_period_id')->lockForUpdate()->get();
        if ($rows->count() !== count($totals)) { throw new UsageError('USAGE_NATIVE_RESERVATION_INVALID'); }
        foreach ($rows as $row) {
            if (!isset($totals[$row->usage_period_id])
                || (string) $row->reserved_bytes !== $totals[$row->usage_period_id]['billable']) {
                throw new UsageError('USAGE_NATIVE_RESERVATION_INVALID');
            }
        }
        DB::table('tx_native_usage_reservation')->where('batch_id', $batchId)->delete();
    }

    /** Candidate filtering only; the account-locked service rechecks exact decimal totals. */
    public static function constrainAvailable(Builder $query, string $periodAlias = 'period'): void
    {
        $cast = DB::getDriverName() === 'sqlite' ? 'REAL' : 'DECIMAL(65,0)';
        $query->whereColumn($periodAlias.'.billable_bytes', '<', $periodAlias.'.quota_bytes')
            ->whereRaw('COALESCE((SELECT SUM(CAST(reservation.reserved_bytes AS '.$cast.'))'
                .' FROM tx_native_usage_reservation AS reservation WHERE reservation.usage_period_id = '.$periodAlias.'.id), 0)'
                .' < CAST('.$periodAlias.'.quota_bytes AS '.$cast.') - CAST('.$periodAlias.'.billable_bytes AS '.$cast.')');
    }

    private static function transaction(): void
    {
        if (DB::transactionLevel() < 1) { throw new UsageError('USAGE_NATIVE_RESERVATION_TRANSACTION_REQUIRED'); }
    }
}
