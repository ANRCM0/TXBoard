<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyUserMutationCapture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        $userIds = [];
        $data = \App\Services\TrafficUsage::normalize($this->data, $this->server['rate'] ?? null);

        foreach ($data as $uid => $v) {
            $increments = ['u' => $v[0] * $this->server['rate'], 'd' => $v[1] * $this->server['rate']];
            if (config('legacy_capture_shadow.enabled') === false && config('authority_writer_fence.enabled') === false) {
                User::where('id', $uid)->incrementEach($increments, ['t' => time()]);
            } else {
                $this->captureIncrement((int) $uid, $increments);
            }
            $userIds[] = $uid;
        }

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }

    private function captureIncrement(int $userId, array $increments): void
    {
        // Keep the native per-User commit boundary; no batch identity or dedupe exists here.
        DB::transaction(function () use ($userId, $increments): void {
            if (config('authority_writer_fence.enabled') !== false) app(\App\Domains\AuthorityMigration\AuthorityWriterFence::class)->admit('traffic.fetch.apply');
            if (User::where('id', $userId)->lockForUpdate()->first(['id']) === null) {
                return;
            }
            if (config('legacy_capture_shadow.enabled') === false) {
                User::where('id', $userId)->incrementEach($increments, ['t' => time()]);
                return;
            }
            $reference = $this->server['id'] ?? null;
            if ((!is_int($reference) && !is_string($reference))
                || !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $reference)
                || (int) $reference > 2147483647) {
                throw new LegacyCaptureShadowException('CAPTURE_SOURCE_INVALID');
            }
            $capture = app(LegacyUserMutationCapture::class);
            $before = $capture->beginLegacyTrafficFetch($userId, (int) $reference);
            User::where('id', $userId)->incrementEach($increments, ['t' => time()]);
            $capture->appendLegacyTrafficFetch($before);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof \App\Domains\AuthorityMigration\AuthorityFenceException) { Log::error('AUTHORITY_TRAFFIC_FETCH_FENCE_REJECTED'); return; }
        if ($exception instanceof LegacyCaptureShadowException) {
            Log::error('LEGACY_TRAFFIC_FETCH_CAPTURE_FAILED');
        }
    }
}
