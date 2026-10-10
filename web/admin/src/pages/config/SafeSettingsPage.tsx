import { SettingsForm, type SettingField } from '../../components/config/SettingsForm'

const fields: SettingField[] = [
  { key: 'email_verify', label: '注册邮箱验证', type: 'switch', section: '账号安全' },
  { key: 'safe_mode_enable', label: '安全模式', type: 'switch', section: '账号安全' },
  { key: 'secure_path', label: '后台安全路径', section: '账号安全', saveMode: 'blur', description: '至少 8 位，只允许字母、数字、下划线和连字符；离开输入框后立即生效，后台会自动切换到新地址，旧地址立即 404，无需重启。' },
  { key: 'email_gmail_limit_enable', label: '限制 Gmail 别名注册', type: 'switch', section: '邮箱限制' },
  { key: 'email_whitelist_enable', label: '启用邮箱后缀白名单', type: 'switch', section: '邮箱限制' },
  { key: 'email_whitelist_suffix', label: '允许的邮箱后缀', type: 'string-array', section: '邮箱限制', placeholder: 'gmail.com\noutlook.com', visibleWhen: v => Boolean(v.email_whitelist_enable) },

  { key: 'captcha_enable', label: '启用人机验证', type: 'switch', section: '人机验证' },
  { key: 'captcha_type', label: '验证类型', type: 'select', section: '人机验证', options: [
    { label: 'reCAPTCHA v2', value: 'recaptcha' },
    { label: 'Cloudflare Turnstile', value: 'turnstile' },
    { label: 'reCAPTCHA v3', value: 'recaptcha-v3' },
  ], visibleWhen: v => Boolean(v.captcha_enable) },
  { key: 'recaptcha_key', label: 'reCAPTCHA Secret Key', type: 'password', section: '人机验证', visibleWhen: v => Boolean(v.captcha_enable) && v.captcha_type === 'recaptcha' },
  { key: 'recaptcha_site_key', label: 'reCAPTCHA Site Key', section: '人机验证', visibleWhen: v => Boolean(v.captcha_enable) && v.captcha_type === 'recaptcha' },
  { key: 'recaptcha_v3_secret_key', label: 'reCAPTCHA v3 Secret Key', type: 'password', section: '人机验证', visibleWhen: v => Boolean(v.captcha_enable) && v.captcha_type === 'recaptcha-v3' },
  { key: 'recaptcha_v3_site_key', label: 'reCAPTCHA v3 Site Key', section: '人机验证', visibleWhen: v => Boolean(v.captcha_enable) && v.captcha_type === 'recaptcha-v3' },
  { key: 'recaptcha_v3_score_threshold', label: 'v3 分数阈值', type: 'number', min: 0, max: 1, step: 0.1, section: '人机验证', visibleWhen: v => Boolean(v.captcha_enable) && v.captcha_type === 'recaptcha-v3' },
  { key: 'turnstile_secret_key', label: 'Turnstile Secret Key', type: 'password', section: '人机验证', visibleWhen: v => Boolean(v.captcha_enable) && v.captcha_type === 'turnstile' },
  { key: 'turnstile_site_key', label: 'Turnstile Site Key', section: '人机验证', visibleWhen: v => Boolean(v.captcha_enable) && v.captcha_type === 'turnstile' },

  { key: 'register_limit_by_ip_enable', label: '启用 IP 注册限制', type: 'switch', section: '频率限制' },
  { key: 'register_limit_count', label: '同 IP 可注册次数', type: 'number', min: 1, section: '频率限制', visibleWhen: v => Boolean(v.register_limit_by_ip_enable) },
  { key: 'register_limit_expire', label: '注册限制周期（分钟）', type: 'number', min: 1, section: '频率限制', visibleWhen: v => Boolean(v.register_limit_by_ip_enable) },
  { key: 'password_limit_enable', label: '启用密码错误限制', type: 'switch', section: '频率限制' },
  { key: 'password_limit_count', label: '允许密码错误次数', type: 'number', min: 1, section: '频率限制', visibleWhen: v => Boolean(v.password_limit_enable) },
  { key: 'password_limit_expire', label: '密码限制周期（分钟）', type: 'number', min: 1, section: '频率限制', visibleWhen: v => Boolean(v.password_limit_enable) },
]

export function SafeSettingsPage() {
  return <SettingsForm settingKey="safe" title="安全设置" description="使用 TXAPI 原生设置接口，修改后立即生效。" fields={fields}/>
}
