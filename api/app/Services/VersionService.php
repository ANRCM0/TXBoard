<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class VersionService
{
    private const CACHE_VERSION = 'CURRENT_VERSION';
    private const CACHE_VERSION_DATE = 'CURRENT_VERSION_DATE';

    public function getCurrentVersion(): string
    {
        $date = (string) (Cache::get(self::CACHE_VERSION_DATE) ?: date('Ymd'));
        $hash = (string) Cache::rememberForever(
            self::CACHE_VERSION,
            fn () => $this->currentCommit()
        );

        return $date . '-' . $hash;
    }

    public function refreshCache(): void
    {
        Cache::forever(self::CACHE_VERSION_DATE, date('Ymd'));
        Cache::forever(self::CACHE_VERSION, $this->currentCommit());
    }

    private function currentCommit(): string
    {
        $commit = trim((string) env('APP_COMMIT', 'unknown'));
        if ($commit === '') {
            return 'unknown';
        }

        return substr($commit, 0, 7);
    }
}
