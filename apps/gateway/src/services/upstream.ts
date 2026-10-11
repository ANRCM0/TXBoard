import type { GatewayConfig } from '../config/env.js'

export type GatewayFailureCode =
  | 'UPSTREAM_UNAVAILABLE'
  | 'UPSTREAM_ERROR'
  | 'PAYLOAD_TOO_LARGE'
  | 'VALIDATION_ERROR'
  | 'RATE_LIMITED'
  /** GW-203: upstream authentication is possible but a CAPTCHA must be satisfied. */
  | 'CAPTCHA_REQUIRED'
  /** GW-304: the same order request is already being processed; retry later. */
  | 'IDEMPOTENCY_IN_PROGRESS'
  /** GW-304: the idempotency key is bound to different request parameters. */
  | 'IDEMPOTENCY_CONFLICT'
  /** GW-304: the upstream refused the order for a business reason (e.g. plan). */
  | 'ORDER_REJECTED'

export class GatewayFailure extends Error {
  constructor(
    public readonly code: GatewayFailureCode,
    public readonly status: number,
    message: string,
  ) {
    super(message)
  }
}

export type UpstreamFetcher = typeof fetch

const allowlistedPaths = {
  guestConfig: '/api/v1/guest/comm/config',
  guestPlans: '/api/v1/guest/plan/fetch',
  guestPaymentMethods: '/api/v1/guest/paymentMethods',
  login: '/api/v1/passport/auth/login',
  userProfile: '/api/v1/user/info',
  userOrders: '/api/v1/user/order/fetch',
  userOrderDetail: '/api/v1/user/order/detail',
  userSubscription: '/api/v1/user/getSubscribe',
  userPaymentMethods: '/api/v1/user/order/getPaymentMethod',
  userNotices: '/api/v1/user/notice/fetch',
  userStats: '/api/v1/user/getStat',
  userOrderStatus: '/api/v1/user/order/check',
  register: '/api/v1/passport/auth/register',
  sendEmailCode: '/api/v1/passport/comm/sendEmailVerify',
  // GW-304: order creation. The ONLY mutating operation the order-write
  // adaptation layer may reach, and only when GATEWAY_ORDER_WRITE_ENABLED is
  // set. Laravel owns the idempotency decision; the Gateway only forwards the
  // hint header it derives itself (see routes/orders-write.ts).
  userOrderCreate: '/api/v1/user/order/save',
} as const

export type UpstreamOperation = keyof typeof allowlistedPaths

/**
 * GW-304: the closed set of request headers the Gateway may forward upstream
 * on the order-write path. The client's own `Idempotency-Key` header is
 * forwarded verbatim (the SDK sets it; the Gateway never generates one from
 * user data); `X-Idempotency-Key-Hint` is the Gateway-derived hint from
 * ADR §7.2. Nothing else — no Origin, no X-Forwarded-*, no credential — is
 * ever added by this mechanism.
 */
export const ORDER_WRITE_FORWARDED_HEADERS: ReadonlySet<string> = new Set([
  'idempotency-key',
  'x-idempotency-key-hint',
])

/** Read UTF-8 JSON with a strict byte cap, including chunked bodies. */
export async function boundedJson(
  stream: ReadableStream<Uint8Array> | null,
  maxBytes: number,
): Promise<unknown> {
  if (!stream) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Empty upstream response')
  const reader = stream.getReader()
  const chunks: Uint8Array[] = []
  let size = 0
  for (;;) {
    const { done, value } = await reader.read()
    if (done) break
    size += value.byteLength
    if (size > maxBytes) {
      await reader.cancel()
      throw new GatewayFailure('PAYLOAD_TOO_LARGE', 413, 'JSON payload exceeds configured limit')
    }
    chunks.push(value)
  }
  const bytes = new Uint8Array(size)
  let offset = 0
  for (const chunk of chunks) {
    bytes.set(chunk, offset)
    offset += chunk.byteLength
  }
  try {
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes))
  } catch {
    throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid upstream JSON')
  }
}

export function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {}
}

function publicErrorMessage(status: number): string {
  // Never echo upstream messages: a plugin/exception may contain PII or secrets.
  switch (status) {
    case 401: return 'Authentication failed'
    case 403: return 'Access denied'
    case 404: return 'Resource not found'
    case 422: return 'Invalid request data'
    case 429: return 'Too many requests'
    default: return status >= 500 ? 'Upstream request failed' : 'Upstream request rejected'
  }
}

/**
 * GW-304: map an upstream order-write rejection to the Gateway's frozen error
 * vocabulary. The verdict codes are Laravel's (docs/adr-order-idempotency.md
 * §7.3); the Gateway never invents them and never degrades an idempotency 409
 * to a generic 502 — the client must be able to tell "retry later" from
 * "conflict" from "the request was refused".
 *
 * Returns null when the failure has no order-write-specific meaning, so the
 * caller falls back to the generic mapping.
 */
function mappedOrderWriteFailure(
  record: Record<string, unknown>,
  status: number,
): GatewayFailure | null {
  switch (record.code) {
    case 'IDEMPOTENCY_IN_PROGRESS':
      return new GatewayFailure('IDEMPOTENCY_IN_PROGRESS', 409,
        'An identical order is already being processed')
    case 'IDEMPOTENCY_CONFLICT':
      return new GatewayFailure('IDEMPOTENCY_CONFLICT', 409,
        'Idempotency key is bound to different order parameters')
    case 'INSUFFICIENT_BALANCE':
    case 'PLAN_NOT_FOUND':
    case 'PLAN_NOT_AVAILABLE':
    case 'PERIOD_NOT_ALLOWED':
    case 'COUPON_NOT_FOUND':
    case 'COUPON_EXPIRED':
      // Business rejection: the order was understood and refused. Laravel
      // owns every rule behind these; the Gateway only classifies.
      return new GatewayFailure('ORDER_REJECTED', status,
        publicErrorMessage(status >= 400 ? status : 422))
    default:
      return null
  }
}

