<?php

namespace App\Domains\UsageV2;

use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\NativeCredentialSynchronization;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\SubscriptionTermsTimeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Corrections change billed entitlement accounting; original signed node observations stay immutable. */
final class NativeUsageCorrections
{
    public const PERMISSION = 'usage.corrections.manage';
    private const ENTRY_FIELDS = ['subscription_id', 'usage_period_id', 'access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id',
        'raw_up_bytes', 'raw_down_bytes', 'billed_up_bytes', 'billed_down_bytes', 'numerator', 'denominator', 'rounding_revision'];

    public function __construct(private NativeSubscriptionStore $store) {}

    public function entries(int $actorId, int $userId, ?string $before = null, int $limit = 25): array
    {
        $this->pagination($before, $limit);
        return $this->owned($actorId, $userId, false, function (array $installation, User $target, $subscriptions) use ($actorId, $userId, $before, $limit): array {
            $query = DB::table('tx_usage_entry')->whereIn('subscription_id', $subscriptions->pluck('id')->all());
            if ($before !== null) $query->where('id', '<', $before);
            $rows = $query->orderByDesc('id')->limit($limit + 1)->sharedLock()->get(); $periods = []; $items = [];
            foreach ($rows->take($limit) as $row) {
                $periodId = (string) $row->usage_period_id;
                if (!isset($periods[$periodId])) $periods[$periodId] = $this->assertProjectionLocked(DB::table('tx_usage_period')->where('id', $periodId)->lockForUpdate()->first(), $rows->where('usage_period_id', $periodId)->pluck('id')->map(fn ($id) => (string) $id)->all());
                $period = DB::table('tx_usage_period')->where('id', $periodId)->sharedLock()->first();
                $current = $periods[$periodId]['entries'][(string) $row->id] ?? null; if (!$current) self::corrupt();
                $items[] = $current['dto'] + ['period_version' => (string) $period->row_version,
                    'editable' => !$target->is_admin && !$target->is_staff && $actorId !== $userId];
            }
            return ['basis' => 'settled-billed-with-admin-corrections/v1', 'items' => $items, 'next_cursor' => $rows->count() > $limit ? (string) $rows[$limit - 1]->id : null];
        });
    }

