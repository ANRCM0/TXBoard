<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /**
     * Retired site-wide appearance fields. The active theme's per-theme config
     * has replaced these values; writes are prohibited after migration.
     */
    public const RETIRED_APPEARANCE_KEYS = [
        'frontend_theme_sidebar',
        'frontend_theme_header',
        'frontend_theme_color',
        'frontend_background_url',
    ];

    public static function isRetiredAppearanceKey(string $name): bool
    {
        return in_array(strtolower($name), self::RETIRED_APPEARANCE_KEYS, true);
    }

    public static function assertWritableKey(string $name): void
    {
        if (self::isRetiredAppearanceKey($name)) {
            throw new \InvalidArgumentException("Retired global appearance setting: {$name}");
        }
    }

    protected $table = 'v2_settings';
    protected $guarded = [];
    protected $casts = [
        'name' => 'string',
        'value' => 'string',
    ];

    /**
     * 获取实际内容值
     */
    public function getContentValue()
    {
        $rawValue = $this->attributes['value'] ?? null;
        
        if ($rawValue === null) {
            return null;
        }

        // 如果已经是数组，直接返回
        if (is_array($rawValue)) {
            return $rawValue;
        }

        // 如果是数字字符串，返回原值
        if (is_numeric($rawValue) && !preg_match('/[^\d.]/', $rawValue)) {
            return $rawValue;
        }

        // 尝试解析 JSON
        if (is_string($rawValue)) {
            $decodedValue = json_decode($rawValue, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decodedValue;
            }
        }

        return $rawValue;
    }

    /**
     * 兼容性：保持原有的 value 访问器
     */
    public function getValueAttribute($value)
    {
        return $this->getContentValue();
    }

    /**
     * 创建或更新设置项
     */
    public static function createOrUpdate(string $name, $value): self
    {
        self::assertWritableKey($name);
        $processedValue = is_array($value) ? json_encode($value) : $value;
        
        return self::updateOrCreate(
            ['name' => $name],
            ['value' => $processedValue]
        );
    }
}
