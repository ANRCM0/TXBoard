<?php

namespace App\Jobs;

use App\Models\User;
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
            $this->applyIncrement((int) $uid, $increments);
            $userIds[] = $uid;
        }

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }

    private function applyIncrement(int $userId, array $increments): void
    {
        // Each User commits independently; this queue has no batch identity or dedupe.
        DB::transaction(function () use ($userId, $increments): void {
            app(\App\Domains\Installation\InstallationAuthority::class)->assertLegacyWriter();
            if (User::where('id', $userId)->lockForUpdate()->first(['id']) === null) {
                return;
            }
            User::where('id', $userId)->incrementEach($increments, ['t' => time()]);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof \App\Domains\Installation\InstallationAuthorityException) { Log::error('INSTALLATION_TRAFFIC_FETCH_REJECTED'); return; }
    }
}
