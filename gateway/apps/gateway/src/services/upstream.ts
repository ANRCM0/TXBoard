import type { GatewayConfig } from '../config/env.js'
import { adaptNativeData, nativeErrorEnvelope, nativeSuccessEnvelope } from '../adapters/txapi-native.js'
import { GatewayFailure } from './failures.js'
import { mappedOrderWriteFailure } from './order-write-failure.js'
import { NativeCoreClient, type NativeInternalOperation } from './native-internal.js'
export { GatewayFailure, type GatewayFailureCode } from './failures.js'

export type UpstreamFetcher = typeof fetch

const allowlistedPaths = {
  guestConfig: '/txapi/public/site-config',
  guestPlans: '/txapi/plans',
  // No anonymous counterpart exists. Checked before URL creation or fetch.
  guestPaymentMethods: null,
  login: '/txapi/auth/login',
  userProfile: '/txapi/me',
  userOrders: '/txapi/orders',
  userOrderDetail: '/txapi/orders/',
  userSubscription: '/txapi/me/subscription',
  userPaymentMethods: '/txapi/billing/payment-methods',
  userNotices: '/txapi/notices',
  userStats: '/txapi/me/dashboard-stats',
  userOrderStatus: '/txapi/orders/',
  register: '/txapi/auth/register',
  sendEmailCode: '/txapi/auth/email-code',
  // GW-304: order creation. The ONLY mutating operation the order-write
  // adaptation layer may reach, and only when GATEWAY_ORDER_WRITE_ENABLED is
  // set. Laravel owns the idempotency decision; the Gateway only forwards the
  // hint header it derives itself (see routes/orders-write.ts).
  userOrderCreate: '/txapi/orders',
} as const

export type UpstreamOperation = keyof typeof allowlistedPaths

/**
 * GW-304: the closed set of request headers the Gateway may forward upstream
 * on the order-write path. The client's own `Idempotency-Key` header is
 * forwarded verbatim (the SDK sets it; the Gateway never generates one from
 * user data); `X-Idempotency-Key-Hint` is the Gateway-derived hint from
 * contracts/gateway-v1.md. Nothing else — no Origin, no X-Forwarded-*, no credential — is
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
 * vocabulary. Core owns the verdict codes (contracts/gateway-v1.md);
 * the Gateway never invents them and never degrades an idempotency 409
 * to a generic 502 — the client must be able to tell "retry later" from
 * "conflict" from "the request was refused".
 *
 * Returns null when the failure has no order-write-specific meaning, so the
 * caller falls back to the generic mapping.
 */
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
    /** Separate paged DTO; the existing list response remains an array. */
    paginated?: boolean
    signal?: AbortSignal
    /** GW-304: extra headers forwarded verbatim (e.g. the idempotency hint). */
    headers?: Record<string, string>
  } = {},
): Promise<unknown> {
  // Only current user DTOs with identical semantics opt into this channel.
  // The independent Admin client is never selected by a browser operation.
  const internalOperations: Partial<Record<UpstreamOperation, NativeInternalOperation>> = {
    login: 'identity.session.create', guestConfig: 'settings.public.get',
  }
  const internalOperation = internalOperations[operation]
  if (config.nativeUserService && internalOperation) {
    const result = await new NativeCoreClient(config.upstream, config.nativeUserService,
      config.timeoutMs, config.maxResponseBytes).call(internalOperation, {
        input: asRecord(options.body), signal: options.signal,
      })
    return adaptNativeData(operation, result.data, result.meta, options)
  }
  // No untrusted path/host input can escape this static allowlist.
  const path = allowlistedPaths[operation]
  if (path === null) throw new GatewayFailure('UPSTREAM_ERROR', 404, 'Public payment catalog unavailable')
  const target = new URL(path, config.upstream)
  if (operation === 'userOrders') {
    if (options.status !== undefined) target.searchParams.set('status', String(options.status))
    target.searchParams.set('page', String(options.current ?? 1))
    target.searchParams.set('per_page', String(options.pageSize ?? 100))
  }
  if (operation === 'userOrderStatus' || operation === 'userOrderDetail') {
    if (!options.tradeNo || !/^[a-zA-Z0-9_-]{1,128}$/.test(options.tradeNo)) {
      throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid order identifier')
    }
    target.pathname += encodeURIComponent(options.tradeNo) + (operation === 'userOrderDetail' ? '/detail' : '')
  }
  if (operation === 'userNotices') {
    target.searchParams.set('page', String(options.current ?? 1))
    target.searchParams.set('per_page', String(options.pageSize ?? 5))
  }
  const headers = new Headers({ Accept: 'application/json' })
  if (operation.startsWith('user') && options.auth) headers.set('Authorization', options.auth)
  const method = operation === 'login' || operation === 'register' || operation === 'sendEmailCode'
    || operation === 'userOrderCreate'
    ? 'POST' : 'GET'
  if (method === 'POST') headers.set('Content-Type', 'application/json')
  // GW-304: only the explicitly allowlisted forwarding headers are added, and
  // only for the order-write operation. A caller can never widen this: the
  // record is built by the route module, never passed through from a client.
  for (const [name, value] of Object.entries(options.headers ?? {})) {
    if (operation === 'userOrderCreate' && ORDER_WRITE_FORWARDED_HEADERS.has(name.toLowerCase())) {
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
    // Only current Native envelopes are accepted. Error messages and fields
    // are never exposed, even when Core returns an error with HTTP 200.
    const failure = nativeErrorEnvelope.safeParse(payload)
    if (!response.ok || Object.hasOwn(record, 'error')) {
      if (!failure.success) {
        throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream error shape')
      }
      const status = response.status >= 400 ? response.status : 502
      // GW-304: the order-write verdict codes are Laravel's; map them to the
      // frozen Gateway vocabulary instead of collapsing them into UPSTREAM_ERROR.
      const orderWriteFailure = operation === 'userOrderCreate' && [409, 422].includes(response.status)
        ? mappedOrderWriteFailure(asRecord(record.error).code)
        : null
      if (orderWriteFailure) throw orderWriteFailure
      throw new GatewayFailure('UPSTREAM_ERROR', status, publicErrorMessage(status))
    }
    const success = nativeSuccessEnvelope.safeParse(payload)
    if (!success.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream response shape')
    return adaptNativeData(operation, success.data.data, success.data.meta, options)
  } catch (err) {
    if (err instanceof GatewayFailure) return Promise.reject(err)
    if (err instanceof Error && (err.name === 'TimeoutError' || err.name === 'AbortError')) {
      throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 504, 'Upstream request timed out')
    }
    throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Upstream connection failed')
  }
}