    public function create(int $actorId, int $userId, array $input, string $key): array
    {
        if (!Str::isUuid($key) || array_diff(array_keys($input), ['entry_id', 'expected_entry_revision', 'expected_period_version', 'billed_up_bytes', 'billed_down_bytes', 'reason'])
            || count($input) !== 6 || !is_string($input['expected_entry_revision'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $input['expected_entry_revision'])
            || !is_string($input['reason'] ?? null) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $input['reason'])) self::invalid();
        foreach (['entry_id', 'expected_period_version', 'billed_up_bytes', 'billed_down_bytes'] as $field) {
            if (!is_string($input[$field])) self::invalid(); SubscriptionTermsTimeline::decimal($input[$field], in_array($field, ['entry_id', 'expected_period_version'], true));
        }
        $result = $this->owned($actorId, $userId, true, function (array $installation, User $target, $subscriptions) use ($actorId, $userId, $input, $key): array {
            if ($target->is_admin || $target->is_staff || $actorId === $userId) throw new UsageError('USAGE_CORRECTION_TARGET_PROTECTED', 403);
            $request = ['installation_id' => $installation['installation_id'], 'actor_id' => (string) $actorId, 'user_id' => (string) $userId, 'request_key' => strtolower($key), 'input' => $input];
            $old = DB::table('tx_native_usage_correction')->where('installation_id', $installation['installation_id'])->where('actor_id', $actorId)->where('request_key', strtolower($key))->lockForUpdate()->first();
            if ($old && !hash_equals($old->request_hash, $this->hash($request))) throw new UsageError('USAGE_CORRECTION_IDEMPOTENCY_CONFLICT', 409);
            $entry = DB::table('tx_usage_entry')->where('id', $input['entry_id'])->sharedLock()->first();
            if (!$entry || !$subscriptions->contains('id', $entry->subscription_id)) throw new UsageError('USAGE_CORRECTION_ENTRY_MISSING', 404);
            $subscription = $subscriptions->firstWhere('id', $entry->subscription_id);
            $period = DB::table('tx_usage_period')->where('id', $entry->usage_period_id)->lockForUpdate()->first();
            $projection = $this->assertProjectionLocked($period, [(string) $entry->id]); $current = $projection['entries'][(string) $entry->id];
            if ($old) { $this->verifyRow($old, $current['source']); return $this->decode($old->result_json); }
            if (!hash_equals($current['dto']['entry_revision'], $input['expected_entry_revision']) || (string) $period->row_version !== $input['expected_period_version']) throw new UsageError('USAGE_CORRECTION_VERSION_CONFLICT', 409);
            if ($input['billed_up_bytes'] === $current['dto']['billed_up_bytes'] && $input['billed_down_bytes'] === $current['dto']['billed_down_bytes']) throw new UsageError('USAGE_CORRECTION_NO_CHANGE', 422);
            $up = $this->change((string) $period->upload_bytes, $current['dto']['billed_up_bytes'], $input['billed_up_bytes']);
            $down = $this->change((string) $period->download_bytes, $current['dto']['billed_down_bytes'], $input['billed_down_bytes']); $billable = UsageMath::add($up, $down);
            $now = time(); $live = $period->status === 'open' && in_array($subscription->status, ['active', 'suspended'], true);
            if ($live) $this->store->protectAcceptedUsage((string) $subscription->id, $now);
            $period = $this->store->updatePeriod($period, ['upload_bytes' => $up, 'download_bytes' => $down, 'billable_bytes' => $billable], $now);
            if ($live) $subscription = $this->store->updateSubscription($subscription, [], $now);
            $version = (int) $current['version'] + 1;
            $revision = $this->hash([$current['source'], (string) $version, $current['dto']['entry_revision'], $input['billed_up_bytes'], $input['billed_down_bytes']]);
            $id = DB::table('tx_native_usage_correction')->insertGetId(['installation_id' => $installation['installation_id'], 'actor_id' => $actorId, 'user_id' => $userId,
                'request_key' => strtolower($key), 'entry_id' => $entry->id, 'period_id' => $period->id, 'version' => $version,
                'request_hash' => $this->hash($request), 'request_json' => SubscriptionTermsTimeline::json($request), 'source_json' => SubscriptionTermsTimeline::json($current['source']),
                'result_json' => '{}', 'content_hash' => str_repeat('0', 64), 'created_at' => $now]);
            $result = ['correction_id' => (string) $id, 'user_id' => (string) $userId, 'subscription_id' => (string) $subscription->id,
                'entry_id' => (string) $entry->id, 'period_id' => (string) $period->id, 'version' => (string) $version, 'entry_revision' => $revision,
                'previous_entry_revision' => $current['dto']['entry_revision'], 'before_up_bytes' => $current['dto']['billed_up_bytes'], 'before_down_bytes' => $current['dto']['billed_down_bytes'],
                'billed_up_bytes' => $input['billed_up_bytes'], 'billed_down_bytes' => $input['billed_down_bytes'], 'period_version' => (string) $period->row_version,
                'period_billable_bytes' => $billable, 'actor_id' => (string) $actorId, 'reason' => $input['reason'], 'occurred_at' => (string) $now];
            $result = $this->decode(SubscriptionTermsTimeline::json($result));
            DB::table('tx_native_usage_correction')->where('id', $id)->update(['result_json' => SubscriptionTermsTimeline::json($result), 'content_hash' => $this->hash([$request, $current['source'], $result])]);
            DB::table('tx_native_usage_correction_head')->updateOrInsert(['entry_id' => $entry->id], ['installation_id' => $installation['installation_id'], 'version' => $version, 'entry_revision' => $revision]);
            $this->assertProjectionLocked($period);
            if ($live) app(NativeCredentialSynchronization::class)->stageLocked($userId, (int) $subscription->id);
            return $result;
        });
        app(NativeCredentialSynchronization::class)->synchronize($userId, (int) $result['subscription_id']); return $result;
    }

