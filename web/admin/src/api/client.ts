import axios, { type AxiosError, type AxiosInstance } from 'axios'
import { toast } from 'sonner'
import { getAuthorizationHeader, removeAccessToken } from '../lib/storage'
import { currentRouterTarget, withBasePath } from '../lib/basePath'

type RuntimeSettings = {
  base_url?: string
  secure_path?: string
}

const ADMIN_SECURE_PATH_KEY = 'txboard_admin_secure_path'

function runtimeSettings(): RuntimeSettings {
  return (window as Window & { settings?: RuntimeSettings }).settings || {}
}

function trimSlashes(value: string) {
  return value.replace(/^\/+|\/+$/g, '')
}

function runtimeBaseUrl() {
  const value = String(runtimeSettings().base_url || '/').trim()
  if (!value || value === '/') return ''
  return '/' + trimSlashes(value)
}

function readStoredSecurePath() {
  try {
    return String(localStorage.getItem(ADMIN_SECURE_PATH_KEY) || '').trim()
  } catch {
    return ''
  }
}

/** Persist a rotating administrator secure path for native TXAPI requests. */
export function setAdminSecurePath(securePath: string) {
  const value = trimSlashes(String(securePath || '').trim())
  if (!value) return
  try {
    localStorage.setItem(ADMIN_SECURE_PATH_KEY, value)
  } catch {
    // Storage can be unavailable (private browsing); the in-memory base URL is
    // still switched below.
  }
}

/** Native administrator operations must keep the instance-specific secure path. */
export function nativeAdminPath(operation: 'audit-logs' | 'plans' | 'orders' | 'tickets' | 'users' | 'content/notices' | 'content/knowledge' | 'traffic-resets' | 'payment-methods' | 'queue' | 'coupons' | 'mail-templates' | 'settings' | 'gift-cards' | 'network-groups' | 'network-routes' | 'network-nodes' | 'network-machines' | 'themes' | 'plugins' | 'agents' | 'analytics' | 'modules'): string {
  // The stored value takes precedence over the injected initial setting after
  // secure_path rotation. Never guess a constant path or fall back to /me.
  const securePath = readStoredSecurePath() || String(runtimeSettings().secure_path || '').trim()
  if (!securePath) throw new Error('Administrator secure path is unavailable')
  return `/admin/${encodeURIComponent(securePath)}/${operation}`
}

export function clearAdminSecurePath() {
  try {
    localStorage.removeItem(ADMIN_SECURE_PATH_KEY)
  } catch {
    // ignore
  }
}

function resolveNativePrefix() {
  const explicit = String(import.meta.env.VITE_TXAPI_PREFIX || '').trim()
  if (explicit) return explicit.replace(/\/$/, '')
  return `${runtimeBaseUrl()}/txapi`
}

function attachCommonErrorHandling(client: AxiosInstance, options: { redirectOnAuthError: boolean; resetSecurePathOnNotFound?: boolean }) {
  client.interceptors.response.use(
    response => response,
    (error: AxiosError<{ message?: string; error?: unknown }>) => {
      const status = error.response?.status
      const message = error.response?.data?.message || error.message || '请求失败'

      if (options.redirectOnAuthError && status === 401) {
        removeAccessToken()
        const signInPath = withBasePath('/sign-in')
        if (import.meta.env.VITE_STATIC_PREVIEW !== '1' && window.location.pathname !== signInPath) {
          const redirect = encodeURIComponent(currentRouterTarget())
          window.location.assign(`${signInPath}?redirect=${redirect}`)
        }
      }

      if (status !== 401) toast.error(message)
      return Promise.reject(error)
    },
  )
}

// All first-party administration, including sign-in, uses native TXAPI.
// Native resource 404s must never be treated as administrator path rotation.
export const nativeApiClient = axios.create({
  baseURL: resolveNativePrefix(),
  timeout: 10_000,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

export type NativeApiEnvelope<T> = {
  data: T
  meta?: { page: number; per_page: number; total: number; last_page: number }
  request_id: string
}

export async function unwrapNative<T>(promise: Promise<{ data: NativeApiEnvelope<T> }>): Promise<T> {
  const { data } = await promise
  if (!data || typeof data !== 'object' || !('data' in data) || !data.request_id) {
    throw new Error('Invalid TXAPI response')
  }
  return data.data
}

// Plugin-owned APIs run under /plugin/*, outside the core admin namespace.
// They use the same administrator bearer but never the TXAPI admin namespace.
export const pluginApiClient = axios.create({
  baseURL: runtimeBaseUrl(),
  timeout: 10_000,
  headers: { 'Content-Type': 'application/json' },
})

for (const client of [pluginApiClient, nativeApiClient]) {
  client.interceptors.request.use(config => {
    const authorization = getAuthorizationHeader()
    if (authorization) config.headers.Authorization = authorization
    return config
  })
}

attachCommonErrorHandling(nativeApiClient, { redirectOnAuthError: true, resetSecurePathOnNotFound: false })
// Root plugin routes share administrator auth but are not tied to the instance
// secure-path cache, so a plugin-level 404 must never invalidate that cache.
attachCommonErrorHandling(pluginApiClient, { redirectOnAuthError: true, resetSecurePathOnNotFound: false })

export function getResolvedApiPrefixes() {
  return { native: resolveNativePrefix(), plugin: runtimeBaseUrl() }
}
