<?php

namespace App\Jobs;

use App\Services\TrafficUsage;
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

    public function __construct(
        private readonly array $server,
        private readonly array $data,
        private readonly string $protocol,
        private readonly int $recordAt,
        private readonly string $batchId
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
        $hash = hash('sha256', json_encode([$rate, $data], JSON_THROW_ON_ERROR));
        $serverId = (int) $this->server['id'];
        $acceptedUsers = [];

        $settled = DB::transaction(function () use ($serverId, $rate, $hash, $data, &$acceptedUsers) {
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
                    ->get(['id', 'u', 'd']);
                foreach ($users as $user) {
                    $usersById[(int) $user->id] = $user;
                }

                $stats = DB::table('tx_stat_user')->where($statKey)
                    ->whereIn('user_id', $ids)->get(['id', 'user_id']);
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

                $billedUp = (int) floor($up * (float) $rate);
                $billedDown = (int) floor($down * (float) $rate);
                if ($billedUp > PHP_INT_MAX - (int) $user->u
                    || $billedDown > PHP_INT_MAX - (int) $user->d) {
                    throw new RuntimeException('User traffic counter overflow');
                }

                DB::table('tx_user')->where('id', $uid)->incrementEach([
                    'u' => $billedUp, 'd' => $billedDown,
                ], ['t' => time()]);

                $where = [
                    'user_id' => $uid,
                    'server_rate' => $rate,
                    'record_at' => $this->recordAt,
                    'record_type' => 'd',
                ];
                $stat = $statsByUser[(int) $uid] ?? null;
                if ($stat) {
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
            $stat = DB::table('tx_stat_server')->where($where)->first();
            if ($stat) {
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
    }
}