export async function upstreamRequest(
  config: GatewayConfig,
  fetcher: UpstreamFetcher,
  operation: UpstreamOperation,
  options: {
    auth?: string
    body?: unknown
    status?: number
    tradeNo?: string
    current?: number
    pageSize?: number
    /** GW-304: extra headers forwarded verbatim (e.g. the idempotency hint). */
    headers?: Record<string, string>
  } = {},
): Promise<unknown> {
  // No untrusted path/host input can escape this static allowlist.
  const target = new URL(allowlistedPaths[operation], config.upstream)
  if (operation === 'userOrders' && options.status !== undefined) {
    target.searchParams.set('status', String(options.status))
  }
  if (operation === 'userOrderStatus' && options.tradeNo) {
    target.searchParams.set('trade_no', options.tradeNo)
  }
  if (operation === 'userOrderDetail' && options.tradeNo) {
    target.searchParams.set('trade_no', options.tradeNo)
  }
  if (operation === 'userNotices') {
    target.searchParams.set('current', String(options.current ?? 1))
    target.searchParams.set('pageSize', String(options.pageSize ?? 5))
  }
  const headers = new Headers({ Accept: 'application/json' })
  if (options.auth) headers.set('Authorization', options.auth)
  const method = operation === 'login' || operation === 'register' || operation === 'sendEmailCode'
    || operation === 'userOrderCreate'
    ? 'POST' : 'GET'
  if (method === 'POST') headers.set('Content-Type', 'application/json')
  // GW-304: only the explicitly allowlisted forwarding headers are added, and
  // only for the order-write operation. A caller can never widen this: the
  // record is built by the route module, never passed through from a client.
  for (const [name, value] of Object.entries(options.headers ?? {})) {
    if (ORDER_WRITE_FORWARDED_HEADERS.has(name.toLowerCase())) {
      headers.set(name, value)
    }
  }
  // GW-204: pin the Host we send and refuse any response that was not served
  // from the configured origin. redirect:'manual' stops follow-through; the
  // response.url check catches a proxy that rewrote the effective URL, and the
  // 3xx status check covers Location-only redirects.
  const finalUrl = target.toString()
  try {
    const response = await fetcher(finalUrl, {
      method,
      headers,
      body: method === 'POST' ? JSON.stringify(options.body) : undefined,
      signal: AbortSignal.timeout(config.timeoutMs),
      redirect: 'manual',
      cache: 'no-store',
    })
    if (response.status >= 300 && response.status < 400) {
      throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream redirect')
    }
    // A redirect that was followed despite redirect:'manual' (custom fetcher,
    // undici retry, DNS-rebinding proxy) would land on a different origin.
    if (response.url) {
      const landed = new URL(response.url)
      if (landed.origin !== target.origin) {
        throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Upstream response origin mismatch')
      }
    }
    const contentLength = response.headers.get('content-length')
    if (contentLength && Number(contentLength) > config.maxResponseBytes) {
      await response.body?.cancel()
      throw new GatewayFailure('PAYLOAD_TOO_LARGE', 502, 'Upstream response too large')
    }
    if (!response.headers.get('content-type')?.toLowerCase().includes('application/json')) {
      await response.body?.cancel()
      throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream content type')
    }
    let payload: unknown
    try {
      payload = await boundedJson(response.body, config.maxResponseBytes)
    } catch (error) {
      if (error instanceof GatewayFailure && error.code === 'PAYLOAD_TOO_LARGE') {
        throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Upstream response too large')
      }
      throw error
    }
    const record = asRecord(payload)
    // Never swallow a TXBoard application-level error with HTTP 200.
    if (!response.ok || record.status === 'fail') {
      const status = response.status >= 400 ? response.status : 400
      // GW-304: the order-write verdict codes are Laravel's; map them to the
      // frozen Gateway vocabulary instead of collapsing them into UPSTREAM_ERROR.
      const orderWriteFailure = operation === 'userOrderCreate'
        ? mappedOrderWriteFailure(record, status)
        : null
      if (orderWriteFailure) throw orderWriteFailure
      throw new GatewayFailure('UPSTREAM_ERROR', status, publicErrorMessage(status))
    }
    // An unknown status is not a TXBoard business failure. Treat its
    // response as a broken contract rather than manufacturing HTTP 400.
    if (Object.hasOwn(record, 'status') && record.status !== 'success') {
      throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream response shape')
    }
    if (record.status === 'success') {
      if (!Object.hasOwn(record, 'data')) {
        throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid upstream success response')
      }
      return record.data
    }
    // Only the known legacy paginator format may omit a status envelope.
    // Unknown successful bodies must not masquerade as valid domain data.
    if (Array.isArray(record.data) && typeof record.total === 'number'
      && Number.isFinite(record.total) && record.total >= 0) {
      return operation === 'userNotices'
        ? { data: record.data, total: record.total }
        : record.data
    }
    throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream response shape')
  } catch (err) {
    if (err instanceof GatewayFailure) return Promise.reject(err)
    if (err instanceof Error && (err.name === 'TimeoutError' || err.name === 'AbortError')) {
      throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 504, 'Upstream request timed out')
    }
    throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Upstream connection failed')
  }
}
