import { z } from 'zod'
import { readPublicBootstrap } from '../native/publicBootstrap'

export type GuestConfig = {
  is_captcha?: number; captcha_type?: string; recaptcha_site_key?: string
  recaptcha_v3_site_key?: string; recaptcha_v3_score_threshold?: number; turnstile_site_key?: string
}
export type { CaptchaPayload } from '@txboard/shared'
const captcha = z.discriminatedUnion('enabled', [
  z.object({ enabled: z.literal(false), type: z.null(), siteKey: z.null() }),
  z.object({ enabled: z.literal(true), type: z.enum(['turnstile', 'recaptcha', 'recaptcha-v3']),
    siteKey: z.string().trim().min(1).max(2048) }),
])
export async function fetchGuestConfig({ signal }: { signal?: AbortSignal } = {}): Promise<GuestConfig> {
  const parsed = z.object({ ok: z.literal(true), data: z.object({ security: z.object({ captcha }) }) })
    .parse(await readPublicBootstrap(signal)).data.security.captcha
  if (!parsed.enabled) return { is_captcha: 0 }
  return { is_captcha: 1, captcha_type: parsed.type,
    ...(parsed.type === 'turnstile' ? { turnstile_site_key: parsed.siteKey }
      : parsed.type === 'recaptcha-v3' ? { recaptcha_v3_site_key: parsed.siteKey }
        : { recaptcha_site_key: parsed.siteKey }) }
}
