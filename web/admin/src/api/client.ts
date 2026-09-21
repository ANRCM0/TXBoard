import axios, { type AxiosError, type AxiosInstance } from 'axios'
import { toast } from 'sonner'
import { getAuthorizationHeader, removeAccessToken } from '../lib/storage'
import { withBasePath } from '../lib/basePath'

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

function attachCommonErrorHandling(client: AxiosInstance, options: { redirectOnAuthError: boolean }) {
  client.interceptors.response.use(
    response => response,
    (error: AxiosError<{ message?: string; error?: unknown }>) => {
      const status = error.response?.status
      const message = error.response?.data?.message || error.message || '请求失败'

      if (options.redirectOnAuthError && (status === 401 || status === 403)) {
        removeAccessToken()
        const signInPath = withBasePath('/sign-in')
        if (import.meta.env.VITE_STATIC_PREVIEW !== '1' && window.location.pathname !== signInPath) {
          const redirect = encodeURIComponent(window.location.pathname + window.location.search)
          window.location.assign(`${signInPath}?redirect=${redirect}`)
        }
      }

      // A cached secure path goes stale when an operator rotates it, after
      // which every admin call answers 404. Drop the cache and send the
      // operator back to sign-in so the fresh path is learned again.
      if (
        options.redirectOnAuthError &&
        status === 404 &&
        !hasExplicitAdminPrefix() &&
        readStoredSecurePath()
      ) {
        clearAdminSecurePath()
        removeAccessToken()
        const signInPath = withBasePath('/sign-in')
        if (import.meta.env.VITE_STATIC_PREVIEW !== '1' && window.location.pathname !== signInPath) {
          window.location.assign(signInPath)
        }
      }

      if (status !== 401) toast.error(message)
      return Promise.reject(error)
    },
  )
}

export const publicApiClient = axios.create({
  baseURL: resolvePublicPrefix(),
  timeout: 30_000,
  headers: { 'Content-Type': 'application/json' },
})

export const apiClient = axios.create({
  baseURL: resolveAdminPrefix(),
  timeout: 30_000,
  headers: { 'Content-Type': 'application/json' },
})

// Plugin-owned admin APIs may intentionally live outside the instance-specific
// /api/v2/<secure_path> namespace. They still use the same Sanctum bearer token.
export const pluginApiClient = axios.create({
  baseURL: runtimeBaseUrl(),
  timeout: 30_000,
  headers: { 'Content-Type': 'application/json' },
})

for (const client of [apiClient, pluginApiClient]) {
  client.interceptors.request.use(config => {
    const authorization = getAuthorizationHeader()
    if (authorization) config.headers.Authorization = authorization
    return config
  })
}

attachCommonErrorHandling(publicApiClient, { redirectOnAuthError: false })
attachCommonErrorHandling(apiClient, { redirectOnAuthError: true })
attachCommonErrorHandling(pluginApiClient, { redirectOnAuthError: true })

export function getResolvedApiPrefixes() {
  return {
    public: resolvePublicPrefix(),
    admin: resolveAdminPrefix(),
  }
}
