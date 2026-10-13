<?php

namespace App\Services\Subscription;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Captured endpoint evidence only. Never a live balance or payment writer. */
final class LegacyMoneyShadow
{
    private const CHECKPOINT = 'tx_legacy_money_shadow_checkpoint';
    private const STEP = 'tx_legacy_money_shadow_step';
    private const POSTING = 'tx_legacy_money_shadow_posting';
    private const NUMBERS = ['stream_id', 'legacy_user_id', 'anchor_wallet_minor', 'anchor_commission_minor',
        'high_water', 'last_sequence', 'wallet_minor', 'commission_minor'];

    public function __construct(private readonly LegacyUserMutationCapture $capture) {}

    public function project(int $userId, int $limit = 100, bool $extend = false): array
    {
        return $this->guard(function () use ($userId, $limit, $extend): array {
            $this->validateInput($userId, 0, $limit);
            return DB::transaction(function () use ($userId, $limit, $extend): array {
                $this->fence($userId, true);
                $row = DB::table(self::CHECKPOINT)->where('legacy_user_id', $userId)->lockForUpdate()->first();
                $cp = $row === null ? null : $this->checkpoint($row);
                if ($extend) $this->require($cp !== null && $cp['last_sequence'] === $cp['high_water'], 'MONEY_SHADOW_EXTENSION_NOT_READY');
                $after = (int) ($cp['last_sequence'] ?? 0);
                $page = $this->capture->moneyEvidencePage($userId, $after, $limit,
                    $cp === null || $extend ? null : (int) $cp['high_water']);
                if ($cp === null) {
                    $before = $page['first']['before_snapshot'];
                    $cp = ['stream_id' => $page['stream_id'], 'legacy_user_id' => (string) $userId,
                        'context_hash' => $page['context_hash'], 'currency' => $page['currency'],
                        'anchor_event_hash' => $page['first']['content_hash'],
                        'anchor_wallet_minor' => $before['balance'], 'anchor_commission_minor' => $before['commission_balance'],
                        'high_water' => $page['high_water'], 'high_event_hash' => $page['high_event_hash'],
                        'last_sequence' => '0', 'last_event_hash' => null, 'last_after_hash' => $page['first']['before_hash'],
                        'wallet_minor' => $before['balance'], 'commission_minor' => $before['commission_balance']];
                    $id = DB::table(self::CHECKPOINT)->insertGetId($cp + ['content_hash' => CommerceShadowCodec::hash($cp)]);
                } else {
                    $id = (int) $row->id;
                    $this->validateAnchor($cp, $page);
                    if ($after > 0) $this->inspectStep($id, $page['anchor']);
                    if ($extend) { $cp['high_water'] = $page['high_water']; $cp['high_event_hash'] = $page['high_event_hash']; }
                    else $this->require($cp['high_water'] === $page['high_water'] && $cp['high_event_hash'] === $page['high_event_hash'], 'MONEY_SHADOW_HIGH_WATER_CHANGED');
                }
                $start = $cp['last_sequence'];
                foreach ($page['events'] as $event) {
                    $step = $this->step($id, $event);
                    $this->require($event['before_hash'] === $cp['last_after_hash']
                        && $event['previous_event_hash'] === $cp['last_event_hash']
                        && $step['wallet_before'] === $cp['wallet_minor']
                        && $step['commission_before'] === $cp['commission_minor'], 'MONEY_SHADOW_CURSOR_DAMAGED');
                    $stepId = DB::table(self::STEP)->insertGetId($step + ['content_hash' => CommerceShadowCodec::hash($step)]);
                    foreach ($this->postings($stepId, $step) as $posting) {
                        DB::table(self::POSTING)->insert($posting + ['content_hash' => CommerceShadowCodec::hash($posting)]);
                    }
                    $cp['last_sequence'] = $step['sequence']; $cp['last_event_hash'] = $event['content_hash'];
                    $cp['last_after_hash'] = $event['after_hash'];
                    $cp['wallet_minor'] = $step['wallet_after']; $cp['commission_minor'] = $step['commission_after'];
                }
                DB::table(self::CHECKPOINT)->where('id', $id)->update($cp + ['content_hash' => CommerceShadowCodec::hash($cp)]);
                return $this->result($cp, $page) + ['read_only' => false, 'after_cursor' => $start,
                    'processed' => count($page['events'])];
            });
        });
    }

