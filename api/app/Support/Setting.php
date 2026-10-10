<?php

namespace App\Support;

use App\Models\Setting as SettingModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository;

class Setting
{
    const CACHE_KEY = 'admin_settings';

    private Repository $cache;
    private ?array $loadedSettings = null; // 请求内缓存

    public function __construct()
    {
        $this->cache = Cache::store(config('cache.setting_store', 'redis'));
    }

    /**
     * 获取配置.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (SettingModel::isRetiredKey($key)) {
            return $default;
        }
        $this->load();
        return Arr::get($this->loadedSettings, strtolower($key), $default);
    }

    /**
     * 设置配置信息.
     */
    public function set(string $key, mixed $value = null): bool
    {
        SettingModel::assertWritableKey($key);
        SettingModel::createOrUpdate(strtolower($key), $value);
        $this->flush();
        return true;
    }

    /**
     * 保存配置到数据库.
     */
    public function save(array $settings): bool
    {
        // Validate the whole batch before writing any settings.
        foreach (array_keys($settings) as $key) {
            SettingModel::assertWritableKey((string) $key);
        }
        foreach ($settings as $key => $value) {
            SettingModel::createOrUpdate(strtolower($key), $value);
        }
        $this->flush();
        return true;
    }

    /**
     * 删除配置信息
     */
    public function remove(string $key): bool
    {
        SettingModel::where('name', $key)->delete();
        $this->flush();
        return true;
    }

    /**
     * 更新单个设置项
     */
    public function update(string $key, $value): bool
    {
        return $this->set($key, $value);
    }
    
    /**
     * 批量获取配置项
     */
    public function getBatch(array $keys): array
    {
        $this->load();
        $result = [];
        
        foreach ($keys as $index => $item) {
            $isNumericIndex = is_numeric($index);
            if (SettingModel::isRetiredKey((string) ($isNumericIndex ? $item : $index))) {
                continue;
            }
            $key = strtolower($isNumericIndex ? $item : $index);
            $default = $isNumericIndex ? null : $item;
            
            $result[$item] = Arr::get($this->loadedSettings, $key, $default);
        }
        
        return $result;
    }
    
    /**
     * 将所有设置转换为数组
     */
    public function toArray(): array
    {
        $this->load();
        return $this->loadedSettings;
    }

    /**
     * 加载配置到请求内缓存
     */
    private function load(): void
    {
        if ($this->loadedSettings !== null) {
            return;
        }

        try {
            $settings = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
                return array_change_key_case(
                    SettingModel::pluck('value', 'name')->toArray(),
                    CASE_LOWER
                );
            });
            
            // Discard stale fields even if a shared cache predates the migration.
            foreach (array_merge(SettingModel::RETIRED_APPEARANCE_KEYS, SettingModel::RETIRED_RUNTIME_KEYS) as $retiredKey) {
                unset($settings[$retiredKey]);
            }

            // 处理JSON格式的值
            foreach ($settings as $key => $value) {
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $settings[$key] = $decoded;
                    }
                }
            }
            
            $this->loadedSettings = $settings;
        } catch (\Throwable) {
            $this->loadedSettings = [];
        }
    }

    /**
     * 清空缓存
     *
     * The database write has already succeeded at this point, so a cache
     * outage (for example tests without a reachable Redis) must not turn a
     * successful save into a failure.
     */
    private function flush(): void
    {
        try {
            $this->cache->forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Cache unavailable; the next load() will fall back to defaults.
        }
        $this->loadedSettings = null;
    }
}
