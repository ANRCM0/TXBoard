<?php
use App\Support\Setting;

if (!function_exists('admin_setting')) {
    /**
     * 获取或保存配置参数.
     *
     * @param  string|array  $key
     * @param  mixed  $default
     * @return App\Support\Setting|mixed
     */
    function admin_setting($key = null, $default = null)
    {
        $setting = app(Setting::class);

        if ($key === null) {
            return $setting->toArray();
        }

        if (is_array($key)) {
            $setting->save($key);
            return '';
        }

        return $setting->get($key) ?? $default;
    }
}

if (!function_exists('subscribe_template')) {
    /**
     * Get subscribe template content by protocol name.
     */
    function subscribe_template(string $name): ?string
    {
        return \App\Models\SubscribeTemplate::getContent($name);
    }
}

if (!function_exists('admin_settings_batch')) {
    /**
     * 批量获取配置参数，性能优化版本
     *
     * @param array $keys 配置键名数组
     * @return array 返回键值对数组
     */
    function admin_settings_batch(array $keys): array
    {
        return app(Setting::class)->getBatch($keys);
    }
}

if (!function_exists('admin_feature_switches')) {
    /**
     * Feature switches that gate built-in user routes.
     *
     * A missing value means "enabled" so that an install which never touched
     * these settings keeps every entry available. Persisted values are exposed
     * to the user SPA through the guest/user comm config endpoints.
     *
     * @return array<string, int>
     */
    function admin_feature_switches(): array
    {
        $switches = [];
        foreach ([
            'invite_enable',
            'commission_enable',
            'gift_card_enable',
            'coupon_enable',
            'ticket_enable',
            'knowledge_enable',
            'traffic_log_enable',
            'announcement_enable',
            'register_enable',
        ] as $key) {
            $switches[$key] = (int) admin_setting($key, 1);
        }

        return $switches;
    }
}

if (!function_exists('admin_transfer_minimum')) {
    /**
     * Effective minimum for a commission transfer, in major currency units.
     *
     * The admin field is optional: clearing it means "inherit the withdrawal
     * minimum". A stored empty string is not null, so it would bypass the
     * admin_setting() default and silently disable the check, which is why the
     * value is normalised here instead of at each call site. A value that is
     * present and numeric (including "0", meaning no minimum) is authoritative.
     */
    function admin_transfer_minimum(): float
    {
        $transferLimit = admin_setting('commission_transfer_limit');

        if ($transferLimit === null || $transferLimit === '' || !is_numeric($transferLimit)) {
            $transferLimit = admin_setting('commission_withdraw_limit', 100);
        }

        return (float) $transferLimit;
    }
}

if (!function_exists('source_base_url')) {
    /**
     * 获取来源基础URL，优先Referer，其次Host
     * @param string $path
     * @return string
     */
    function source_base_url(string $path = ''): string
    {
        $baseUrl = '';
        $referer = request()->header('Referer');

        if ($referer) {
            $parsedUrl = parse_url($referer);
            if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
                $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
                if (isset($parsedUrl['port'])) {
                    $baseUrl .= ':' . $parsedUrl['port'];
                }
            }
        }

        if (!$baseUrl) {
            $baseUrl = request()->getSchemeAndHttpHost();
        }

        $baseUrl = rtrim($baseUrl, '/');
        $path = ltrim($path, '/');
        return $baseUrl . '/' . $path;
    }
}
