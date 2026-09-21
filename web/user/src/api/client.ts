import axios from 'axios'

export type ApiEnvelope<T> = {
  status?: string
  message?: string
  data?: T
  error?: unknown
}

const AUTH_KEY = 'xboard_auth_data'
const baseURL = (import.meta.env.VITE_API_V1_PREFIX || '/api/v1').replace(/\/$/, '')

export const api = axios.create({
  baseURL,
  timeout: 10_000,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

function normalizeAuthorization(value: string | null | undefined) {
  const token = String(value || '').trim()
  if (!token) return ''
  return /^Bearer\s+/i.test(token) ? token.replace(/^Bearer\s+/i, 'Bearer ') : `Bearer ${token}`
}

api.interceptors.request.use(config => {
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
    localStorage.removeItem(AUTH_KEY)
    return
  }
  localStorage.setItem(AUTH_KEY, normalized)
}

export function getAuthData() {
  return normalizeAuthorization(localStorage.getItem(AUTH_KEY))
}

export function clearAuthData() {
  localStorage.removeItem(AUTH_KEY)
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
  const value = error as { response?: { data?: { message?: string } }; message?: string }
  return value?.response?.data?.message || value?.message || '请求失败'
}
