import { t } from '../lib/i18n'
import type { ConfigurationGroup } from './ConfigurationPage'

export const configurationDescriptions: Record<ConfigurationGroup, string> = {
  site: '设置站点名称、访问地址和注册入口，保持品牌信息一致。',
  features: '按业务需要管理用户可用的功能入口。',
  safe: '配置注册验证、验证码服务与登录限制。',
  subscribe: '管理套餐变更与订单场景的权益设置。',
  server: '配置节点同步周期与设备限制模式。',
  email: '配置 SMTP 服务和发件人信息，保存后可在邮件模板页发送测试。',
  telegram: '管理机器人凭据、Webhook 地址与讨论群入口。',
  app: '分别维护各平台客户端的版本号与下载地址。',
}

export const configurationHints: Record<string, string> = {
  app_name: '显示在站点标题和通知中的名称。',
  app_description: '简短介绍站点提供的服务。',
  app_url: '填写用户访问站点的完整地址，例如 https://example.com。',
  logo: '填写品牌标志的图片地址。',
  tos_url: '用户可访问的服务条款页面地址。',
  stop_register: '开启后停止接受新用户注册。',
  email_verify: '注册时要求完成邮箱验证，请先确认邮件服务可用。',
  email_whitelist_suffix: '每行一个邮箱域名，例如 example.com；启用白名单后使用。',
  captcha_type: '选择已配置站点密钥与私密密钥的验证码服务。',
  login_limit_count: '触发登录限制前允许的失败次数。',
  login_limit_expire: '达到限制后需要等待的分钟数。',
  register_limit_count: '同一 IP 在限制周期内允许注册的次数。',
  register_limit_expire: '注册次数限制的统计周期，单位为分钟。',
  reset_traffic_method: '用于新发布套餐，已有套餐的重置方式在套餐页维护。',
  server_pull_interval: '节点拉取配置的间隔，单位为秒。',
  server_push_interval: '节点上报数据的间隔，单位为秒。',
  email_host: 'SMTP 服务器域名，例如 smtp.example.com。',
  email_port: '使用邮件服务商提供的 SMTP 端口。',
  email_username: '用于 SMTP 认证的账号。',
  email_from_address: '邮件发件人地址，应符合服务商的发信要求。',
  email_encryption: '与 SMTP 服务商要求一致；TLS 通常用于 STARTTLS。',
  telegram_webhook_url: '填写机器人接收回调的站点地址。',
  telegram_discuss_link: '填写用户可访问的 Telegram 讨论群链接。',
}

const sections: Record<string, string> = {
  app_name: '站点信息', app_description: '站点信息', app_url: '站点信息', logo: '站点信息', tos_url: '站点信息', stop_register: '注册入口',
  email_verify: '注册与访问', email_whitelist_enable: '注册与访问', email_gmail_limit_enable: '注册与访问', captcha_enable: '注册与访问', register_limit_by_ip_enable: '注册与访问', email_whitelist_suffix: '注册与访问',
  login_limit_enabled: '登录与注册频率', login_limit_count: '登录与注册频率', login_limit_expire: '登录与注册频率', register_limit_count: '登录与注册频率', register_limit_expire: '登录与注册频率',
  email_host: 'SMTP 服务器', email_port: 'SMTP 服务器', email_username: 'SMTP 服务器', email_from_address: '发件与认证', email_encryption: '发件与认证', email_password: '发件与认证',
}
export function configurationSection(name: string, group: ConfigurationGroup) {
  if (group === 'app') return name.startsWith('windows') ? 'Windows' : name.startsWith('macos') ? 'macOS' : 'Android'
  return sections[name] ?? (group === 'safe' ? '验证码服务' : '')
}
export function configurationOption(value: string) {
  return ({ '': '不加密', ssl: 'SSL / TLS', tls: 'STARTTLS', recaptcha: 'Google reCAPTCHA v2', 'recaptcha-v3': 'Google reCAPTCHA v3', turnstile: 'Cloudflare Turnstile' } as Record<string, string>)[value] ?? value
}

export function configurationFieldVisible(name: string, values: Record<string, unknown>) {
  if (name === 'captcha_type') return Boolean(values.captcha_enable)
  const provider = name.startsWith('recaptcha_v3_') ? 'recaptcha-v3' : name.startsWith('recaptcha_') ? 'recaptcha' : name.startsWith('turnstile_') ? 'turnstile' : undefined
  return !provider || Boolean(values.captcha_enable) && values.captcha_type === provider
}

export function configurationChoices(name: string): [number, string][] | undefined {
  if (name === 'reset_traffic_method') return [[0, '每月 1 日重置'], [1, '按月重置'], [2, '不自动重置'], [3, '每年 1 月 1 日重置'], [4, '按年重置']]
  if (['new_order_event_id', 'renew_order_event_id', 'change_order_event_id'].includes(name)) return [[0, '不追加流量重置'], [1, '订单生效时重置流量']]
}

export function configurationDisplayOption(name: string, value: unknown) {
  return configurationChoices(name)?.find(([key]) => key === Number(value))?.[1] ?? configurationOption(String(value))
}

export const mailVariableHelp: Record<string, { description: string; example: string }> = {
  name: { description: '站点名称', example: '示例站点' },
  url: { description: '站点访问地址', example: 'https://example.com' },
  code: { description: '邮箱验证码', example: '123456' },
  content: { description: '本次通知正文', example: '这是一封示例通知邮件。' },
  link: { description: '本次邮件登录链接', example: 'https://example.com/login?token=sample' },
}

export function mailSample(source: string, html = false) {
  return source.replace(/\{\{\s*([a-zA-Z_]+)\s*\}\}/g, (token, name: string) => {
    const sourceValue = mailVariableHelp[name]?.example
    const value = sourceValue ? t(sourceValue) : undefined
    if (!value) return token
    return html ? value.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]!) : value
  })
}
