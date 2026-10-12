<?php

namespace App\Domains\InternalReadOnly;

use App\Models\Setting as SettingModel;
use App\Services\CaptchaService;
use App\Support\Setting;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/** Scoped to this dispatcher; no native CAPTCHA or mail/queue binding is replaced. */
final class InternalEmailCodeCaptcha extends CaptchaService
{
    public function verify(Request $request): array
    {
        try {
            $this->requireLocalDelivery();
            $disabled = $this->knownDisabled();
        } catch (SliceFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        return $disabled ? parent::verify($request) : [false, null];
    }

    private function requireLocalDelivery(): void
    {
        if (config('queue.default') !== 'sync'
            || config('queue.connections.sync.after_commit', false) !== false
            || config('mail.driver') !== 'array') {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        $queue = Queue::connection('sync');
        $manager = Mail::getFacadeRoot();
        if ($queue::class !== SyncQueue::class || ($queue->getConfig()['after_commit'] ?? false) !== false
            || $manager::class !== MailManager::class) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        $mailer = $manager->mailer();
        if ($mailer::class !== Mailer::class || $mailer->getSymfonyTransport()::class !== ArrayTransport::class) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
    }

    private function knownDisabled(): bool
    {
        $cached = Cache::store(config('cache.setting_store', 'redis'))->get(Setting::CACHE_KEY);
        if (!is_array($cached)) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        foreach (['captcha_enable' => 0, 'email_whitelist_enable' => 0] as $name => $default) {
            if ($this->flag($this->decode($cached[$name] ?? $default)) !== $this->flag(admin_setting($name, $default))) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
        }
        if ($this->suffixes($this->decode($cached['email_whitelist_suffix'] ?? Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            !== $this->suffixes(admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        $row = SettingModel::query()->useWritePdo()->where('name', 'captcha_enable')->sharedLock()->first();
        $current = $row === null ? 0 : $row->value;
        // A current SQL value must first be a known flag. Unknown values are
        // dependency failures, not evidence that a CAPTCHA provider is enabled.
        return $this->flag($current) === 0 && $this->flag(admin_setting('captcha_enable', 0)) === 0;
    }

    private function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
        return $value;
    }

    private function flag(mixed $value): int
    {
        if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        return (int) $value;
    }

    private function suffixes(mixed $value): array
    {
        if (is_string($value)) $value = explode(',', $value);
        if (!is_array($value) || !array_is_list($value)) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        foreach ($value as $suffix) {
            if (!is_string($suffix)) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        return $value;
    }
}
