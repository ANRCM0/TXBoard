<?php

namespace App\Services;

use App\Models\Setting;

/** Administrator-shell defaults are separate from subscriber theme packages and local light/dark preference. */
final class NativeAdminAppearance
{
    public const DEFAULTS = ['admin_sidebar_style' => 'normal', 'admin_header_style' => 'normal',
        'admin_color_scheme' => 'default', 'admin_login_background_url' => ''];

    public function publicSnapshot(): array
    {
        $rows = Setting::query()->useWritePdo()->whereIn('name', array_keys(self::DEFAULTS))->get()->keyBy('name');
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $rows->get($key)?->value ?? $default;
            $valid = match ($key) {
                'admin_sidebar_style' => in_array($value, ['normal', 'contrast'], true),
                'admin_header_style' => in_array($value, ['normal', 'accent'], true),
                'admin_color_scheme' => in_array($value, ['default', 'black', 'blackblue', 'green'], true),
                'admin_login_background_url' => self::validBackground($value),
            };
            $result[$key] = $valid ? $value : $default;
        }
        return $result;
    }

    public static function validBackground(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\s\\\\\x00-\x1f\x7f]/', $value)
            || preg_match('/%(?![0-9a-f]{2})/i', $value)) return false;
        if ($value === '') return true;
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            // Browsers normalize percent-encoded backslashes and controls before URL resolution.
            return preg_match('/[\s\\\\\x00-\x1f\x7f]/', rawurldecode($value)) !== 1;
        }
        return AdminFavicon::isValidUrl($value);
    }
}
