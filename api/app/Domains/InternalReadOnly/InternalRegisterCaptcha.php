<?php

namespace App\Domains\InternalReadOnly;

use App\Models\Setting as SettingModel;
use App\Services\CaptchaService;
use App\Support\Setting;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class InternalRegisterCaptcha extends CaptchaService
{
    private const POLICY = [
        'captcha_enable' => 0, 'email_whitelist_enable' => 0,
        'email_whitelist_suffix' => Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT,
        'email_gmail_limit_enable' => 0, 'stop_register' => 0,
        'invite_force' => 0, 'invite_never_expire' => 0, 'email_verify' => 0,
        'register_limit_by_ip_enable' => 0, 'register_limit_count' => 3, 'register_limit_expire' => 60,
        'try_out_plan_id' => 0, 'try_out_hour' => 1, 'default_remind_expire' => 1, 'default_remind_traffic' => 1,
    ];

    public function verify(Request $request): array
    {
        try {
            // validateRegister has already loaded the request's native settings.
            $cached = Cache::store(config('cache.setting_store', 'redis'))->get(Setting::CACHE_KEY);
            if (!is_array($cached)) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            $rows = SettingModel::query()->useWritePdo()->whereIn('name', array_keys(self::POLICY))
                ->sharedLock()->get()->keyBy('name');
            foreach (self::POLICY as $name => $default) {
                $loaded = admin_setting($name, $default);
                $current = isset($rows[$name]) ? $this->decode($rows[$name]->getRawOriginal('value')) : $default;
                if ($this->decode($cached[$name] ?? $default) !== $loaded || $current !== $loaded) {
                    throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
                }
            }
            $flag = admin_setting('captcha_enable', 0);
            if (!in_array($flag, [0, 1, '0', '1', false, true], true)) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
        } catch (SliceFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        return (int) $flag === 0 ? parent::verify($request) : [false, null];
    }

    private function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
        return $value;
    }
}
