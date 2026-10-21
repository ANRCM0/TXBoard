import { publicBootstrapData, readPublicBootstrap } from '../native/publicBootstrap'

export type GuestConfig = {
  site?: { name: string; description: string; logo: string | null }
  is_captcha?: number; captcha_type?: string; recaptcha_site_key?: string
  recaptcha_v3_site_key?: string; recaptcha_v3_score_threshold?: number; turnstile_site_key?: string
}
export function publicLogo(value: unknown): string | null {
  if (typeof value !== 'string' || value.length > 2048 || /[\s\\\u0000-\u001f]/.test(value)) return null
  if (value.startsWith('/') && !value.startsWith('//')) return value
  try {
    const url = new URL(value)
    return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password ? url.href : null
  } catch { return null }
}
export type { CaptchaPayload } from '@txboard/shared'
function object(value: unknown): Record<string, unknown> | undefined {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : undefined
}
export async function fetchGuestConfig({ signal }: { signal?: AbortSignal } = {}): Promise<GuestConfig> {
  const data = publicBootstrapData(await readPublicBootstrap(signal))
  const parsed = object(object(data.security)?.captcha)
  const disabled = parsed?.enabled === false && parsed.type === null && parsed.siteKey === null
  const enabled = parsed?.enabled === true && typeof parsed.type === 'string'
    && ['turnstile', 'recaptcha', 'recaptcha-v3'].includes(parsed.type)
    && typeof parsed.siteKey === 'string' && parsed.siteKey.trim().length > 0 && parsed.siteKey.trim().length <= 2048
  if (!disabled && !enabled) throw new Error('PUBLIC_CAPTCHA_INVALID')
  const branding = object(data.site)
  const name = typeof branding?.name === 'string' ? branding.name.trim() : ''
  const description = typeof branding?.description === 'string' ? branding.description.trim() : undefined
  const site = name && name.length <= 120 && description !== undefined && description.length <= 500
    ? { site: { name, description, logo: publicLogo(branding?.logo) } } : {}
  if (disabled) return { is_captcha: 0, ...site }
  const siteKey = (parsed!.siteKey as string).trim(), type = parsed!.type as string
  return { is_captcha: 1, ...site, captcha_type: type,
    ...(type === 'turnstile' ? { turnstile_site_key: siteKey }
      : type === 'recaptcha-v3' ? { recaptcha_v3_site_key: siteKey }
        : { recaptcha_site_key: siteKey }) }
}
