<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $userIds = array_keys($this->data);

        foreach ($this->data as $uid => $v) {
            if (!is_array($v) || count($v) < 2 || !is_numeric($v[0]) || !is_numeric($v[1])
                || $v[0] < 0 || $v[1] < 0) {
                continue;
            }

            User::where('id', $uid)
                ->incrementEach(
                    [
                        'u' => $v[0] * $this->server['rate'],
                        'd' => $v[1] * $this->server['rate'],
                    ],
                    ['t' => time()]
                );
        }

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }
}