    /** Each autonomous page is SELECT only and validates exact immutable rows. */
    public function verify(int $userId, int $after = 0, int $limit = 100): array
    {
        return $this->guard(function () use ($userId, $after, $limit): array {
            $this->validateInput($userId, $after, $limit);
            return DB::transaction(function () use ($userId, $after, $limit): array {
                $this->fence($userId, false);
                $row = DB::table(self::CHECKPOINT)->where('legacy_user_id', $userId)->sharedLock()->first();
                $this->require($row !== null, 'MONEY_SHADOW_CHECKPOINT_MISSING');
                $cp = $this->checkpoint($row); $id = (int) $row->id;
                $this->require($after <= (int) $cp['last_sequence'], 'MONEY_SHADOW_CURSOR_AHEAD');
                $page = $this->capture->moneyEvidencePage($userId, $after, $limit, (int) $cp['high_water']);
                $this->validateAnchor($cp, $page, false);
                $this->require($cp['high_event_hash'] === $page['high_event_hash'], 'MONEY_SHADOW_HIGH_WATER_CHANGED');
                $endpoint = $this->capture->moneyEvidencePage($userId, (int) $cp['last_sequence'], 1, (int) $cp['high_water']);
                $this->validateAnchor($cp, $endpoint);
                if ((int) $cp['last_sequence'] > 0) $this->inspectStep($id, $endpoint['anchor']);
                if ($after > 0) $this->inspectStep($id, $page['anchor']);
                $next = $after; $verified = 0;
                foreach ($page['events'] as $event) {
                    if ((int) $event['sequence'] > (int) $cp['last_sequence']) break;
                    $this->inspectStep($id, $event); $next = (int) $event['sequence']; $verified++;
                }
                $this->require(DB::table(self::STEP)->where('checkpoint_id', $id)->count() === (int) $cp['last_sequence'], 'MONEY_SHADOW_STEP_COUNT_DAMAGED');
                return $this->result($cp, $page) + ['read_only' => true, 'page_ok' => true, 'after_cursor' => (string) $after,
                    'next_cursor' => (string) $next, 'has_more' => $next < (int) $cp['last_sequence'], 'verified' => $verified];
            });
        });
    }

    private function validateInput(int $userId, int $after, int $limit): void
    {
        $this->require(config('legacy_money_shadow.enabled') === true && app()->environment(['local', 'testing'])
            && config('core_shadow.isolated_copy') === true, 'MONEY_SHADOW_ISOLATED_REQUIRED');
        $this->require(DB::transactionLevel() === 0, 'MONEY_SHADOW_OUTER_TRANSACTION_REFUSED');
        $this->require($userId > 0 && $userId <= 2147483647 && $after >= 0 && $limit > 0 && $limit <= 100, 'MONEY_SHADOW_PAGE_INVALID');
    }

    private function fence(int $userId, bool $exclusive): void
    {
        $q = DB::table('tx_user')->where('id', $userId)->select('id');
        $this->require(($exclusive ? $q->lockForUpdate() : $q->sharedLock())->first() !== null, 'CAPTURE_SOURCE_MISSING');
    }

    private function checkpoint(object $row): array
    {
        $cp = (array) $row; unset($cp['id'], $cp['content_hash']);
        foreach (self::NUMBERS as $field) $cp[$field] = CommerceShadowCodec::integer($cp[$field]);
        $this->require($row->content_hash === CommerceShadowCodec::hash($cp)
            && (int) $cp['last_sequence'] <= (int) $cp['high_water'], 'MONEY_SHADOW_CHECKPOINT_DAMAGED');
        return $cp;
    }

    private function validateAnchor(array $cp, array $page, bool $cursor = true): void
    {
        $first = $page['first'];
        $this->require($cp['stream_id'] === $page['stream_id'] && $cp['context_hash'] === $page['context_hash']
            && $cp['currency'] === $page['currency'] && $cp['anchor_event_hash'] === $first['content_hash']
            && $cp['anchor_wallet_minor'] === $first['before_snapshot']['balance']
            && $cp['anchor_commission_minor'] === $first['before_snapshot']['commission_balance'], 'MONEY_SHADOW_ANCHOR_CHANGED');
        if (!$cursor) return;
        $anchor = $page['anchor']; $snapshot = $anchor['after_snapshot'] ?? $first['before_snapshot'];
        $this->require($cp['last_event_hash'] === ($anchor['content_hash'] ?? null)
            && $cp['last_after_hash'] === ($anchor['after_hash'] ?? $first['before_hash'])
            && $cp['wallet_minor'] === $snapshot['balance'] && $cp['commission_minor'] === $snapshot['commission_balance'], 'MONEY_SHADOW_CURSOR_DAMAGED');
    }

