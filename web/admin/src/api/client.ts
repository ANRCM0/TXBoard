import axios, { type AxiosError, type AxiosInstance } from 'axios'
import { toast } from 'sonner'
import { getAuthorizationHeader, removeAccessToken } from '../lib/storage'

type RuntimeSettings = {
  base_url?: string
  secure_path?: string
}

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

  const securePath = String(runtimeSettings().secure_path || '').trim()
  if (securePath) return `${runtimeBaseUrl()}/api/v2/${trimSlashes(securePath)}`

  // Backward-compatible development fallback. Real deployments should inject
  // window.settings.secure_path or VITE_API_V2_ADMIN_PREFIX.
  return `${runtimeBaseUrl()}/api/v2/de47dcba`
}

function attachCommonErrorHandling(client: AxiosInstance, options: { redirectOnAuthError: boolean }) {
  client.interceptors.response.use(
    response => response,
    (error: AxiosError<{ message?: string; error?: unknown }>) => {
      const status = error.response?.status
      const message = error.response?.data?.message || error.message || '请求失败'

      if (options.redirectOnAuthError && (status === 401 || status === 403)) {
        removeAccessToken()
        if (import.meta.env.VITE_STATIC_PREVIEW !== '1' && window.location.pathname !== '/sign-in') {
          const redirect = encodeURIComponent(window.location.pathname + window.location.search)
          window.location.assign(`/sign-in?redirect=${redirect}`)
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

apiClient.interceptors.request.use(config => {
  const authorization = getAuthorizationHeader()
  if (authorization) config.headers.Authorization = authorization
  return config
})

attachCommonErrorHandling(publicApiClient, { redirectOnAuthError: false })
attachCommonErrorHandling(apiClient, { redirectOnAuthError: true })

export function getResolvedApiPrefixes() {
  return {
    public: resolvePublicPrefix(),
    admin: resolveAdminPrefix(),
  }
}
