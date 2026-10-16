<?php

namespace App\Models;

use App\Domains\Identity\AccountSqlPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SubscribeTemplate extends Model
{

protected $table = 'tx_subscribe_templates';
    protected $guarded = [];
    protected $casts = [
        'name' => 'string',
        'content' => 'string',
    ];

    private static string $cachePrefix = 'subscribe_template:';

    public static function getContent(string $name): ?string
    {
        $cacheKey = self::$cachePrefix . $name;

        return Cache::store('redis')->remember($cacheKey, 3600, function () use ($name) {
            return self::where('name', $name)->value('content');
        });
    }

    public static function setContent(string $name, ?string $content): void
    {
        $phase = static fn () => self::updateOrCreate(
            ['name' => $name], ['content' => $content]
        );
        if (!AccountSqlPhase::enabled()) {
            $phase();
            Cache::store('redis')->forget(self::$cachePrefix . $name);
            return;
        }
        app(AccountSqlPhase::class)->run(AccountSqlPhase::SETTINGS_SUBSCRIBE_TEMPLATE_WRITE, $phase);
        try {
            Cache::store('redis')->forget(self::$cachePrefix . $name);
        } catch (\Throwable) {
            throw new \RuntimeException('AUTHORITY_TEMPLATE_CACHE_OUTCOME_UNKNOWN');
        }
    }

    public static function getAllContents(): array
    {
        return self::pluck('content', 'name')->toArray();
    }

    public static function flushCache(string $name): void
    {
        Cache::store('redis')->forget(self::$cachePrefix . $name);
    }
}
