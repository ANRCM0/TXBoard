import { nativeApiClient, unwrapNative, type NativeApiEnvelope } from './client'

export type GuestConfig = {
  is_captcha?: number
  captcha_type?: string
  recaptcha_site_key?: string
  recaptcha_v3_site_key?: string
  recaptcha_v3_score_threshold?: number
  turnstile_site_key?: string
}

// Single source of truth shared with the user SPA's captcha component.
export type { CaptchaPayload } from '@txboard/shared'

export async function fetchGuestConfig() {
  const config = await unwrapNative(nativeApiClient.get<NativeApiEnvelope<GuestConfig>>('/public/site-config'))
  // Fail closed rather than silently disabling CAPTCHA on a malformed site config.
  if (!config || (Number(config.is_captcha) !== 0 && Number(config.is_captcha) !== 1)) {
    throw new Error('Invalid public CAPTCHA configuration')
  }
  return config
}