    public function history(int $actorId, int $userId, ?string $before = null, int $limit = 25): array
    {
        $this->pagination($before, $limit);
        return $this->owned($actorId, $userId, false, function (array $installation) use ($userId, $before, $limit): array {
            $query = DB::table('tx_native_usage_correction')->where('installation_id', $installation['installation_id'])->where('user_id', $userId);
            if ($before !== null) $query->where('id', '<', $before);
            $rows = $query->orderByDesc('id')->limit($limit + 1)->sharedLock()->get(); $items = [];
            foreach ($rows->take($limit) as $row) {
                $entry = DB::table('tx_usage_entry')->where('id', $row->entry_id)->sharedLock()->first(); if (!$entry) self::corrupt();
                $source = $this->source($entry); $this->verifyRow($row, $source); $items[] = $this->decode($row->result_json);
            }
            return ['items' => $items, 'next_cursor' => $rows->count() > $limit ? (string) $rows[$limit - 1]->id : null];
        });
    }

    /** Settlement calls this only when corrections exist, before adding a new immutable batch. */
    public function assertCorrectedProjectionLocked(object $period): void
    {
        if (Schema::hasTable('tx_native_usage_correction') && DB::table('tx_native_usage_correction')->where('period_id', $period->id)->sharedLock()->exists()) $this->assertProjectionLocked($period);
    }

    public function assertProjectionLocked(?object $period, array $retainEntryIds = []): array
    {
        if (!$period || $period->boundary_kind !== 'known' || DB::transactionLevel() < 1) self::corrupt();
        $subscription = DB::table('tx_subscription')->where('id', $period->subscription_id)->sharedLock()->first();
        if (!$subscription || $subscription->authority_version !== 'core-subscription/v1') self::corrupt();
        // The period lock prevents owner mutations while bounded pages verify its complete history.
        $entries = DB::table('tx_usage_entry')->where('usage_period_id', $period->id)->sharedLock()->lazyById(250);
        $retain = array_fill_keys($retainEntryIds, true); $verifiedCorrections = 0;
        $items = []; $up = '0'; $down = '0';
        foreach ($entries as $entry) {
            $source = $this->source($entry); $version = 0; $revision = $this->hash([$source, 'original/v1']);
            $entryUp = (string) $entry->billed_up_bytes; $entryDown = (string) $entry->billed_down_bytes;
            $rows = DB::table('tx_native_usage_correction')->where('period_id', $period->id)->where('entry_id', $entry->id)->sharedLock()->lazyById(250);
            foreach ($rows as $row) {
                ++$verifiedCorrections;
                $this->verifyRow($row, $source); $result = $this->decode($row->result_json);
                if ((string) $row->version !== (string) ++$version || $result['previous_entry_revision'] !== $revision
                    || $result['before_up_bytes'] !== $entryUp || $result['before_down_bytes'] !== $entryDown) self::corrupt();
                $entryUp = $result['billed_up_bytes']; $entryDown = $result['billed_down_bytes'];
                $expected = $this->hash([$source, (string) $version, $revision, $entryUp, $entryDown]);
                if ($result['entry_revision'] !== $expected) self::corrupt(); $revision = $expected;
            }
            $head = DB::table('tx_native_usage_correction_head')->where('entry_id', $entry->id)->sharedLock()->first();
            if (($version === 0) !== ($head === null) || $head && ($head->installation_id !== $subscription->installation_id || (string) $head->version !== (string) $version || $head->entry_revision !== $revision)) self::corrupt();
            $up = UsageMath::add($up, $entryUp); $down = UsageMath::add($down, $entryDown);
            if (isset($retain[(string) $entry->id])) $items[(string) $entry->id] = ['source' => $source, 'version' => $version, 'dto' => ['entry_id' => (string) $entry->id, 'subscription_id' => (string) $entry->subscription_id,
                'period_id' => (string) $period->id, 'batch_id' => (string) $entry->batch_id, 'node_id' => $source['node_id'], 'ended_at' => $source['ended_at'],
                'original_up_bytes' => (string) $entry->billed_up_bytes, 'original_down_bytes' => (string) $entry->billed_down_bytes,
                'billed_up_bytes' => $entryUp, 'billed_down_bytes' => $entryDown, 'entry_revision' => $revision]];
        }
        if ($verifiedCorrections !== DB::table('tx_native_usage_correction')->where('period_id', $period->id)->sharedLock()->count()) self::corrupt();
        if ($up !== (string) $period->upload_bytes || $down !== (string) $period->download_bytes || UsageMath::add($up, $down) !== (string) $period->billable_bytes) throw new UsageError('USAGE_CORRECTION_PROJECTION_MISMATCH', 409);
        return ['entries' => $items, 'upload_bytes' => $up, 'download_bytes' => $down];
    }

