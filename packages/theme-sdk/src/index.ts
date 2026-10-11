/**
 * TXBoard Gateway v1: framework-agnostic TypeScript client.
 * The SDK NEVER stores access tokens or credentials.
 */

export type GatewayMeta = { version: '1'; requestId: string }
export type GatewaySuccess<T> = { ok: true; data: T; meta: GatewayMeta }
export type GatewayFailure = {
  ok: false
  error: { code: string; message: string }
  meta: GatewayMeta
}
export type GatewayEnvelope<T> = GatewaySuccess<T> | GatewayFailure

export type ThemeData = { name: string; config: Record<string, unknown> }
export type SiteData = { name: string; description: string; url: string; logo: string }
export type CaptchaConfig = {
  enabled: boolean
  type: 'turnstile' | 'recaptcha' | 'recaptcha-v3' | null
  siteKey: string | null
}
export type BootstrapData = {
  site: SiteData
  theme: ThemeData
  security: { captcha: CaptchaConfig }
  capabilities: string[]
}

export type LoginPayload = {
  email: string
  password: string
  turnstile_token?: string
  recaptcha_v3_token?: string
  recaptcha_data?: string
  email_code?: string
}
export type SubscriptionSummary = {
  planId: number | null; planName: string | null; expiredAt: number | null
  upload: number | null; download: number | null; transferEnable: number | null
  resetDay: number | null; speedLimit: number | null; deviceLimit: number | null
}
export type PaymentMethod = {
  id: number; name: string; icon: string | null; payment: string | null
  handlingFeeFixed: number; handlingFeePercent: number
}
export type Notice = { id: number; [field: string]: unknown }
export type NoticePage = { data: Notice[]; total: number }
export type RegistrationPayload = LoginPayload & { invite_code?: string }
export type EmailCodePayload = Pick<LoginPayload, 'email' | 'turnstile_token' | 'recaptcha_v3_token' | 'recaptcha_data'>
export type DashboardStats = { unpaidOrders: number; openTickets: number; invitedUsers: number }
export type OrderStatus = { tradeNo: string; status: number }
export type UserAuth = { auth_data: string; is_admin?: boolean | number }
export type UserProfile = {
  email: string
  balance?: number
  plan_id?: number | null
  expired_at?: number | null
  [field: string]: unknown
}
export type Plan = {
  id: number
  name: string
  month_price?: number | null
  year_price?: number | null
  [field: string]: unknown
}
export type Order = {
  trade_no: string
  status: number
  plan_id: number
  period: string
  total_amount: number
  [field: string]: unknown
}

export class GatewayApiError extends Error {
  constructor(
    public readonly code: string,
    public readonly status: number,
    message: string,
    public readonly requestId?: string,
    /**
     * Whether this error is on the retryable-status list (GET only).
     * `true` for statuses in the retryable set (default 408, 429, 500, 502, 503, 504),
     * or when the server explicitly signals retryability via envelope extension.
     * `false` for all non-retryable statuses and client errors like UNAUTHORIZED.
     */
    public readonly retryable: boolean = false,
    /**
     * Whether the caller should confirm remote state before proceeding.
     * Set when the outcome is indeterminate (e.g. timeout on a write, or an
     * unknown error code that could indicate the operation partially succeeded).
     */
    public readonly needsStateConfirmation: boolean = false,
  ) {
    super(message)
    this.name = 'GatewayApiError'
  }
}

export type TXBoardClientOptions = {
  /** Defaults to /gateway/v1. Use an HTTPS URL for cross-origin frontend hosting. */
  baseURL?: string
  /** User bearer callback; never used for public endpoints. */
  getToken?: () => string | undefined | null | Promise<string | undefined | null>
  fetchImpl?: typeof fetch
  timeoutMs?: number
  /** Experimental HPKE request encryption for login only. Never silently fall back. */
  encryptedLogin?: boolean
  /** Maximum retry attempts for idempotent GET requests. Default: 3. Write requests never auto-retry. */
  maxRetries?: number
  /** Base delay in milliseconds for exponential backoff. Default: 500. Delay = baseRetryDelay * 2^(attempt-1). */
  baseRetryDelay?: number
  /** HTTP status codes that trigger retry on GET requests. Default: [408, 429, 500, 502, 503, 504]. */
  retryableStatuses?: number[]
}

const DEFAULT_RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504] as const

/**
 * Error codes the SDK can classify with certainty. Anything outside this set is
 * treated as an unknown state — the caller should confirm remote state before
 * proceeding (GW-308).
 */
const KNOWN_ERROR_CODES = new Set([
  'UNAUTHORIZED', 'FORBIDDEN', 'NOT_FOUND', 'VERSION_MISMATCH', 'INVALID_RESPONSE',
  'NETWORK_ERROR', 'ORIGIN_DENIED', 'VALIDATION_ERROR', 'RATE_LIMITED', 'DISABLED',
  'MAINTENANCE', 'UPSTREAM_UNAVAILABLE', 'GATEWAY_ERROR',
])

