<?php

namespace App\Domains\Network;

use App\Domains\Installation\InstallationAuthority;
use App\Jobs\TrafficBatchJob;
use App\Models\Server;
use App\Services\TrafficUsage;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Durable native-v1 reception; billing stays in the original TrafficBatchJob. */
final class NativeTrafficReceipt
{
    private const TABLE = 'tx_native_traffic_receipt';

    public function accept(Server $authenticatedNode, string $batchId, array $rawTraffic, ?array $segments = null): object
    {
        // HTTP/Workerman are standalone owners. A returned ACK cannot certify a
        // transaction controlled by another caller which could still roll back.
        if (DB::transactionLevel() !== 0) $this->unavailable();
        $raw = TrafficUsage::normalize($rawTraffic, 1);
        if ($raw === [] || count($raw) !== count($rawTraffic)) throw new NodeReportError('INVALID_TRAFFIC', 'Invalid traffic entries');
        ksort($raw, SORT_NUMERIC);
        $segments = $segments === null ? null : NativeTrafficSegments::canonical($segments, $raw);
        $rawHash = hash('sha256', json_encode($segments === null ? $raw : [$raw, $segments], JSON_THROW_ON_ERROR));
        $nodeId = (int) $authenticatedNode->id;
        try {
            $receipt = $this->transaction(function () use ($nodeId, $batchId, $raw, $rawHash, $segments): object {
                app(InstallationAuthority::class)->assertLegacyWriter();
                // Match the original settlement lock order: authority, server,
                // batch. Serializing first acceptance prevents repeated hooks.
                $node = Server::query()->whereKey($nodeId)->lockForUpdate()->first();
                if (!$node) $this->unavailable();
                $existing = DB::table(self::TABLE)->where(['node_id' => $nodeId, 'batch_id' => $batchId])->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->input($existing);
                    if ($existing->status === 'settled') $this->verifyLedger($existing);
                    if (!hash_equals($existing->raw_payload_hash, $rawHash)) {
                        throw new NodeReportError('TRAFFIC_BATCH_CONFLICT', 'Batch identity conflicts with accepted traffic');
                    }
                    return $existing;
                }
                if ($segments !== null) {
                    $users = DB::table('tx_user')->whereIn('id', array_keys($raw))->orderBy('id')->lockForUpdate()->get(['id', 'reset_count'])->keyBy('id');
                    foreach ($segments as $segment) {
                        // Missing/deleted Users retain the original settlement
                        // skip boundary; authenticity is still checked.
                        $epoch = isset($users[$segment['user_id']]) ? (int) $users[$segment['user_id']]->reset_count : PHP_INT_MAX;
                        app(NativeTrafficPeriod::class)->verify($segment['period'], $segment['user_id'], $epoch);
                    }
                }
                $prepared = app(UserService::class)->prepareTrafficInput($node, (string) $node->type, $raw);
                if ((int) ($prepared['server']['id'] ?? 0) !== $nodeId || !is_string($prepared['protocol'] ?? null)
                    || $prepared['protocol'] === '' || !is_int($prepared['record_at'] ?? null)
                    || !is_array($prepared['data'] ?? null) || $prepared['data'] === []) {
                    throw new NodeReportError('INVALID_TRAFFIC', 'Invalid prepared traffic');
                }
                $rate = $prepared['server']['rate'] ?? null;
                $data = TrafficUsage::normalize($prepared['data'], $rate);
                if (count($data) !== count($prepared['data'])) throw new NodeReportError('INVALID_TRAFFIC', 'Invalid prepared traffic');
                ksort($data, SORT_NUMERIC);
                if ($segments !== null && $data !== $raw) throw new NodeReportError('TRAFFIC_PERIOD_HOOK_CONFLICT', 'Traffic hooks changed attributed bytes');
                // Persist only settlement inputs, never server credentials,
                // protocol keys, authenticated bearer, or machine token.
                $input = ['server' => ['id' => $nodeId, 'rate' => $rate], 'protocol' => $prepared['protocol'],
                    'record_at' => $prepared['record_at'], 'data' => $data];
                if ($segments !== null) $input['segments'] = $segments;
                $inputJson = json_encode($input, JSON_THROW_ON_ERROR);
                $ledgerHash = NativeTrafficSegments::ledgerHash($rate, $data, $segments);
                $legacyHash = DB::table('tx_traffic_batch')->where(['server_id' => $nodeId, 'batch_id' => $batchId])->value('payload_hash');
                if ($legacyHash !== null && (!is_string($legacyHash) || !hash_equals($legacyHash, $ledgerHash))) {
                    throw new NodeReportError('TRAFFIC_BATCH_CONFLICT', 'Batch identity conflicts with settled traffic');
                }
                $row = ['node_id' => $nodeId, 'batch_id' => $batchId, 'raw_payload_hash' => $rawHash,
                    'settlement_input' => $inputJson, 'ledger_payload_hash' => $ledgerHash, 'accepted_at' => time()];
                $row['content_hash'] = $this->contentHash((object) $row);
                $id = DB::table(self::TABLE)->insertGetId($row + ['status' => 'pending', 'attempts' => 0]);
                return DB::table(self::TABLE)->where('id', $id)->first();
            });
        } catch (NodeReportError $error) {
            throw $error;
        } catch (Throwable) {
            Log::error('NATIVE_TRAFFIC_ACCEPTANCE_FAILED');
            $this->unavailable();
        }
        // SQL acceptance is already committed. Queue loss cannot remove it;
        // the explicit recovery command replays this exact immutable input.
        try {
            $input = $this->input($receipt);
            TrafficBatchJob::dispatch($input['server'], $input['data'], $input['protocol'], $input['record_at'], $receipt->batch_id, (int) $receipt->id, $input['segments'] ?? null);
        } catch (Throwable) {
            Log::warning('NATIVE_TRAFFIC_WAKEUP_FAILED');
        }
        return $receipt;
    }

    public function recover(int $receiptId): void
    {
        if (DB::transactionLevel() !== 0) throw new RuntimeException('NATIVE_TRAFFIC_RECOVERY_TRANSACTION_UNSUPPORTED');
        $row = DB::table(self::TABLE)->where('id', $receiptId)->first();
        if ($row === null) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_MISSING');
        $input = $this->input($row);
        if ($row->status === 'settled') {
            $this->verifyLedger($row);
            return;
        }
        (new TrafficBatchJob($input['server'], $input['data'], $input['protocol'], $input['record_at'], $row->batch_id, (int) $row->id, $input['segments'] ?? null))->handle();
        $confirmed = DB::table(self::TABLE)->where('id', $receiptId)->first();
        if ($confirmed === null || $confirmed->status !== 'settled') throw new RuntimeException('NATIVE_TRAFFIC_SETTLEMENT_UNCONFIRMED');
        $this->input($confirmed);
        $this->verifyLedger($confirmed);
    }

    /** A queued receipt-bound job cannot substitute another rate/day or payload. */
    public function verifySettlementInput(int $receiptId, array $server, array $data, string $protocol, int $recordAt, string $batchId, ?array $segments = null): void
    {
        $row = DB::table(self::TABLE)->where('id', $receiptId)->first();
        if ($row === null || $row->batch_id !== $batchId) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_IDENTITY_INVALID');
        $input = $this->input($row);
        $candidate = ['server' => ['id' => (int) ($server['id'] ?? 0), 'rate' => $server['rate'] ?? null],
            'protocol' => $protocol, 'record_at' => $recordAt, 'data' => $data];
        if ($segments !== null) $candidate['segments'] = $segments;
        if (json_encode($candidate, JSON_THROW_ON_ERROR) !== $row->settlement_input) throw new RuntimeException('NATIVE_TRAFFIC_SETTLEMENT_INPUT_CONFLICT');
    }

    /** Called after the original SQL settlement committed or proved a duplicate. */
    public function completeFromLedger(int $receiptId, int $nodeId, string $batchId, string $payloadHash): void
    {
        if (DB::transactionLevel() !== 0) throw new RuntimeException('NATIVE_TRAFFIC_SETTLEMENT_TRANSACTION_UNSUPPORTED');
        $this->transaction(function () use ($receiptId, $nodeId, $batchId, $payloadHash): void {
            app(InstallationAuthority::class)->assertLegacyWriter();
            // Preserve the shared original lock order across settlement/accept.
            DB::table('tx_server')->where('id', $nodeId)->lockForUpdate()->first();
            $row = DB::table(self::TABLE)->where('id', $receiptId)->lockForUpdate()->first();
            if ($row === null || (int) $row->node_id !== $nodeId || $row->batch_id !== $batchId) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_IDENTITY_INVALID');
            $this->input($row);
            if (!hash_equals($row->ledger_payload_hash, $payloadHash)) throw new RuntimeException('NATIVE_TRAFFIC_LEDGER_CONFLICT');
            $this->verifyLedger($row);
            if ($row->status === 'settled') return;
            DB::table(self::TABLE)->where('id', $receiptId)->update(['status' => 'settled', 'settled_at' => time(), 'last_error_code' => null]);
        });
    }

    public function recordRecoveryFailure(int $receiptId): void
    {
        $this->transaction(function () use ($receiptId): void {
            app(InstallationAuthority::class)->assertLegacyWriter();
            $row = DB::table(self::TABLE)->where('id', $receiptId)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'pending') return;
            DB::table(self::TABLE)->where('id', $receiptId)->increment('attempts', 1, ['last_error_code' => 'NATIVE_TRAFFIC_RECOVERY_FAILED']);
        });
    }

    public function settledUserIds(object $receipt): array
    {
        $input = $this->input($receipt);
        if ($receipt->status !== 'settled') throw new RuntimeException('NATIVE_TRAFFIC_SETTLEMENT_UNCONFIRMED');
        $this->verifyLedger($receipt);
        return array_map('intval', array_keys($input['data']));
    }

    public function quotaUserIds(object $receipt): array
    {
        return $this->settledUserIds($receipt);
    }

    public function markQuotaChecked(int $receiptId): void
    {
        if (DB::transactionLevel() !== 0) throw new RuntimeException('NATIVE_TRAFFIC_QUOTA_TRANSACTION_UNSUPPORTED');
        $this->transaction(function () use ($receiptId): void {
            app(InstallationAuthority::class)->assertLegacyWriter();
            $row = DB::table(self::TABLE)->where('id', $receiptId)->lockForUpdate()->first();
            if ($row === null) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_MISSING');
            $this->settledUserIds($row);
            if ($row->quota_checked_at === null) DB::table(self::TABLE)->where('id', $receiptId)->update(['quota_checked_at' => time()]);
        });
    }

    private function verifyLedger(object $row): void
    {
        $hash = DB::table('tx_traffic_batch')->where(['server_id' => $row->node_id, 'batch_id' => $row->batch_id])->value('payload_hash');
        if (!is_string($hash) || !hash_equals($row->ledger_payload_hash, $hash)) throw new RuntimeException('NATIVE_TRAFFIC_LEDGER_UNCONFIRMED');
    }

    private function input(object $row): array
    {
        if (!isset($row->content_hash) || !hash_equals($row->content_hash, $this->contentHash($row))
            || !in_array($row->status ?? null, ['pending', 'settled'], true)) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_INVALID');
        $input = json_decode($row->settlement_input, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !in_array(array_keys($input), [['server', 'protocol', 'record_at', 'data'], ['server', 'protocol', 'record_at', 'data', 'segments']], true)
            || !is_array($input['server']) || array_keys($input['server']) !== ['id', 'rate']
            || $input['server']['id'] !== (int) $row->node_id || !is_string($input['protocol']) || $input['protocol'] === ''
            || !is_int($input['record_at']) || !is_array($input['data']) || $input['data'] === []) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_INVALID');
        $data = TrafficUsage::normalize($input['data'], $input['server']['rate']);
        if (count($data) !== count($input['data'])) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_INVALID');
        ksort($data, SORT_NUMERIC);
        if ($data !== $input['data']) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_INVALID');
        $segments = array_key_exists('segments', $input) ? NativeTrafficSegments::canonical($input['segments'], $data) : null;
        if ($segments !== null && $segments !== $input['segments']) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_INVALID');
        if (!hash_equals($row->ledger_payload_hash, NativeTrafficSegments::ledgerHash($input['server']['rate'], $data, $segments))) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_INVALID');
        return $input;
    }

    private function contentHash(object $row): string
    {
        return hash('sha256', json_encode([(int) $row->node_id, $row->batch_id, $row->raw_payload_hash,
            $row->settlement_input, $row->ledger_payload_hash, (int) $row->accepted_at], JSON_THROW_ON_ERROR));
    }

    private function transaction(callable $callback): mixed
    {
        if (DB::transactionLevel() !== 0) throw new RuntimeException('NATIVE_TRAFFIC_RECEIPT_TRANSACTION_UNSUPPORTED');
        try {
            return DB::transaction($callback, 3);
        } catch (Throwable $error) {
            // Laravel commit-event faults can leave the callback's changes in
            // an open transaction. This phase owns it and must not leak an
            // uncommitted marker or return a receipt as durable reception.
            $connection = DB::connection();
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            } elseif ($connection->getPdo()->inTransaction()) {
                // Commit failure decrements Laravel's level before rethrowing,
                // while the physical transaction can still remain active.
                $connection->getPdo()->rollBack();
                app('db.transactions')->rollback($connection->getName(), 0);
            }
            throw $error;
        }
    }

    private function unavailable(): never
    {
        throw new NodeReportError('TRAFFIC_DURABILITY_UNAVAILABLE', 'Durable traffic acceptance unavailable');
    }
}