    private function source(object $entry): array
    {
        $batch = DB::table('tx_usage_batch')->where('id', $entry->batch_id)->sharedLock()->first();
        if (!$batch || $batch->status !== 'settled') self::corrupt(); $frozen = json_decode($batch->frozen_entries, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($frozen) || !hash_equals($batch->frozen_hash, UsageEvidence::hash($frozen))
            || !hash_equals($batch->payload_digest, 'sha256:'.hash('sha256', $batch->canonical_payload))) self::corrupt();
        $original = $frozen[(int) $entry->entry_no] ?? null; if (!is_array($original)) self::corrupt();
        $fields = [];
        foreach (self::ENTRY_FIELDS as $field) { if (!array_key_exists($field, $original) || (string) $entry->$field !== (string) $original[$field]) self::corrupt(); $fields[$field] = (string) $entry->$field; }
        foreach (['billed_up_bytes', 'billed_down_bytes', 'raw_up_bytes', 'raw_down_bytes'] as $field) SubscriptionTermsTimeline::decimal($fields[$field]);
        $grant = DB::table('tx_auth_snapshot')->where('id', $entry->auth_snapshot_id)->sharedLock()->first(); if (!$grant) self::corrupt();
        app(NativeUsageLedger::class)->verifyNativeGrant($grant);
        foreach (['subscription_id', 'usage_period_id', 'access_subject_id', 'terms_id', 'tariff_revision_id'] as $field) if ((string) $grant->$field !== $fields[$field]) self::corrupt();
        $tariff = DB::table('tx_tariff_revision')->where('id', $entry->tariff_revision_id)->sharedLock()->first(); if (!$tariff) self::corrupt();
        NativeUsageLedger::verifyTariff($tariff);
        if (($original['grant_hash'] ?? null) !== $grant->content_hash || ($original['tariff_hash'] ?? null) !== $tariff->content_hash
            || (string) $tariff->node_id !== (string) $batch->node_id || (string) $tariff->numerator !== $fields['numerator']
            || (string) $tariff->denominator !== $fields['denominator'] || $tariff->rounding_revision !== $fields['rounding_revision']
            || UsageMath::floor($fields['raw_up_bytes'], $fields['numerator'], $fields['denominator']) !== $fields['billed_up_bytes']
            || UsageMath::floor($fields['raw_down_bytes'], $fields['numerator'], $fields['denominator']) !== $fields['billed_down_bytes']) self::corrupt();
        return ['entry_id' => (string) $entry->id, 'batch_id' => (string) $batch->id, 'batch_hash' => $batch->frozen_hash,
            'node_id' => (string) $batch->node_id, 'ended_at' => (string) $batch->ended_at, 'fields' => $fields];
    }

