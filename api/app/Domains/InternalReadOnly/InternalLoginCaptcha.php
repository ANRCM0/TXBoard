<?php

namespace App\Domains\InternalReadOnly;

use App\Models\Setting as SettingModel;
use App\Services\CaptchaService;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Passed only to the isolated dispatcher; never replaces native CAPTCHA. */
final class InternalLoginCaptcha extends CaptchaService
{
    public function verify(Request $request): array
    {
        try {
            $disabled = $this->knownDisabled();
        } catch (SliceFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        return $disabled ? parent::verify($request) : [false, null];
    }

    private function knownDisabled(): bool
    {
        $cached = Cache::store(config('cache.setting_store', 'redis'))->get(Setting::CACHE_KEY);
        if (!is_array($cached)) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        foreach (['captcha_enable' => 0, 'password_limit_enable' => true,
            'password_limit_count' => 5, 'password_limit_expire' => 60] as $name => $default) {
            $value = $cached[$name] ?? $default;
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) $value = $decoded;
            }
            if (!is_scalar($value) || (int) $value !== (int) admin_setting($name, $default)) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
        }
        $row = SettingModel::query()->useWritePdo()->where('name', 'captcha_enable')->sharedLock()->first();
        $current = $row === null ? 0 : $row->value;
        // Refuse noncanonical disabled values as well as enabled/unknown policy.
        if (!in_array($current, [0, '0', false], true)
            || !in_array(admin_setting('captcha_enable', 0), [0, '0', false], true)) {
            return false;
        }
        return true;
    }
}