    private function step(int $checkpointId, array $event): array
    {
        return ['checkpoint_id' => (string) $checkpointId, 'capture_event_id' => $event['id'], 'sequence' => $event['sequence'],
            'capture_event_hash' => $event['content_hash'], 'before_hash' => $event['before_hash'], 'after_hash' => $event['after_hash'],
            'wallet_before' => $event['before_snapshot']['balance'], 'wallet_after' => $event['after_snapshot']['balance'],
            'commission_before' => $event['before_snapshot']['commission_balance'], 'commission_after' => $event['after_snapshot']['commission_balance']];
    }

    private function postings(int $stepId, array $step): array
    {
        $lines = [];
        foreach (['wallet', 'commission'] as $purpose) {
            $delta = (int) $step[$purpose.'_after'] - (int) $step[$purpose.'_before'];
            if ($delta === 0) continue;
            foreach (['user_'.$purpose => $delta > 0 ? 'credit' : 'debit',
                'capture_'.$purpose.'_clearing' => $delta > 0 ? 'debit' : 'credit'] as $account => $side) {
                $lines[] = ['step_id' => (string) $stepId, 'line_no' => count($lines) + 1,
                    'purpose' => $account, 'side' => $side, 'amount_minor' => (string) abs($delta)];
            }
        }
        return $lines;
    }

    private function inspectStep(int $id, array $event): void
    {
        $row = DB::table(self::STEP)->where('checkpoint_id', $id)->where('sequence', $event['sequence'])->sharedLock()->first();
        $this->require($row !== null, 'MONEY_SHADOW_STEP_MISSING');
        $expected = $this->step($id, $event); $actual = (array) $row; unset($actual['id'], $actual['content_hash']);
        foreach ($expected as $key => $value) $actual[$key] = (string) $actual[$key];
        $this->require($actual === $expected && $row->content_hash === CommerceShadowCodec::hash($expected), 'MONEY_SHADOW_STEP_DAMAGED');
        $rows = DB::table(self::POSTING)->where('step_id', $row->id)->orderBy('line_no')->limit(5)->sharedLock()->get();
        $lines = $this->postings((int) $row->id, $expected);
        $this->require($rows->count() === count($lines), 'MONEY_SHADOW_POSTING_DAMAGED');
        foreach ($rows as $i => $posting) {
            $actual = ['step_id' => (string) $posting->step_id, 'line_no' => (int) $posting->line_no,
                'purpose' => $posting->purpose, 'side' => $posting->side, 'amount_minor' => (string) $posting->amount_minor];
            $this->require($actual === $lines[$i] && $posting->content_hash === CommerceShadowCodec::hash($lines[$i]), 'MONEY_SHADOW_POSTING_DAMAGED');
        }
    }

    private function result(array $cp, array $page): array
    {
        return ['scope' => 'captured-money-endpoints/v1', 'legacy_user_id' => $cp['legacy_user_id'], 'currency' => $cp['currency'],
            'high_water' => $cp['high_water'], 'stream_high_water' => $page['stream_high_water'], 'cursor' => $cp['last_sequence'],
            'complete' => $cp['last_sequence'] === $cp['high_water'], 'needs_extension' => (int) $page['stream_high_water'] > (int) $cp['high_water'],
            'wallet_minor' => $cp['wallet_minor'], 'commission_minor' => $cp['commission_minor'],
            'checkpoint_hash' => CommerceShadowCodec::hash($cp), 'global_coverage_complete' => false, 'migration_gate_open' => false];
    }

    private function require(bool $ok, string $code): void { if (!$ok) throw new LegacyCaptureShadowException($code); }
    private function guard(callable $callback): mixed
    {
        try { return $callback(); }
        catch (LegacyCaptureShadowException $e) { throw $e; }
        catch (Throwable) { throw new LegacyCaptureShadowException('MONEY_SHADOW_STORAGE_OR_EVIDENCE_FAILED'); }
    }
}
