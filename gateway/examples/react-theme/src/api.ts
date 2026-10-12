// React theme bootstrap: single SDK client + token store (GW-505).
// Identical security semantics to the Vue template: only the Gateway is
// contacted — never Laravel directly.
import { createTXBoardClient } from '@txboard/theme-sdk'
import type { BootstrapData, CaptchaConfig } from '@txboard/theme-sdk'

const TOKEN_KEY = 'txboard_auth_data'

export const readToken = (): string => {
  try { return (localStorage.getItem(TOKEN_KEY) || '').trim() } catch { return '' }
}
export const writeToken = (value: string): void => {
  const token = String(value || '').trim()
  if (!token) throw new Error('登录接口未返回有效凭证')
  localStorage.setItem(TOKEN_KEY, token)
}
export const clearToken = (): void => {
  try { localStorage.removeItem(TOKEN_KEY) } catch { /* storage unavailable */ }
}

export const api = createTXBoardClient({
  baseURL: import.meta.env.VITE_GATEWAY_BASE_URL || '/gateway/v1',
  getToken: () => readToken(),
  encryptedLogin: import.meta.env.VITE_ENCRYPTED_LOGIN === 'true',
  timeoutMs: 10000,
})

// Fail-closed CAPTCHA check: enabled=true with a missing siteKey or type must
// block login rather than silently downgrade to an unverified submit.
export const captchaBlocksLogin = (captcha: CaptchaConfig | null | undefined): boolean =>
  !!captcha?.enabled && (!captcha.type || !captcha.siteKey)

export const hasCapability = (caps: string[], cap: string): boolean =>
  Array.isArray(caps) && caps.includes(cap)

export function newIdempotencyKey(): string {
  return globalThis.crypto?.randomUUID?.() ??
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, ch => {
      const r = Math.floor(Math.random() * 16)
      return (ch === 'x' ? r : (r & 0x3) | 0x8).toString(16)
    })
}

export type { BootstrapData }
