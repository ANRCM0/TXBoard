<?php

namespace App\Services\Auth;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

final class EmailVerificationService
{
    public function send(string $email): array
    {
        // Reuse existing whitelist and email cache keys across API generations.
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            $registered = User::byEmail($email)->exists();
            if (!$registered) {
                $suffix = substr(strrchr($email, '@'), 1);
                if (!in_array($suffix, Helper::getEmailSuffix(), true)) {
                    return [false, [400, __('Email suffix is not in whitelist')]];
                }
            }
        }

        $cooldown = CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email);
        // Atomic add prevents two parallel requests sending two codes.
        if (!Cache::add($cooldown, time(), 60)) {
            return [false, [400, __('Email verification code has been sent, please request again later')]];
        }

        $code = random_int(100000, 999999);
        try {
            SendEmailJob::dispatch([
                'email' => $email,
                'subject' => admin_setting('app_name', 'TXBoard') . __('Email verification code'),
                'template_name' => 'verify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'TXBoard'),
                    'code' => $code,
                    'url' => admin_setting('app_url'),
                ],
            ]);
            Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        } catch (\Throwable $e) {
            Cache::forget($cooldown);
            throw $e;
        }
        return [true, true];
    }
}
