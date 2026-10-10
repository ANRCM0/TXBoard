<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\SubscribeTemplate;
use App\Services\AdminSettingsProjection;
use App\Services\ThemeService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class SettingsAdminController
{
    public function index(Request $request, AdminSettingsProjection $projection): JsonResponse
    {
        return TxapiResponse::success($request, $projection->getConfigMappings())
            ->header('Cache-Control', 'no-store');
    }

    public function group(Request $request, AdminSettingsProjection $projection): JsonResponse
    {
        $group = (string) $request->route('group');
        $groups = $projection->getConfigMappings();
        if (!array_key_exists($group, $groups)) {
            return TxapiResponse::error($request, 'SETTINGS_GROUP_NOT_FOUND',
                'Unknown settings group', 404);
        }
        return TxapiResponse::success($request, [$group => $groups[$group]])
            ->header('Cache-Control', 'no-store');
    }

    public function save(ConfigSave $request): JsonResponse
    {
        $data = $request->validated();
        $templateKeys = [
            'subscribe_template_singbox' => 'singbox',
            'subscribe_template_clash' => 'clash',
            'subscribe_template_clashmeta' => 'clashmeta',
            'subscribe_template_stash' => 'stash',
            'subscribe_template_surge' => 'surge',
            'subscribe_template_surfboard' => 'surfboard',
        ];

        // Persist only the validated native settings; retired keys are rejected.
        foreach ($data as $key => $value) {
            if (isset($templateKeys[$key])) {
                SubscribeTemplate::setContent($templateKeys[$key], $value);
                continue;
            }
            if ($key === 'frontend_theme') {
                app(ThemeService::class)->switch($value);
                continue;
            }
            admin_setting([$key => $value]);
        }

        return TxapiResponse::success($request, ['ok' => true]);
    }

    /**
     * Register the native TXAPI Telegram callback using the saved bot token.
     * No webhook URL (which carries an authentication digest), credentials or
     * upstream exception details may be returned to the browser.
     */
    public function setTelegramWebhook(Request $request): JsonResponse
    {
        $input = $request->validate([
            'telegram_bot_token' => ['required', 'string', 'max:256'],
        ]);
        $storedToken = trim((string) admin_setting('telegram_bot_token', ''));
        if ($storedToken === '' || !hash_equals($storedToken, trim($input['telegram_bot_token']))) {
            return TxapiResponse::error($request, 'TELEGRAM_SETTINGS_NOT_SAVED',
                'Save the current Telegram bot token before registering the webhook', 409)
                ->header('Cache-Control', 'no-store');
        }

        $baseUrl = trim((string) admin_setting('telegram_webhook_url', ''));
        if ($baseUrl === '') {
            $baseUrl = trim((string) admin_setting('app_url', ''));
        }
        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)
            || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($baseUrl, PHP_URL_QUERY) !== null
            || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null) {
            return TxapiResponse::error($request, 'TELEGRAM_WEBHOOK_URL_INVALID',
                'Configure a valid Telegram webhook base URL first', 422)
                ->header('Cache-Control', 'no-store');
        }

        $baseUrl = rtrim($baseUrl, '/');
        $hookUrl = str_ends_with($baseUrl, '/txapi/integrations/telegram/webhook')
            ? $baseUrl : $baseUrl . '/txapi/integrations/telegram/webhook';
        $hookUrl .= '?' . http_build_query(['access_token' => md5($storedToken)]);

        try {
            $telegram = new TelegramService($storedToken);
            $telegram->getMe();
            $telegram->setWebhook(url: $hookUrl);
            $telegram->registerBotCommands();
        } catch (Throwable) {
            return TxapiResponse::error($request, 'TELEGRAM_WEBHOOK_FAILED',
                'Telegram webhook registration failed', 503)
                ->header('Cache-Control', 'no-store');
        }

        return TxapiResponse::success($request, ['ok' => true])
            ->header('Cache-Control', 'no-store');
    }
}
