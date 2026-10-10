<?php

namespace App\Services;

/**
 * Shared mail template defaults and test content.
 * Used by both legacy V2 and native Admin to prevent contract drift.
 */
final class MailTemplateAdminService
{
    public function getTestSubject(string $name): string
    {
        $appName = admin_setting('app_name', 'TXBoard');
        return match ($name) {
            'verify' => "{$appName} - 验证码测试",
            'notify' => "{$appName} - 通知测试",
            'remindExpire' => "{$appName} - 到期提醒测试",
            'remindTraffic' => "{$appName} - 流量提醒测试",
            'mailLogin' => "{$appName} - 登录链接测试",
            default => "{$appName} - 邮件测试",
        };
    }

    public function getTestVars(string $name): array
    {
        $appName = admin_setting('app_name', 'TXBoard');
        $appUrl = admin_setting('app_url', 'https://example.com');

        return match ($name) {
            'verify' => [
                'name' => $appName,
                'code' => '123456',
                'url' => $appUrl,
            ],
            'notify' => [
                'name' => $appName,
                'content' => '这是一封测试通知邮件。',
                'url' => $appUrl,
            ],
            'remindExpire' => [
                'name' => $appName,
                'url' => $appUrl,
            ],
            'remindTraffic' => [
                'name' => $appName,
                'url' => $appUrl,
            ],
            'mailLogin' => [
                'name' => $appName,
                'link' => $appUrl . '/login?token=test-token',
                'url' => $appUrl,
            ],
            default => ['name' => $appName, 'url' => $appUrl],
        };
    }

    public function getDefaultSubject(string $name): string
    {
        $appName = admin_setting('app_name', 'TXBoard');
        return match ($name) {
            'verify' => "{$appName} - 邮箱验证码",
            'notify' => "{$appName} - 站点通知",
            'remindExpire' => "{$appName} - 服务即将到期",
            'remindTraffic' => "{$appName} - 流量使用提醒",
            'mailLogin' => "{$appName} - 邮件登录",
            default => "{$appName}",
        };
    }

    public function getDefaultContent(string $name): string
    {
        $theme = 'default';
        $viewName = "mail.{$theme}.{$name}";

        try {
            $viewPath = resource_path("views/mail/{$theme}/{$name}.blade.php");
            if (file_exists($viewPath)) {
                $blade = file_get_contents($viewPath);
                return self::bladeToPlaceholder($blade);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return self::hardcodedDefault($name);
    }

    /**
     * Convert Blade syntax to {{placeholder}} syntax for editing.
     */
    private static function bladeToPlaceholder(string $blade): string
    {
        // {{$var}} → {{var}}
        $result = preg_replace('/\{\{\s*\$([a-zA-Z_]+)\s*\}\}/', '{{$1}}', $blade);
        // {!! nl2br($var) !!} → {{var}}
        $result = preg_replace('/\{!!\s*nl2br\(\$([a-zA-Z_]+)\)\s*!!\}/', '{{$1}}', $result);
        // {!! $var !!} → {{var}}
        $result = preg_replace('/\{!!\s*\$([a-zA-Z_]+)\s*!!\}/', '{{$1}}', $result);
        return $result;
    }

    private static function hardcodedDefault(string $name): string
    {
        $layout = fn($title, $body) => <<<HTML
<div style="background: #eee">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0">
        <tbody>
        <tr>
            <td>
                <div style="background:#fff">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <thead>
                        <tr>
                            <td valign="middle" style="padding-left:30px;background-color:#415A94;color:#fff;padding:20px 40px;font-size: 21px;">{{name}}</td>
                        </tr>
                        </thead>
                        <tbody>
                        <tr style="padding:40px 40px 0 40px;display:table-cell">
                            <td style="font-size:24px;line-height:1.5;color:#000;margin-top:40px">{$title}</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户您好！<br /><br />{$body}
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td style="padding:20px 40px;font-size:12px;color:#999;line-height:20px;background:#f7f7f7"><a href="{{url}}" style="font-size:14px;color:#929292">返回{{name}}</a></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
</div>
HTML;

        return match ($name) {
            'verify' => $layout('邮箱验证码', '您的验证码是：{{code}}，请在 5 分钟内进行验证。如果该验证码不为您本人申请，请无视。'),
            'notify' => $layout('网站通知', '{{content}}'),
            'remindExpire' => $layout('服务到期提醒', '您的服务即将在24小时内到期，如需继续使用请及时续费。'),
            'remindTraffic' => $layout('流量使用提醒', '您的流量使用已达到80%，请注意流量使用情况。'),
            'mailLogin' => $layout('登入到{{name}}', '您正在登入到{{name}}, 请在 5 分钟内点击下方链接进行登入。如果您未授权该登入请求，请无视。<a href="{{link}}">{{link}}</a>'),
            default => $layout('通知', '{{content}}'),
        };
    }
}
