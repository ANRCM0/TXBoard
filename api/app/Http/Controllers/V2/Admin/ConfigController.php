<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\SubscribeTemplate;
use App\Services\MailService;
use App\Services\TelegramService;
use App\Services\ThemeService;
use App\Utils\Dict;
use Illuminate\Http\Request;

class ConfigController extends Controller
{


    public function getEmailTemplate()
    {
        $path = resource_path('views/mail/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return $this->success($files);
    }

    public function getThemeTemplate()
    {
        $path = public_path('theme/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return $this->success($files);
    }

    public function testSendMail(Request $request)
    {
        $mailLog = MailService::sendEmail([
            'email' => $request->user()->email,
            'subject' => 'This is xboard test email',
            'template_name' => 'notify',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'content' => 'This is xboard test email',
                'url' => admin_setting('app_url')
            ]
        ]);
        return response([
            'data' => $mailLog,
        ]);
    }
    public function setTelegramWebhook(Request $request)
    {
        $hookUrl = $this->resolveTelegramWebhookUrl();
        if (blank($hookUrl)) {
            return $this->fail([422, 'Telegram Webhook地址未配置']);
        }
        $hookUrl .= '?' . http_build_query([
            'access_token' => md5(admin_setting('telegram_bot_token', $request->input('telegram_bot_token')))
        ]);
        $telegramService = new TelegramService($request->input('telegram_bot_token'));
        $telegramService->getMe();
        $telegramService->setWebhook(url: $hookUrl);
        $telegramService->registerBotCommands();
        return $this->success([
            'success' => true,
            'webhook_url' => $hookUrl,
            'webhook_base_url' => $this->getTelegramWebhookBaseUrl(),
        ]);
    }

    public function fetch(Request $request)
    {
        $key = $request->input('key');
        $configMappings = $this->getConfigMappings();
        if ($key && isset($configMappings[$key])) {
            return $this->success([$key => $configMappings[$key]]);
        }

        return $this->success($configMappings);
    }

    /**
     * 获取配置映射数据
     * 
     * @return array 配置映射数组
     */
    private function getConfigMappings(): array
    {
        return app(\App\Services\AdminSettingsProjection::class)->getConfigMappings();
    }

    public function save(ConfigSave $request)
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

        foreach ($data as $k => $v) {
            if (isset($templateKeys[$k])) {
                SubscribeTemplate::setContent($templateKeys[$k], $v);
                continue;
            }
            if ($k === 'frontend_theme') {
                app(ThemeService::class)->switch($v);
                continue;
            }
            admin_setting([$k => $v]);
        }

        return $this->success(true);
    }

    /**
     * 格式化模板内容
     * 
     * @param mixed $content 模板内容
     * @param string $format 输出格式 (json|string)
     * @return string 格式化后的内容
     */
    private function getTelegramWebhookBaseUrl(): ?string
    {
        $customUrl = trim((string) admin_setting('telegram_webhook_url', ''));
        if ($customUrl !== '') {
            return rtrim($customUrl, '/');
        }

        $appUrl = trim((string) admin_setting('app_url', ''));
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        return null;
    }

    private function resolveTelegramWebhookUrl(): ?string
    {
        $baseUrl = $this->getTelegramWebhookBaseUrl();
        if (!$baseUrl) {
            return null;
        }

        if (str_contains($baseUrl, '/api/v1/guest/telegram/webhook')) {
            return $baseUrl;
        }

        return $baseUrl . '/api/v1/guest/telegram/webhook';
    }
}
