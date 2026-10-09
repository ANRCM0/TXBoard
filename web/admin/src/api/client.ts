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

function buildAdminPrefix(securePath: string) {
  return `${runtimeBaseUrl()}/api/v2/${trimSlashes(securePath)}`
}

/**
 * Caches the instance-specific admin secure path learned from a successful
 * admin sign-in and re-points the admin API client at it.
 */
export function setAdminSecurePath(securePath: string) {
  const value = trimSlashes(String(securePath || '').trim())
  if (!value) return
  try {
    localStorage.setItem(ADMIN_SECURE_PATH_KEY, value)
  } catch {
    // Storage can be unavailable (private browsing); the in-memory base URL is
    // still switched below.
  }
  apiClient.defaults.baseURL = buildAdminPrefix(value)
}

/** Native administrator operations must keep the instance-specific secure path. */
export function nativeAdminPath(operation: 'audit-logs' | 'plans' | 'orders' | 'tickets' | 'users' | 'content/notices' | 'content/knowledge' | 'traffic-resets' | 'payment-methods' | 'queue' | 'coupons' | 'mail-templates' | 'settings' | 'gift-cards' | 'network-groups' | 'network-routes' | 'network-nodes' | 'network-machines' | 'themes' | 'plugins'): string {
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

function hasExplicitAdminPrefix() {
  return Boolean(
    String(
      import.meta.env.VITE_API_V2_ADMIN_PREFIX || import.meta.env.VITE_API_V2_PREFIX || '',
    ).trim(),
  )
}

function resolveNativePrefix() {
  const explicit = String(import.meta.env.VITE_TXAPI_PREFIX || '').trim()
  if (explicit) return explicit.replace(/\/$/, '')
  return `${runtimeBaseUrl()}/txapi`
}

function resolvePublicPrefix() {
  const explicit = String(import.meta.env.VITE_API_V2_PUBLIC_PREFIX || '').trim()
  if (explicit) return explicit.replace(/\/$/, '')
  return `${runtimeBaseUrl()}/api/v2`
}

function resolveAdminPrefix() {
  const explicit = String(
    import.meta.env.VITE_API_V2_ADMIN_PREFIX ||
    import.meta.env.VITE_API_V2_PREFIX ||
    '',
  ).trim()
  if (explicit) return explicit.replace(/\/$/, '')

  // The admin API lives behind an instance-generated secure path. It is either
  // injected by the PHP admin shell (window.settings.secure_path) or learned
  // from the admin sign-in response and cached for later page loads.
  const securePath = String(runtimeSettings().secure_path || '').trim() || readStoredSecurePath()
  if (securePath) return buildAdminPrefix(securePath)

  // Nothing is known yet: the operator still has to sign in through /sign-in,
  // which resolves the real path at runtime. Failing here is preferable to
  // silently probing a hardcoded, guessed prefix.
  return `${runtimeBaseUrl()}/api/v2`
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

      // A cached secure path goes stale when an operator rotates it, after
      // which every admin call answers 404. Drop the cache and send the
      // operator back to sign-in so the fresh path is learned again.
      if (
        options.redirectOnAuthError &&
        options.resetSecurePathOnNotFound !== false &&
        status === 404 &&
        !hasExplicitAdminPrefix() &&
        readStoredSecurePath()
      ) {
        clearAdminSecurePath()
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

// Native TXAPI is separate from the legacy admin secure-path namespace.
// Native 404s must never be interpreted as secure-path rotation.
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

export const publicApiClient = axios.create({
  baseURL: resolvePublicPrefix(),
  timeout: 10_000,
  headers: { 'Content-Type': 'application/json' },
})

export const apiClient = axios.create({
  baseURL: resolveAdminPrefix(),
  timeout: 10_000,
  headers: { 'Content-Type': 'application/json' },
})

// Plugin-owned admin APIs may intentionally live outside the instance-specific
// /api/v2/<secure_path> namespace. They still use the same Sanctum bearer token.
export const pluginApiClient = axios.create({
  baseURL: runtimeBaseUrl(),
  timeout: 10_000,
  headers: { 'Content-Type': 'application/json' },
})

for (const client of [apiClient, pluginApiClient, nativeApiClient]) {
  client.interceptors.request.use(config => {
    const authorization = getAuthorizationHeader()
    if (authorization) config.headers.Authorization = authorization
    return config
  })
}

attachCommonErrorHandling(publicApiClient, { redirectOnAuthError: false })
attachCommonErrorHandling(nativeApiClient, { redirectOnAuthError: true, resetSecurePathOnNotFound: false })
attachCommonErrorHandling(apiClient, { redirectOnAuthError: true })
// Root plugin routes share administrator auth but are not tied to the instance
// secure-path cache, so a plugin-level 404 must never invalidate that cache.
attachCommonErrorHandling(pluginApiClient, { redirectOnAuthError: true, resetSecurePathOnNotFound: false })

export function getResolvedApiPrefixes() {
  return {
    public: resolvePublicPrefix(),
    admin: resolveAdminPrefix(),
    native: resolveNativePrefix(),
  }
}
