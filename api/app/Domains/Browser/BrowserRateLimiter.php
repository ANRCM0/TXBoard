<?php

namespace App\Domains\Browser;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

final class BrowserRateLimiter
{
    public function check(string $account, string $ip): bool
    {
        return $this->budget('login:account', $account, 8) && $this->budget('login:ip', $ip, 30);
    }

    public function ingress(string $ip): bool
    {
        return $this->budget('ingress', $ip, (int) config('browser.ingress_per_minute', 600));
    }

    private function budget(string $kind, string $value, int $limit): bool
    {
        $store = config('browser.rate_store', 'redis');
        if (config('cache.stores.'.$store.'.driver') !== 'redis' && !app()->runningUnitTests()) {
            throw new \RuntimeException('BROWSER_LIMITER_UNAVAILABLE');
        }
        $key = 'core-browser:'.$kind.':'.hash('sha256', $value);
        if (config('cache.stores.'.$store.'.driver') === 'redis') {
            $used = Redis::connection(config('cache.stores.'.$store.'.connection', 'cache'))->eval(
                "local n=redis.call('INCR', KEYS[1]); if n == 1 then redis.call('EXPIRE', KEYS[1], 60) end; return n", 1, $key);
        } else {
            // Array storage is confined to PHPUnit's disposable fixtures, never a production admission fallback.
            $cache = Cache::store($store);
            $cache->add($key, 0, 60);
            $used = $cache->increment($key);
        }
        if (!is_int($used) || $used < 1) throw new \RuntimeException('BROWSER_LIMITER_UNAVAILABLE');
        return $used <= $limit;
    }
}
