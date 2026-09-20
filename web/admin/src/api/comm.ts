import { publicApiClient } from './client'
import { unwrap } from '../lib/api'

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
  const { data } = await publicApiClient.get('/guest/comm/config')
  return unwrap<GuestConfig>(data)
}