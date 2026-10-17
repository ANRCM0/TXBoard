// my-theme SDK bootstrap: single client instance (docs/developer-guide.md §3.3).
// All data flows through @txboard/theme-sdk -> /gateway/v1. No other upstream exists.
import { createTXBoardClient } from '@txboard/theme-sdk'
import type { BootstrapData } from '@txboard/theme-sdk'

const TOKEN_KEY = 'txboard_auth_data'

// The SDK never stores tokens; this module owns storage and lifetime.
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

// Same-origin Gateway. Cross-origin hosting requires the site's exact origin
// in GATEWAY_ALLOWED_ORIGINS and an HTTPS base URL.
export const api = createTXBoardClient({
  baseURL: import.meta.env.VITE_GATEWAY_BASE_URL || '/gateway/v1',
  getToken: () => readToken(),
  encryptedLogin: import.meta.env.VITE_ENCRYPTED_LOGIN === 'true',
  timeoutMs: 10000,
})

// Theme initialization: site metadata, theme appearance config, CAPTCHA
// public metadata and the capability list the deployment actually serves.
export async function loadBootstrap(): Promise<BootstrapData> {
  const bootstrap = await api.bootstrap()
  // enabled=true with a missing siteKey means the deployment is misconfigured:
  // the login UI must block captcha-less submits, never silently downgrade.
  const captcha = bootstrap.security?.captcha
  if (captcha?.enabled && (!captcha.type || !captcha.siteKey)) {
    throw new Error('CAPTCHA 已启用但配置不完整，请联系管理员')
  }
  return bootstrap
}

// Fail-closed capability checks: a UI element only appears when the Gateway
// advertised the operation in bootstrap.capabilities.
export const hasCapability = (caps: string[], cap: string): boolean =>
  Array.isArray(caps) && caps.includes(cap)

// Draft idempotency keys are generated per logical order and persisted, so a
// browser refresh never silently double-submits a write.
export function newIdempotencyKey(): string {
  return globalThis.crypto?.randomUUID?.() ??
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, ch => {
      const r = Math.floor(Math.random() * 16)
      return (ch === 'x' ? r : (r & 0x3) | 0x8).toString(16)
    })
}

// Persist the draft key across refreshes: sessionStorage scoped per
// offer_id. Core binds the unchanged key to the authenticated user and input.
// Without sessionStorage, keep the same draft key for this page lifetime.
const DRAFT_KEY_PREFIX = 'txboard_offer_draft_key:'
const draftKeys = new Map<string, string>()
export function draftKeyFor(offerId: string): string {
  const storageKey = `${DRAFT_KEY_PREFIX}${offerId}`
  const cached = draftKeys.get(offerId)
  if (cached) return cached
  try {
    const existing = sessionStorage.getItem(storageKey)
    if (existing) {
      draftKeys.set(offerId, existing)
      return existing
    }
    const created = newIdempotencyKey()
    draftKeys.set(offerId, created)
    sessionStorage.setItem(storageKey, created)
    return created
  } catch {
    const key = draftKeys.get(offerId) ?? newIdempotencyKey()
    draftKeys.set(offerId, key)
    return key
  }
}