function sleep(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms))
}

export function createTXBoardClient(options: TXBoardClientOptions = {}) {
  const baseURL = (options.baseURL || '/gateway/v1').replace(/\/+$/, '')
  const fetchImpl = options.fetchImpl ?? fetch
  const timeout = options.timeoutMs ?? 10000
  if (!Number.isSafeInteger(timeout) || timeout < 100 || timeout > 60000) {
    throw new Error('timeoutMs must be between 100 and 60000 milliseconds')
  }
  if (/^https?:\/\//.test(baseURL) && !baseURL.startsWith('https://')
    && !/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//.test(baseURL + '/')) {
    throw new Error('Cross-origin Gateway access requires HTTPS')
  }

  const maxRetries = options.maxRetries ?? 3
  const baseRetryDelay = options.baseRetryDelay ?? 500
  const retryableStatuses = new Set(options.retryableStatuses ?? DEFAULT_RETRYABLE_STATUSES)

  /** True when a GET request may be retried for this error. */
  function canRetryGet(error: GatewayApiError, attempt: number): boolean {
    if (attempt >= maxRetries) return false
    if (error.code === 'NETWORK_ERROR') return true
    return retryableStatuses.has(error.status)
  }

  /**
   * Backoff delay for the given attempt (1-based). Honours a server-supplied
   * `Retry-After` hint (seconds or HTTP-date) when present, capped so a hostile
   * or misconfigured upstream cannot park the caller for an unbounded time.
   */
  const MAX_RETRY_AFTER_MS = 60000

  function retryDelay(attempt: number, retryAfterHeader: string | null): number {
    const backoff = baseRetryDelay * 2 ** (attempt - 1)
    if (!retryAfterHeader) return backoff
    const trimmed = retryAfterHeader.trim()
    const seconds = Number(trimmed)
    if (Number.isFinite(seconds) && seconds >= 0) {
      return Math.min(Math.max(seconds * 1000, backoff), MAX_RETRY_AFTER_MS)
    }
    const dateMs = Date.parse(trimmed)
    if (!Number.isNaN(dateMs)) {
      const wait = Math.max(0, dateMs - Date.now())
      return Math.min(Math.max(wait, backoff), MAX_RETRY_AFTER_MS)
    }
    return backoff
  }

  /** True when the error outcome is indeterminate and the caller should confirm remote state. */
  function isNeedsStateConfirmation(error: GatewayApiError): boolean {
    if (!KNOWN_ERROR_CODES.has(error.code)) return true
    // Network errors mean the request may or may not have reached the Gateway.
    if (error.code === 'NETWORK_ERROR') return true
    // Retryable statuses exhausted — remote state is unknown after transient failures.
    if (retryableStatuses.has(error.status)) return true
    return false
  }

  async function invoke<T>(path: string, options: {
    method?: 'GET' | 'POST'
    body?: unknown
    protected?: boolean
  } = {}): Promise<T> {
    const method = options.method || 'GET'
    const isGet = method === 'GET'
    const maxAttempts = isGet ? 1 + maxRetries : 1

    let attempt = 0
    let lastError: GatewayApiError | null = null

    while (attempt < maxAttempts) {
      attempt++
      const headers = new Headers({ Accept: 'application/json' })
      if (options.body !== undefined) headers.set('Content-Type', 'application/json')

      if (options.protected) {
        const token = String(await optionsGetToken() || '').trim()
        if (!token) throw new GatewayApiError('UNAUTHORIZED', 401, 'User session is required')
        headers.set('Authorization', /^Bearer /i.test(token) ? token : `Bearer ${token}`)
      }

      let response: Response
      try {
        response = await fetchImpl(`${baseURL}${path}`, {
          method,
          headers,
          body: options.body === undefined ? undefined : JSON.stringify(options.body),
          redirect: 'error',
          credentials: 'omit',
          signal: AbortSignal.timeout(timeout),
        })
      } catch (err) {
        // Write requests never auto-retry — surface as state-confirmation-required.
        lastError = new GatewayApiError('NETWORK_ERROR', 0, 'Gateway is unavailable', undefined, isGet, true)
        if (isGet && canRetryGet(lastError, attempt - 1)) {
          await sleep(baseRetryDelay * 2 ** (attempt - 1))
          continue
        }
        throw lastError
      }
      let envelope: unknown
      try { envelope = await response.json() } catch {
        throw new GatewayApiError('INVALID_RESPONSE', response.status, 'Invalid Gateway response')
      }
      if (!envelope || typeof envelope !== 'object') {
        throw new GatewayApiError('INVALID_RESPONSE', response.status, 'Invalid Gateway response')
      }
      const result = envelope as Partial<GatewayEnvelope<T>>
      if (result.meta?.version !== '1') {
        throw new GatewayApiError('VERSION_MISMATCH', response.status, 'Unsupported Gateway contract version')
      }
      if (result.ok === false) {
        const error = (result as GatewayFailure).error
        const code = error?.code || 'GATEWAY_ERROR'
        const message = error?.message || 'Gateway request failed'
        const requestId = result.meta?.requestId
        const canRetry = isGet && canRetryGet(
          new GatewayApiError(code, response.status, message, requestId),
          attempt - 1,
        )
        const retryable = canRetry
        const needsConfirmation = !KNOWN_ERROR_CODES.has(code) || retryableStatuses.has(response.status)
        lastError = new GatewayApiError(code, response.status, message, requestId, retryable, needsConfirmation)
        if (canRetry) {
          await sleep(retryDelay(attempt, response.headers.get('Retry-After')))
          continue
        }
        throw lastError
      }
      if (!response.ok || result.ok !== true || !('data' in result)) {
        throw new GatewayApiError('INVALID_RESPONSE', response.status, 'Unexpected Gateway response', result.meta?.requestId)
      }
      return result.data as T
    }

    // Unreachable in practice (maxAttempts >= 1 and every path returns or throws),
    // but keeps the loop invariant explicit for the type checker.
    throw lastError ?? new GatewayApiError('GATEWAY_ERROR', 0, 'Gateway request failed')
  }

  async function secureInvoke<T>(operation: 'register' | 'email-code', payload: unknown): Promise<T> {
    const discovery = await invoke<import('./crypto.js').KeyDiscovery>('/crypto/key')
    const { encryptForOperation } = await import('./crypto.js')
    const sealed = await encryptForOperation(discovery, payload, operation)
    return invoke<T>(`/secure/auth/${operation}`, { method: 'POST', body: sealed })
  }

  async function optionsGetToken() {
    return options.getToken ? options.getToken() : undefined
  }

  return {
    bootstrap: () => invoke<BootstrapData>('/bootstrap'),
    theme: {
      config: () => invoke<ThemeData>('/theme/config'),
    },
    plans: {
      list: () => invoke<Plan[]>('/plans'),
    },
    auth: {
      /** Account writes are encrypted-only and require opt-in Redis+HPKE Gateway. */
      register: (payload: RegistrationPayload) => secureInvoke<UserAuth>('register', payload),
      sendEmailCode: (payload: EmailCodePayload) => secureInvoke<{ sent: boolean }>('email-code', payload),
      login: async (payload: LoginPayload) => {
        if (!options.encryptedLogin) return invoke<UserAuth>('/auth/login', { method: 'POST', body: payload })
        const discovery = await invoke<import('./crypto.js').KeyDiscovery>('/crypto/key')
        const { encryptLoginPayload } = await import('./crypto.js')
        const sealed = await encryptLoginPayload(discovery, payload)
        return invoke<UserAuth>('/secure/auth/login', { method: 'POST', body: sealed })
      },
    },
    user: {
      profile: () => invoke<UserProfile>('/user/profile', { protected: true }),
      subscription: () => invoke<SubscriptionSummary>('/user/subscription/summary', { protected: true }),
    },
    dashboard: { stats: () => invoke<DashboardStats>('/dashboard/stats', { protected: true }) },
    orders: {
      status: (tradeNo: string) => invoke<OrderStatus>(`/orders/${encodeURIComponent(tradeNo)}/status`, { protected: true }),
      list: (query: { status?: 0 | 1 | 2 | 3 } = {}) => invoke<Order[]>(
        query.status === undefined ? '/orders' : `/orders?status=${query.status}`,
        { protected: true },
      ),
      detail: (tradeNo: string) => invoke<Order>(`/orders/${encodeURIComponent(tradeNo)}`, { protected: true }),
    },
    payments: { list: () => invoke<PaymentMethod[]>('/payments', { protected: true }) },
    notices: {
      list: (query: { current?: number; pageSize?: number } = {}) => {
        const params = new URLSearchParams()
        if (query.current !== undefined) params.set('current', String(query.current))
        if (query.pageSize !== undefined) params.set('pageSize', String(query.pageSize))
        return invoke<NoticePage>('/notices' + (params.size ? '?' + params.toString() : ''), { protected: true })
      },
    },
  }
}

export type TXBoardClient = ReturnType<typeof createTXBoardClient>

// Luma theme SDK methods (GW-217)
export type { LumaThemeConfig, LumaFeatureFlag } from './luma.js'
export {
  extractLumaTheme,
  discoverLumaTheme,
  loadLumaTheme,
  createLumaFeatureFlag,
  supportsLumaFeature,
} from './luma.js'
