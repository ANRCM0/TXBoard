<?php

namespace App\Services\Subscription;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Only migration openings. No spending, settlement, refunds or balance writer. */
final class CommerceShadowLedger
{
    public function open(array $source, int $now): array
    {
        $currency = $source['currency'];
        $userId = $source['legacy_user_id'];
        $identities = $this->identities($userId, $currency);
        $accounts = $this->accounts($identities, true);
        foreach ($identities as $key => $identity) {
            if (!isset($accounts[$key])) {
                DB::table('tx_commerce_shadow_money_account')->insertOrIgnore($identity + [
                    'content_hash' => CommerceShadowCodec::hash($identity), 'created_at' => $now,
                ]);
            }
        }
        $accounts = $this->accounts($identities, true);
        if (count($accounts) !== count($identities)) {
            throw new RuntimeException('COMMERCE_ACCOUNT_DAMAGED');
        }
        $references = [];
        foreach (['wallet', 'commission'] as $purpose) {
            $amount = $source[$purpose.'_minor'];
            if ($amount === '0') {
                $references[$purpose.'_journal_id'] = null;
                continue;
            }
            $payload = $this->payload($source, $purpose, $accounts);
            $lines = $payload['postings'];
            $this->assertBalanced($lines);
            $journalId = DB::table('tx_commerce_shadow_money_journal')->insertGetId([
                'business_type' => 'legacy_'.$purpose.'_opening', 'business_ref' => $this->businessRef($source),
                'idempotency_key' => 'opening/v1', 'currency' => $currency, 'status' => 'posted',
                'source_snapshot' => CommerceShadowCodec::json($payload), 'content_hash' => CommerceShadowCodec::hash($payload),
                'occurred_at' => $now, 'posted_at' => $now, 'reverses_journal_id' => null, 'created_at' => $now,
            ]);
            foreach ($lines as $line) {
                $posting = $line + ['journal_id' => (string) $journalId, 'currency' => $currency];
                DB::table('tx_commerce_shadow_money_posting')->insert($posting + [
                    'content_hash' => CommerceShadowCodec::hash($posting), 'created_at' => $now,
                ]);
            }
            $references[$purpose.'_journal_id'] = (string) $journalId;
        }
        return $references;
    }