    private function verifyRow(object $row, array $source): void
    {
        $request = $this->decode($row->request_json); $storedSource = $this->decode($row->source_json); $result = $this->decode($row->result_json);
        if (!hash_equals($row->request_hash, $this->hash($request)) || !hash_equals($row->content_hash, $this->hash([$request, $storedSource, $result]))
            || SubscriptionTermsTimeline::json($storedSource) !== SubscriptionTermsTimeline::json($source)
            || ($request['installation_id'] ?? null) !== $row->installation_id || ($request['actor_id'] ?? null) !== (string) $row->actor_id
            || ($request['user_id'] ?? null) !== (string) $row->user_id || ($request['request_key'] ?? null) !== $row->request_key
            || ($result['correction_id'] ?? null) !== (string) $row->id || ($result['entry_id'] ?? null) !== (string) $row->entry_id
            || ($result['period_id'] ?? null) !== (string) $row->period_id || ($result['version'] ?? null) !== (string) $row->version
            || ($result['actor_id'] ?? null) !== (string) $row->actor_id || ($result['user_id'] ?? null) !== (string) $row->user_id
            || ($result['reason'] ?? null) !== ($request['input']['reason'] ?? null) || ($result['occurred_at'] ?? null) !== (string) $row->created_at
            || ($result['billed_up_bytes'] ?? null) !== ($request['input']['billed_up_bytes'] ?? null) || ($result['billed_down_bytes'] ?? null) !== ($request['input']['billed_down_bytes'] ?? null)) self::corrupt();
        foreach (['billed_up_bytes', 'billed_down_bytes', 'before_up_bytes', 'before_down_bytes', 'period_billable_bytes'] as $field) SubscriptionTermsTimeline::decimal($result[$field]);
    }

    private function owned(int $actorId, int $userId, bool $write, \Closure $operation): mixed
    {
        if ($actorId < 1 || $userId < 1 || DB::transactionLevel() !== 0) self::invalid();
        return DB::transaction(function () use ($actorId, $userId, $write, $operation): mixed {
            $installation = $write ? app(InstallationAuthority::class)->assertNativeMutation() : app(InstallationAuthority::class)->assertNativeRead();
            $users = User::query()->whereIn('id', [$actorId, $userId])->orderBy('id')->lockForUpdate()->get();
            app(CurrentSessionAuthorization::class)->assertCurrentLocked($actorId, self::PERMISSION);
            if (!Schema::hasTable('tx_native_usage_correction') || !Schema::hasTable('tx_native_usage_correction_head')) throw new UsageError('USAGE_CORRECTION_CAPABILITY_UNAVAILABLE', 503);
            $target = $users->firstWhere('id', $userId); if (!$target) throw new UsageError('USAGE_CORRECTION_ACCOUNT_MISSING', 404);
            [, , $subscriptions] = $this->store->lockAccount($userId, time(), $installation['installation_id']);
            foreach ($subscriptions as $subscription) $this->store->verifyHistory($subscription, $this->store->lockHistory($subscription), true);
            return $operation($installation, $target, $subscriptions);
        }, 3);
    }
    private function change(string $total, string $old, string $new): string { $value = bcadd(bcsub($total, $old, 0), $new, 0); SubscriptionTermsTimeline::decimal($value); return $value; }
    private function pagination(?string $before, int $limit): void { if ($before !== null) SubscriptionTermsTimeline::decimal($before, true); if ($limit < 1 || $limit > 100) self::invalid(); }
    private function hash(array $value): string { return hash_hmac('sha256', SubscriptionTermsTimeline::json($value), (string) config('app.key')); }
    private function decode(string $value): array { try { $data = json_decode($value, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { self::corrupt(); } if (!is_array($data) || array_is_list($data)) self::corrupt(); return $data; }
    private static function invalid(): never { throw new UsageError('USAGE_CORRECTION_INPUT_INVALID', 422); }
    private static function corrupt(): never { throw new UsageError('USAGE_CORRECTION_EVIDENCE_INVALID', 409); }
}
