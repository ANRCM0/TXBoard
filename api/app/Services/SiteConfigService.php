<?php

namespace App\Services;

use App\Services\Plugin\HookManager;
use App\Utils\Dict;
use App\Utils\Helper;

/**
 * Single source of truth for legacy and native UI settings.
 * The native controllers wrap these projections in TXAPI's envelope.
 */
final class SiteConfigService
{
    public function guest(): array
    {
        $themeService = app(ThemeService::class);
        $activeTheme = $themeService->getActiveTheme();
        $data = [
        // Public rendering settings originate from the active theme package,
        // never from the obsolete global frontend appearance keys.
        'frontend_theme' => $activeTheme,
        'theme_config' => $themeService->getPublicConfig($activeTheme),
        'tos_url' => admin_setting('tos_url'),
        'is_email_verify' => (int) admin_setting('email_verify', 0) ? 1 : 0,
        'is_invite_force' => (int) admin_setting('invite_force', 0) ? 1 : 0,
        'email_whitelist_suffix' => (int) admin_setting('email_whitelist_enable', 0)
        ? Helper::getEmailSuffix()
        : 0,
        'is_captcha' => (int) admin_setting('captcha_enable', 0) ? 1 : 0,
        'captcha_type' => admin_setting('captcha_type', 'recaptcha'),
        'recaptcha_site_key' => admin_setting('recaptcha_site_key'),
        'recaptcha_v3_site_key' => admin_setting('recaptcha_v3_site_key'),
        'recaptcha_v3_score_threshold' => admin_setting('recaptcha_v3_score_threshold', 0.5),
        'turnstile_site_key' => admin_setting('turnstile_site_key'),
        'app_description' => admin_setting('app_description'),
        'app_url' => admin_setting('app_url'),
        'logo' => admin_setting('logo'),
        'app_name' => admin_setting('app_name', 'TXBoard'),
        'stop_register' => (int) admin_setting('stop_register', 0),
        'login_with_mail_link_enable' => (int) admin_setting('login_with_mail_link_enable', 0),
        'try_out_enable' => (int) admin_setting('try_out_enable', 0),
        'try_out_plan_id' => (int) admin_setting('try_out_plan_id', 0),
        'traffic_warn_rate' => admin_setting('traffic_warn_rate', 0),
        // 保持向后兼容
        'is_recaptcha' => (int) admin_setting('captcha_enable', 0) ? 1 : 0,
        ];
        
        $data = array_merge($data, admin_feature_switches());
        
        $data = HookManager::filter('guest_comm_config', $data);
        
        return $data;
    }

    public function user(): array
    {
        $data = [
        'is_telegram' => (int)admin_setting('telegram_bot_enable', 0),
        'telegram_discuss_link' => admin_setting('telegram_discuss_link'),
        'stripe_pk' => admin_setting('stripe_pk_live'),
        'withdraw_methods' => admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
        'withdraw_close' => (int)admin_setting('withdraw_close_enable', 0),
        'currency' => admin_setting('currency', 'CNY'),
        'currency_symbol' => admin_setting('currency_symbol', '¥'),
        'commission_distribution_enable' => (int)admin_setting('commission_distribution_enable', 0),
        'commission_distribution_l1' => admin_setting('commission_distribution_l1'),
        'commission_distribution_l2' => admin_setting('commission_distribution_l2'),
        'commission_distribution_l3' => admin_setting('commission_distribution_l3'),
        'commission_withdraw_limit' => admin_setting('commission_withdraw_limit', 100),
        'commission_transfer_limit' => admin_transfer_minimum(),
        'ticket_must_wait_reply' => (int) admin_setting('ticket_must_wait_reply', 0),
        'plan_change_enable' => (int) admin_setting('plan_change_enable', 1),
        'try_out_enable' => (int) admin_setting('try_out_enable', 0),
        'try_out_plan_id' => (int) admin_setting('try_out_plan_id', 0),
        'traffic_warn_rate' => admin_setting('traffic_warn_rate', 0)
        ];
        
        $data = array_merge($data, admin_feature_switches());
        
        return $data;
    }
}