    /** Derives expected postings from frozen source, then checks physical records. No mutations. */
    public function inspect(object $opening): array
    {
        try {
            $source = json_decode($opening->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $identities = $this->identities((string) $opening->legacy_user_id, $opening->currency);
            $accounts = $this->accounts($identities, false);
            $differences = [];
            foreach ($identities as $key => $identity) {
                if (!isset($accounts[$key])) {
                    $differences[] = ['field' => 'ledger.account.'.$key, 'error_code' => 'COMMERCE_ACCOUNT_DAMAGED'];
                }
            }
            if ($differences) {
                return $differences;
            }
            $expectedCount = 0;
            foreach (['wallet', 'commission'] as $purpose) {
                $amount = CommerceShadowCodec::integer($source[$purpose.'_minor']);
                $journalId = $opening->{$purpose.'_journal_id'};
                $prefix = 'ledger.'.$purpose.'.';
                if ($amount === '0') {
                    if ($journalId !== null) {
                        $differences[] = ['field' => $prefix.'journal_id', 'expected' => null, 'actual' => (string) $journalId];
                    }
                    continue;
                }
                $expectedCount++;
                $journal = $journalId === null ? null : DB::table('tx_commerce_shadow_money_journal')->where('id', $journalId)->sharedLock()->first();
                if ($journal === null) {
                    $differences[] = ['field' => $prefix.'journal', 'error_code' => 'COMMERCE_JOURNAL_MISSING'];
                    continue;
                }
                $payload = $this->payload($source, $purpose, $accounts);
                $expected = ['business_type' => 'legacy_'.$purpose.'_opening', 'business_ref' => $this->businessRef($source),
                    'idempotency_key' => 'opening/v1', 'currency' => $opening->currency, 'status' => 'posted',
                    'reverses_journal_id' => null, 'content_hash' => CommerceShadowCodec::hash($payload)];
                foreach (['created_at', 'occurred_at', 'posted_at'] as $timestamp) {
                    $expected[$timestamp] = (string) $opening->captured_at;
                }
                $actual = array_intersect_key((array) $journal, $expected);
                foreach (['created_at', 'occurred_at', 'posted_at'] as $timestamp) {
                    $actual[$timestamp] = (string) $actual[$timestamp];
                }
                $differences = [...$differences, ...CommerceShadowCodec::differences($expected, $actual, $prefix)];
                $actualPayload = json_decode($journal->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
                $differences = [...$differences, ...CommerceShadowCodec::differences($payload, $actualPayload, $prefix.'source.')];
                $postings = DB::table('tx_commerce_shadow_money_posting')->where('journal_id', $journal->id)->orderBy('line_no')->sharedLock()->get();
                if ($postings->count() !== 2) {
                    $differences[] = ['field' => $prefix.'posting_count', 'expected' => '2', 'actual' => (string) $postings->count()];
                }
                $actualLines = [];
                foreach ($postings as $index => $posting) {
                    $actualLine = ['line_no' => (int) $posting->line_no, 'account_id' => (string) $posting->account_id,
                        'side' => $posting->side, 'amount_minor' => CommerceShadowCodec::integer($posting->amount_minor, positive: true)];
                    $actualLines[] = $actualLine;
                    if (isset($payload['postings'][$index])) {
                        $expectedPosting = $payload['postings'][$index] + ['journal_id' => (string) $journal->id, 'currency' => $opening->currency];
                        $actualPosting = $actualLine + ['journal_id' => (string) $posting->journal_id, 'currency' => $posting->currency];
                        $expectedPosting['content_hash'] = CommerceShadowCodec::hash($expectedPosting);
                        $actualPosting['content_hash'] = $posting->content_hash;
                        $differences = [...$differences, ...CommerceShadowCodec::differences($expectedPosting, $actualPosting, $prefix.'posting.'.$index.'.')];
                    }
                }
                try {
                    $this->assertBalanced($actualLines);
                } catch (Throwable) {
                    $differences[] = ['field' => $prefix.'balance', 'error_code' => 'COMMERCE_JOURNAL_UNBALANCED'];
                }
            }
            $count = DB::table('tx_commerce_shadow_money_journal')->where('business_ref', $this->businessRef($source))->sharedLock()->get(['id'])->count();
            if ($count !== $expectedCount) {
                $differences[] = ['field' => 'ledger.journal_count', 'expected' => (string) $expectedCount, 'actual' => (string) $count];
            }
            return $differences;
        } catch (Throwable) {
            return [['field' => 'ledger.structure', 'error_code' => 'COMMERCE_LEDGER_DAMAGED']];
        }
    }

    private function identity(string $ownerType, string $ownerId, string $purpose, string $currency, string $normal): array
    {
        return ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'purpose' => $purpose, 'currency' => $currency, 'normal_side' => $normal];
    }

    private function identities(string $userId, string $currency): array
    {
        $identities = [];
        foreach (['wallet', 'commission'] as $purpose) {
            $identities[$purpose] = $this->identity('user', $userId, $purpose, $currency, 'credit');
            $clearing = 'migration_'.$purpose.'_clearing';
            $identities[$clearing] = $this->identity('system', '0', $clearing, $currency, 'debit');
        }
        return $identities;
    }

    /** Ordered current reads avoid stale MVCC snapshots after waiting for the User lock. */
    private function accounts(array $identities, bool $exclusive): array
    {
        $query = DB::table('tx_commerce_shadow_money_account')->where(function ($query) use ($identities) {
            foreach ($identities as $identity) {
                $query->orWhere(function ($accountQuery) use ($identity) {
                    $accountQuery->where(array_diff_key($identity, ['normal_side' => true]));
                });
            }
        })->orderBy('id');
        $rows = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->get();
        $accounts = [];
        foreach ($rows as $account) {
            $key = $account->purpose;
            if (!isset($identities[$key]) || !hash_equals(CommerceShadowCodec::hash($identities[$key]), $account->content_hash)
                || $account->normal_side !== $identities[$key]['normal_side']) {
                throw new RuntimeException('COMMERCE_ACCOUNT_DAMAGED');
            }
            $accounts[$key] = $account;
        }
        return $accounts;
    }

    private function businessRef(array $source): string
    {
        return 'user/'.$source['legacy_user_id'].'/'.$source['currency'];
    }

    private function payload(array $source, string $purpose, array $accounts): array
    {
        return ['kind' => 'migration_opening_only', 'legacy_user_id' => $source['legacy_user_id'],
            'currency' => $source['currency'], 'purpose' => $purpose, 'user_source_hash' => CommerceShadowCodec::hash($source),
            'postings' => [
                ['line_no' => 1, 'account_id' => (string) $accounts['migration_'.$purpose.'_clearing']->id,
                    'side' => 'debit', 'amount_minor' => $source[$purpose.'_minor']],
                ['line_no' => 2, 'account_id' => (string) $accounts[$purpose]->id,
                    'side' => 'credit', 'amount_minor' => $source[$purpose.'_minor']],
            ]];
    }

    private function assertBalanced(array $lines): void
    {
        $totals = ['debit' => '0', 'credit' => '0'];
        if (count($lines) < 2) {
            throw new RuntimeException('COMMERCE_JOURNAL_UNBALANCED');
        }
        foreach ($lines as $line) {
            if (!isset($totals[$line['side']])) {
                throw new RuntimeException('COMMERCE_JOURNAL_UNBALANCED');
            }
            $amount = CommerceShadowCodec::integer($line['amount_minor'], positive: true);
            $totals[$line['side']] = CommerceShadowCodec::add($totals[$line['side']], $amount);
        }
        if ($totals['debit'] !== $totals['credit']) {
            throw new RuntimeException('COMMERCE_JOURNAL_UNBALANCED');
        }
    }
}
