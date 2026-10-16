<?php

namespace App\Jobs;

use App\Services\TrafficUsage;
use App\Domains\Network\NativeTrafficSegments;
use App\Domains\Network\NativeTrafficPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Settle an identified report in one DB transaction. Inserting the uniquely
 * keyed ledger row and updating users, user stats, node stats and node totals
 * share the same commit/rollback boundary. Redis is best-effort AFTER commit.
 */
class TrafficBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function backoff(): array
    {
        return [5, 20, 60];
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof \App\Domains\Installation\InstallationAuthorityException) {
            Log::error('INSTALLATION_TRAFFIC_BATCH_REJECTED');
            return;
        }
    }

    public function __construct(
        private readonly array $server,
        private readonly array $data,
        private readonly string $protocol,
        private readonly int $recordAt,
        private readonly string $batchId,
        private readonly ?int $nativeReceiptId = null,
        private readonly ?array $trafficSegments = null
    ) {
        $this->onQueue('traffic_fetch');
    }

    public function handle(): void
    {
        $rate = $this->server['rate'] ?? null;
        $data = TrafficUsage::normalize($this->data, $rate);
        if ($data === []) {
            return;
        }

        // Sort before hashing so map iteration order does not change identity.
        ksort($data, SORT_NUMERIC);
        $segments = $this->trafficSegments === null ? null : NativeTrafficSegments::canonical($this->trafficSegments, $data);
        $segmentsByUser = [];
        foreach ($segments ?? [] as $segment) $segmentsByUser[$segment['user_id']][] = $segment;
        $hash = NativeTrafficSegments::ledgerHash($rate, $data, $segments);
        $serverId = (int) $this->server['id'];
        if ($this->nativeReceiptId !== null) {
            app(\App\Domains\Network\NativeTrafficReceipt::class)->verifySettlementInput($this->nativeReceiptId, $this->server, $data, $this->protocol, $this->recordAt, $this->batchId, $segments);
        }
        $acceptedUsers = [];

        $settled = DB::transaction(function () use ($serverId, $rate, $hash, $data, $segments, $segmentsByUser, &$acceptedUsers) {
            app(\App\Domains\Installation\InstallationAuthority::class)->assertLegacyWriter();
            // A deadlock retry must notify only the successful transaction's users.
            $acceptedUsers = [];
            // Serialise batches for one server and prevent duplicate stat rows
            // even on databases without composite unique statistics indexes.
            $server = DB::table('tx_server')->where('id', $serverId)->lockForUpdate()->first();
            if (!$server) {
                throw new RuntimeException('Cannot settle traffic for missing server');
            }

            $inserted = DB::table('tx_traffic_batch')->insertOrIgnore([
                'server_id' => $serverId,
                'batch_id' => $this->batchId,
                'payload_hash' => $hash,
                'created_at' => time(),
            ]);
            if ($inserted !== 1) {
                $oldHash = DB::table('tx_traffic_batch')->where([
                    'server_id' => $serverId, 'batch_id' => $this->batchId,
                ])->value('payload_hash');
                if (!is_string($oldHash) || !hash_equals($oldHash, $hash)) {
                    Log::error('Traffic batch id reused with different payload', [
                        'server_id' => $serverId, 'batch_id' => $this->batchId,
                    ]);
                }
                return false;
            }

            // P4: one indexed SELECT per bounded chunk, not one SELECT for
            // every individual user and statistics row. Lock users in ID order
            // to preserve deterministic lock acquisition across replay batches.
            // Chunking also respects SQLite/MySQL bind-parameter limits.
            $usersById = [];
            $statsByUser = [];
            $statKey = [
                'server_rate' => $rate,
                'record_at' => $this->recordAt,
                'record_type' => 'd',
            ];
            foreach (array_chunk(array_keys($data), 400) as $ids) {
                $users = DB::table('tx_user')->whereIn('id', $ids)
                    ->orderBy('id')->lockForUpdate()
                    ->get($segments === null ? ['id', 'u', 'd'] : ['id', 'u', 'd', 'reset_count']);
                foreach ($users as $user) {
                    $usersById[(int) $user->id] = $user;
                }
            }

            // Acquire every sorted User fence before any statistics range lock.
            // This keeps the same lock scope/query count while preventing a
            // later User chunk from waiting behind a competing stat insertion.
            foreach (array_chunk(array_keys($data), 400) as $ids) {
                $statsQuery = DB::table('tx_stat_user')->where($statKey)->whereIn('user_id', $ids);
                // Always use a current read: a prior chunk may establish an
                // older repeatable-read snapshot before this User lock waits.
                $stats = $statsQuery->sharedLock()->get(['id', 'user_id', 'u', 'd']);
                foreach ($stats as $stat) {
                    $statsByUser[(int) $stat->user_id] = $stat;
                }
            }

            $rawUp = $rawDown = 0;
            foreach ($data as $uid => [$up, $down]) {
                $user = $usersById[(int) $uid] ?? null;
                if (!$user) {
                    continue;
                }

                $currentUp = $currentDown = 0;
                if ($segments === null) {
                    $billedUp = (int) floor($up * (float) $rate);
                    $billedDown = (int) floor($down * (float) $rate);
                    $currentUp = $billedUp; $currentDown = $billedDown;
                } else {
                    $billedUp = $billedDown = 0;
                    foreach ($segmentsByUser[(int) $uid] ?? [] as $segment) {
                        $epoch = app(NativeTrafficPeriod::class)->verify($segment['period'], (int) $uid, (int) $user->reset_count);
                        $segmentUp = (int) floor($segment['upload'] * (float) $rate);
                        $segmentDown = (int) floor($segment['download'] * (float) $rate);
                        if ($segmentUp > PHP_INT_MAX - $billedUp || $segmentDown > PHP_INT_MAX - $billedDown) throw new RuntimeException('Traffic period counter overflow');
                        $billedUp += $segmentUp; $billedDown += $segmentDown;
                        if ($epoch === (int) $user->reset_count) { $currentUp += $segmentUp; $currentDown += $segmentDown; }
                        $periodRow = DB::table('tx_native_traffic_period_usage')->where(['user_id' => $uid, 'reset_count' => $epoch])->lockForUpdate()->first();
                        $increments = ['raw_upload' => $segment['upload'], 'raw_download' => $segment['download'], 'billed_upload' => $segmentUp, 'billed_download' => $segmentDown];
                        if ($periodRow) {
                            foreach ($increments as $column => $value) {
                                if ($value > PHP_INT_MAX - (int) $periodRow->$column) throw new RuntimeException('Traffic period counter overflow');
                            }
                            DB::table('tx_native_traffic_period_usage')->where('id', $periodRow->id)->incrementEach($increments, ['updated_at' => time()]);
                        } else {
                            DB::table('tx_native_traffic_period_usage')->insert(['user_id' => $uid, 'reset_count' => $epoch, 'updated_at' => time()] + $increments);
                        }
                    }
                }
                if ($currentUp > PHP_INT_MAX - (int) $user->u || $currentDown > PHP_INT_MAX - (int) $user->d) throw new RuntimeException('User traffic counter overflow');
                if ($currentUp > 0 || $currentDown > 0 || $segments === null) {
                    DB::table('tx_user')->where('id', $uid)->incrementEach(['u' => $currentUp, 'd' => $currentDown], ['t' => time()]);
                }

                $where = [
                    'user_id' => $uid,
                    'server_rate' => $rate,
                    'record_at' => $this->recordAt,
                    'record_type' => 'd',
                ];
                $stat = $statsByUser[(int) $uid] ?? null;
                if ($stat) {
                    if ($billedUp > PHP_INT_MAX - (int) $stat->u || $billedDown > PHP_INT_MAX - (int) $stat->d) {
                        throw new RuntimeException('User traffic statistic overflow');
                    }
                    DB::table('tx_stat_user')->where('id', $stat->id)->incrementEach([
                        'u' => $billedUp, 'd' => $billedDown,
                    ], ['updated_at' => time()]);
                } else {
                    DB::table('tx_stat_user')->insert($where + [
                        'u' => $billedUp, 'd' => $billedDown,
                        'created_at' => time(), 'updated_at' => time(),
                    ]);
                }

                $rawUp += $up;
                $rawDown += $down;
                $acceptedUsers[] = (int) $uid;
            }

            if ($rawUp > PHP_INT_MAX - (int) ($server->u ?? 0)
                || $rawDown > PHP_INT_MAX - (int) ($server->d ?? 0)) {
                throw new RuntimeException('Server traffic counter overflow');
            }

            DB::table('tx_server')->where('id', $serverId)->incrementEach([
                'u' => $rawUp, 'd' => $rawDown,
            ], ['updated_at' => now()]);

            $where = [
                'server_id' => $serverId,
                'server_type' => $this->protocol,
                'record_at' => $this->recordAt,
                'record_type' => 'd',
            ];
            $statQuery = DB::table('tx_stat_server')->where($where);
            $stat = $statQuery->sharedLock()->first();
            if ($stat) {
                if ($rawUp > PHP_INT_MAX - (int) $stat->u || $rawDown > PHP_INT_MAX - (int) $stat->d) {
                    throw new RuntimeException('Server traffic statistic overflow');
                }
                DB::table('tx_stat_server')->where('id', $stat->id)->incrementEach([
                    'u' => $rawUp, 'd' => $rawDown,
                ], ['updated_at' => time()]);
            } else {
                DB::table('tx_stat_server')->insert($where + [
                    'u' => $rawUp, 'd' => $rawDown,
                    'created_at' => time(), 'updated_at' => time(),
                ]);
            }
            return true;
        }, 3);

        if (!$settled) {
            Log::info('Duplicate traffic batch skipped', [
                'server_id' => $serverId, 'batch_id' => $this->batchId,
            ]);
            $this->completeNativeReceipt($serverId, $hash);
            return;
        }

        if ($acceptedUsers !== []) {
            try {
                Redis::sadd('traffic:pending_check', ...$acceptedUsers);
            } catch (\Throwable $e) {
                // The SQL settlement has committed. Never retry and double
                // account because a non-authoritative notification failed.
                Log::warning('Traffic settlement succeeded but quota push pending check failed', [
                    'server_id' => $serverId, 'batch_id' => $this->batchId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->completeNativeReceipt($serverId, $hash);
    }
    private function completeNativeReceipt(int $serverId, string $payloadHash): void
    {
        if ($this->nativeReceiptId !== null) {
            app(\App\Domains\Network\NativeTrafficReceipt::class)->completeFromLedger($this->nativeReceiptId, $serverId, $this->batchId, $payloadHash);
        }
    }

}
