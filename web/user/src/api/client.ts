import axios from 'axios'

export type ApiEnvelope<T> = {
  status?: string
  message?: string
  data?: T
  error?: unknown
}

const AUTH_KEY = 'txboard_auth_data'
const LEGACY_AUTH_KEY = 'xboard_auth_data'
const nativeBaseURL = (import.meta.env.VITE_TXAPI_PREFIX || '/txapi').replace(/\/$/, '')
// Optional plugin API calls use same-origin /plugin/*, never removed V1/V2 paths.
const baseURL = '/'

export const api = axios.create({
  baseURL,
  timeout: 10_000,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

export const nativeApi = axios.create({
  baseURL: nativeBaseURL,
  timeout: 10_000,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

export type NativeEnvelope<T> = {
  data: T
  meta?: { page: number; per_page: number; total: number; last_page: number }
  request_id: string
}

export async function nativeRequest<T>(promise: Promise<{ data: NativeEnvelope<T> }>): Promise<T> {
  const { data } = await promise
  if (!data || typeof data !== 'object' || !('data' in data) || !data.request_id) {
    throw new Error('Invalid TXAPI response')
  }
  return data.data
}

function normalizeAuthorization(value: string | null | undefined) {
  const token = String(value || '').trim()
  if (!token) return ''
  return /^Bearer\s+/i.test(token) ? token.replace(/^Bearer\s+/i, 'Bearer ') : `Bearer ${token}`
}

for (const client of [api, nativeApi]) client.interceptors.request.use(config => {
  const authData = getAuthData()
  if (authData) config.headers.Authorization = authData
  return config
})

api.interceptors.response.use(
  response => response,
  error => {
    if (error?.response?.status === 403) {
      const message = String(error?.response?.data?.message || '')
      if (/login|token|auth|登录|认证|过期/i.test(message)) {
        forceLogout()
      }
    }
    return Promise.reject(error)
  },
)

nativeApi.interceptors.response.use(
  response => response,
  error => {
    // Native 401 signals an invalid user session; 403 may be a normal
    // permission denial and must not invalidate an otherwise valid login.
    if (error?.response?.status === 401 &&
        error?.response?.data?.error?.code === 'UNAUTHENTICATED') {
      forceLogout()
    }
    return Promise.reject(error)
  },
)

function forceLogout() {
  clearAuthData()
  void (async () => {
    try {
      const { useAuthStore } = await import('../stores/auth')
      const auth = useAuthStore()
      auth.user = null
      auth.authenticated = false
    } catch {}
    try {
      const { useUserCommConfig } = await import('../composables/useUserCommConfig')
      useUserCommConfig().reset()
    } catch {}
  })()
  if (!window.location.hash.includes('/login')) {
    const redirect = window.location.hash.replace(/^#/, '') || '/'
    window.location.hash = '#/login?redirect=' + encodeURIComponent(redirect)
  }
}

export function saveAuthData(authData: string) {
  const normalized = normalizeAuthorization(authData)
  if (!normalized) {
    clearAuthData()
    return
  }
  localStorage.setItem(AUTH_KEY, normalized)
  // Never leave an older bearer copy after a successful login.
  localStorage.removeItem(LEGACY_AUTH_KEY)
}

export function getAuthData() {
  const current = normalizeAuthorization(localStorage.getItem(AUTH_KEY))
  if (current) {
    localStorage.removeItem(LEGACY_AUTH_KEY)
    return current
  }
  const legacy = normalizeAuthorization(localStorage.getItem(LEGACY_AUTH_KEY))
  if (!legacy) return ''
  // One-time, lossless migration on the first read. If storage is unavailable
  // during migration, keep the old value so the current session still works.
  try {
    localStorage.setItem(AUTH_KEY, legacy)
    localStorage.removeItem(LEGACY_AUTH_KEY)
  } catch {
    return legacy
  }
  return legacy
}

export function clearAuthData() {
  localStorage.removeItem(AUTH_KEY)
  localStorage.removeItem(LEGACY_AUTH_KEY)
}

export async function request<T>(
  promise: Promise<{ data: T | ApiEnvelope<T> }>,
): Promise<T> {
  const { data } = await promise

  if (data && typeof data === 'object' && 'status' in data) {
    const envelope = data as ApiEnvelope<T>
    if (envelope.status && envelope.status !== 'success') {
      throw new Error(envelope.message || '请求失败')
    }
    if (envelope.data === undefined) {
      throw new Error(envelope.message || '响应数据为空')
    }
    return envelope.data
  }

  // A number of current Xboard endpoints intentionally return legacy
  // top-level structures such as { data, total } or { data, pagination }.
  // Preserve those responses instead of unwrapping their "data" field.
  return data as T
}

export function errorMessage(error: unknown) {
  const value = error as { response?: { data?: { message?: string; error?: { message?: string } } }; message?: string }
  return value?.response?.data?.error?.message || value?.response?.data?.message || value?.message || '请求失败'
}
