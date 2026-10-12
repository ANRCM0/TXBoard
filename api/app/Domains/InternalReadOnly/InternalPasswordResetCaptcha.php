<?php

namespace App\Domains\InternalReadOnly;

use App\Models\Setting as SettingModel;
use App\Services\CaptchaService;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class InternalPasswordResetCaptcha extends CaptchaService
{
    public function verify(Request $request): array
    {
        try {
            $loaded = admin_setting('captcha_enable', 0);
            $cached = Cache::store(config('cache.setting_store', 'redis'))->get(Setting::CACHE_KEY);
            if (!is_array($cached)) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            $row = SettingModel::query()->useWritePdo()->where('name', 'captcha_enable')->sharedLock()->first();
            $current = $row === null ? 0 : $row->value;
            $stored = $cached['captcha_enable'] ?? 0;
            if (is_string($stored)) {
                $decoded = json_decode($stored, true);
                if (json_last_error() === JSON_ERROR_NONE) $stored = $decoded;
            }
            foreach ([$loaded, $stored, $current] as $flag) {
                if (!in_array($flag, [0, 1, '0', '1', false, true], true)) {
                    throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
                }
            }
            if ((int) $loaded !== (int) $stored || (int) $loaded !== (int) $current) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
        } catch (SliceFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        return (int) $loaded === 0 ? parent::verify($request) : [false, null];
    }
}
