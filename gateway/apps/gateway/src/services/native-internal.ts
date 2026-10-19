import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { request as httpsRequest } from 'node:https'
import { GatewayFailure } from './failures.js'
import { parseStrictJson } from '../security/strict-json.js'
import { coreOperations, type CoreOperation } from '../contracts/core.generated.js'

export const NATIVE_INTERNAL_OPERATIONS = {
  'support.tickets.list': ['user'],
  'support.tickets.get': ['user'],
  'support.tickets.messages.list': ['user'],
  'support.tickets.create': ['user'],
  'support.tickets.reply': ['user'],
  'support.tickets.close': ['user'],
  'support.admin-tickets.list': ['admin'],
  'support.admin-tickets.get': ['admin'],
  'support.admin-tickets.messages.list': ['admin'],
  'support.admin-tickets.reply': ['admin'],
  'support.admin-tickets.close': ['admin'],
  'content.user-notices.list': ['user'],
  'content.user-notices.get': ['user'],
  'content.user-knowledge.list': ['user'],
  'content.user-knowledge.get': ['user'],
  'content.user-knowledge.categories.list': ['user'],
  'content.audit.list': ['admin'],
  'support.admin-tickets.claim': ['admin'],
  'support.admin-tickets.unassign': ['admin'],
  'support.admin-tickets.audit.list': ['admin'],
  'support.tickets.attachments.begin': ['user'],
  'support.tickets.attachments.append': ['user'],
  'support.tickets.attachments.finish': ['user'],
  'support.tickets.attachments.cancel': ['user'],
  'support.tickets.attachments.get': ['user'],
  'support.tickets.attachments.download': ['user'],
  'support.admin-tickets.attachments.begin': ['admin'],
  'support.admin-tickets.attachments.append': ['admin'],
  'support.admin-tickets.attachments.finish': ['admin'],
  'support.admin-tickets.attachments.cancel': ['admin'],
  'support.admin-tickets.attachments.get': ['admin'],
  'support.admin-tickets.attachments.download': ['admin'],
  'system.capabilities.get': ['user', 'admin'],
  'identity.session.create': ['user'],
  'identity.admin-session.create': ['admin'],
  'identity.account.get': ['user'],
  'identity.admin.get': ['admin'],
  'identity.accounts.list': ['admin'],
  'identity.accounts.get': ['admin'],
  'commerce.admin-orders.list': ['admin'],
  'commerce.admin-orders.get': ['admin'],
  'commerce.admin-orders.events.list': ['admin'],
  'identity.sessions.list': ['user', 'admin'],
  'identity.sessions.revoke': ['user', 'admin'],
  'identity.session.end': ['user', 'admin'],
  'catalog.offers.list': ['user'],
  'subscription.mine.get': ['user'],
  'subscription.delivery-options.get': ['user'],
  'subscription.deliveries.authorize': ['user'],
  'subscription.links.list': ['user'],
  'subscription.links.issue': ['user'],
  'subscription.links.revoke': ['user'],
  'commerce.orders.list': ['user'],
  'commerce.orders.get': ['user'],
  'commerce.orders.events.list': ['user'],
  'commerce.orders.create': ['user'],
  'commerce.orders.checkout': ['user'],
  'commerce.orders.cancel': ['user'],
  'billing.order-payment-attempts.list': ['user'],
  'commerce.admin-orders.close': ['admin'],
  'commerce.admin-orders.retry': ['admin'],
  'commerce.admin-orders.audit.list': ['admin'],
  'billing.wallet.get': ['user'],
  'billing.payment-attempts.get': ['user'],
  'billing.payment-attempts.query': ['user'],
  'billing.payment-methods.list': ['user'],
  'billing.recharges.create': ['user'],
  'billing.recharges.list': ['user'],
  'billing.recharges.get': ['user'],
  'billing.recharges.checkout': ['user'],
  'usage.mine.list': ['user'],
  'settings.public.get': ['user', 'admin'],
  'network.nodes.list': ['admin'],
  'network.machines.list': ['admin'],
  'catalog.plans.publish': ['admin'],
  'catalog.offers.availability.update': ['admin'],
  'billing.refunds.create': ['admin'],
  'billing.reconciliation.get': ['admin'],
  'settings.site.get': ['admin'],
  'settings.site.update': ['admin'],
  'subscription.lifecycle.suspend': ['admin'],
  'subscription.lifecycle.resume': ['admin'],
  'subscription.lifecycle.revoke': ['admin'],
  'subscription.lifecycle.expire': ['admin'],
  'subscription.lifecycle.reset': ['admin'],
  'identity.accounts.create': ['admin'],
  'identity.accounts.status.update': ['admin'],
  'network.groups.list': ['admin'],
  'network.groups.save': ['admin'],
  'network.groups.delete': ['admin'],
  'network.routes.list': ['admin'],
  'network.routes.save': ['admin'],
  'network.routes.delete': ['admin'],
  'network.routes.sort': ['admin'],
  'network.nodes.patch': ['admin'],
  'network.nodes.delete': ['admin'],
  'network.nodes.traffic-reset': ['admin'],
  'network.nodes.batch-update': ['admin'],
  'network.nodes.batch-delete': ['admin'],
  'network.nodes.batch-traffic-reset': ['admin'],
  'network.nodes.sort': ['admin'],
  'network.machines.update': ['admin'],
  'network.machines.delete': ['admin'],
  'content.notices.list': ['admin'],
  'content.notices.get': ['admin'],
  'content.notices.create': ['admin'],
  'content.notices.update': ['admin'],
  'content.notices.visibility.toggle': ['admin'],
  'content.notices.delete': ['admin'],
  'content.notices.sort': ['admin'],
  'content.knowledge.list': ['admin'],
  'content.knowledge.get': ['admin'],
  'content.knowledge.create': ['admin'],
  'content.knowledge.update': ['admin'],
  'content.knowledge.visibility.toggle': ['admin'],
  'content.knowledge.delete': ['admin'],
  'content.knowledge.sort': ['admin'],
  'audit.logs.list': ['admin'],
  'platform.queue.failures.list': ['admin'],
  'catalog.admin-offers.list': ['admin'],
  'platform.modules.list': ['admin'],
  'platform.modules.get': ['admin'],
  'platform.modules.operations.list': ['admin'],
  'network.access-audit.rules.list': ['admin'],
  'network.access-audit.rules.save': ['admin'],
  'network.access-audit.rules.delete': ['admin'],
  'network.access-audit.events.list': ['admin'],
  'network.nodes.copy': ['admin'],
  'network.machines.nodes.list': ['admin'],
  'network.routes.simulate': ['admin'],
  'content.knowledge.categories.list': ['admin'],
  'platform.queue.snapshot.get': ['admin'],
  'platform.queue.failures.get': ['admin'],
  'notifications.mail-templates.list': ['admin'],
  'notifications.mail-templates.get': ['admin'],
  'notifications.mail-templates.save': ['admin'],
  'notifications.mail-templates.reset': ['admin'],
  'notifications.mail-templates.test': ['admin'],
} as const

export type NativeInternalOperation = keyof typeof NATIVE_INTERNAL_OPERATIONS
export type NativeServiceConfig = Readonly<{
  domain: 'user' | 'admin'
  serviceId: string
  credential: string
  epoch: string
  caFile: string
  certificateFile: string
  keyFile: string
}>
export type NativeCoreResponse = {
  data: unknown
  request_id: string
  meta?: Record<string, unknown>
}
export type NativeCall = {
  input?: Record<string, unknown>
  /** Original actor bearer, independently revalidated by Core, never a service credential. */
  authorization?: string
  adminPath?: string
  idempotencyKey?: string
  requestId?: string
  signal?: AbortSignal
}
export type NativeTransport = (url: URL, options: {
  headers: Record<string, string>; body: string; timeoutMs: number
  maxResponseBytes: number; service: NativeServiceConfig; signal?: AbortSignal
}) => Promise<{ status: number; contentType: string; body: string }>

/** Actual mTLS transport: private trust root, normal hostname verification,
 * no redirects, no global TLS overrides, one bounded response, no retries. */
export const nativeMtlsTransport: NativeTransport = async (url, options) => {
  const deadline = new AbortController()
  const timer = setTimeout(() => deadline.abort(), options.timeoutMs)
  const signal = options.signal ? AbortSignal.any([options.signal, deadline.signal]) : deadline.signal
  try {
    const [ca, cert, key] = await Promise.all([
      readFile(options.service.caFile, { signal }), readFile(options.service.certificateFile, { signal }),
      readFile(options.service.keyFile, { signal }),
    ])
    signal.throwIfAborted()
    return await new Promise<Awaited<ReturnType<NativeTransport>>>((resolve, reject) => {
      const req = httpsRequest(url, {
        method: 'POST', headers: options.headers, ca, cert, key,
        rejectUnauthorized: true, minVersion: 'TLSv1.2', signal, agent: false,
      }, response => {
        const chunks: Buffer[] = []
        let size = 0
        response.on('data', (chunk: Buffer) => {
          size += chunk.length
          if (size > options.maxResponseBytes) {
            response.destroy()
            reject(new GatewayFailure('UPSTREAM_ERROR', 502, 'Core response limit exceeded'))
            return
          }
          chunks.push(chunk)
        })
        response.on('error', () => reject(new GatewayFailure('UPSTREAM_UNAVAILABLE', 503,
          'Core result unknown; retain the original idempotency key')))
        response.on('end', () => {
          try {
            resolve({
              status: response.statusCode ?? 502,
              contentType: String(response.headers['content-type'] ?? ''),
              body: new TextDecoder('utf-8', { fatal: true }).decode(Buffer.concat(chunks)),
            })
          } catch {
            reject(new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core response'))
          }
        })
      })
      req.on('error', () => reject(new GatewayFailure('UPSTREAM_UNAVAILABLE', 503,
        'Core result unknown; retain the original idempotency key')))
      req.end(options.body)
    })
  } catch (error) {
    if (error instanceof GatewayFailure) throw error
    throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503,
      'Core result unknown; retain the original idempotency key')
  } finally {
    // Includes TLS-file reads, connection establishment and the entire body.
    clearTimeout(timer)
  }
}

const anonymous = new Set<NativeInternalOperation>([
  'system.capabilities.get', 'settings.public.get', 'catalog.offers.list',
  'identity.session.create', 'identity.admin-session.create',
])
const writes = new Set<NativeInternalOperation>([
  'identity.sessions.revoke', 'commerce.orders.create', 'commerce.orders.checkout', 'commerce.orders.cancel',
  'billing.recharges.create', 'billing.recharges.checkout',
  'billing.payment-attempts.query',
  'subscription.links.issue', 'subscription.links.revoke', 'catalog.plans.publish',
  'catalog.offers.availability.update', 'billing.refunds.create', 'settings.site.update',
  'subscription.lifecycle.suspend', 'subscription.lifecycle.resume', 'subscription.lifecycle.revoke',
  'subscription.lifecycle.expire', 'subscription.lifecycle.reset',
])
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

/** Production server-side consumer. The browser SDK never receives this object. */
export class NativeCoreClient {
  constructor(
    private readonly origin: URL,
    private readonly service: NativeServiceConfig,
    private readonly timeoutMs = 8000,
    private readonly maxResponseBytes = 1048576,
    private readonly transport: NativeTransport = nativeMtlsTransport,
  ) {
    if (origin.protocol !== 'https:' || origin.pathname !== '/' || origin.username || origin.password
      || origin.search || origin.hash) throw new Error('Native Core requires a clean HTTPS origin')
    if (!['user', 'admin'].includes(service.domain)
      || !/^[a-z][a-z0-9_-]{0,63}$/.test(service.serviceId)
      || !/^[a-f0-9]{64}$/.test(service.credential) || !/^[1-9][0-9]{0,18}$/.test(service.epoch)
      || !service.caFile || !service.certificateFile || !service.keyFile
      || !Number.isSafeInteger(timeoutMs) || timeoutMs < 500 || timeoutMs > 30000
      || !Number.isSafeInteger(maxResponseBytes) || maxResponseBytes < 1 || maxResponseBytes > 8388608) {
      throw new Error('Invalid isolated Core service configuration')
    }
  }

  async call(operation: NativeInternalOperation, options: NativeCall = {}): Promise<NativeCoreResponse> {
    const domains: readonly string[] | undefined = Object.hasOwn(NATIVE_INTERNAL_OPERATIONS, operation)
      ? NATIVE_INTERNAL_OPERATIONS[operation] : undefined
    if (!domains?.includes(this.service.domain)) throw new GatewayFailure('FORBIDDEN', 403, 'Operation domain rejected')
    if (!anonymous.has(operation) && !/^Bearer \S{8,4096}$/.test(options.authorization ?? '')) {
      throw new GatewayFailure('UNAUTHORIZED', 401, 'Actor credential required')
    }
    if (this.service.domain === 'admin' && !anonymous.has(operation)
      && !/^[a-zA-Z0-9_-]{1,128}$/.test(options.adminPath ?? '')) {
      throw new GatewayFailure('FORBIDDEN', 403, 'Current administrator path required')
    }
    if ((writes.has(operation) || operation in coreOperations && coreOperations[operation as CoreOperation].idempotency !== 'read') && !uuid.test(options.idempotencyKey ?? '')) {
      throw new GatewayFailure('VALIDATION_ERROR', 400, 'Original UUID idempotency key required')
    }
    const requestId = options.requestId ?? randomUUID()
    if (!uuid.test(requestId)) throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid trace identifier')
    const headers: Record<string, string> = {
      'Accept': 'application/json', 'Content-Type': 'application/json',
      'X-Core-Service-Id': this.service.serviceId, 'X-Core-Service-Credential': this.service.credential,
      'X-Core-Service-Epoch': this.service.epoch,
    }
    if (!anonymous.has(operation)) headers.Authorization = options.authorization!
    if (this.service.domain === 'admin' && !anonymous.has(operation)) headers['X-Core-Admin-Path'] = options.adminPath!
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey
    // TLS verification headers are supplied ONLY by the trusted TLS proxy.
    const input = options.input ?? {}
    if (typeof input !== 'object' || Array.isArray(input)) {
      throw new GatewayFailure('VALIDATION_ERROR', 400, 'Core input object required')
    }
    const contract = Object.hasOwn(coreOperations, operation) ? coreOperations[operation as CoreOperation] : undefined
    if (contract && !contract.input.safeParse(input).success) {
      throw new GatewayFailure('VALIDATION_ERROR', 422, 'Core operation input rejected')
    }
    let body: string
    try {
      body = JSON.stringify({
        contract_version: '1', request_id: requestId,
        deadline_at: Math.floor(Date.now() / 1000) + Math.max(1, Math.ceil(this.timeoutMs / 1000)),
        input,
      })
    } catch {
      throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid Core request')
    }
    if (Buffer.byteLength(body) > 32768) throw new GatewayFailure('PAYLOAD_TOO_LARGE', 413, 'Core request too large')
    const url = new URL(`/txapi/internal/native/v1/${this.service.domain}/operations/${operation}`, this.origin)
    let response: Awaited<ReturnType<NativeTransport>>
    try {
      response = await this.transport(url, { headers, body, timeoutMs: this.timeoutMs,
        maxResponseBytes: this.maxResponseBytes, service: this.service, signal: options.signal })
    } catch (error) {
      if (error instanceof GatewayFailure) throw error
      throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Core result unknown; retain the original idempotency key')
    }
    if (response.contentType.split(';', 1)[0]?.trim().toLowerCase() !== 'application/json'
      || !Number.isInteger(response.status) || response.status < 200 || response.status > 599
      || (response.status >= 300 && response.status < 400)
      || Buffer.byteLength(response.body) > this.maxResponseBytes) {
      throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core response')
    }
    let payload: Record<string, unknown>
    try { payload = parseStrictJson(response.body) as Record<string, unknown> } catch {
      throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core response')
    }
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)
      || typeof payload.request_id !== 'string' || !uuid.test(payload.request_id)) {
      throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core envelope')
    }
    if (response.status >= 400 || Object.hasOwn(payload, 'error')) {
      // No upstream message, validation fields, body or credentials enter logs/errors.
      const error = payload.error
      const code = error !== null && typeof error === 'object' && !Array.isArray(error)
        ? (error as Record<string, unknown>).code : undefined
      if (response.status === 409 && (code === 'IDEMPOTENCY_CONFLICT' || code === 'IDEMPOTENCY_IN_PROGRESS')) {
        throw new GatewayFailure(code, 409, code === 'IDEMPOTENCY_CONFLICT'
          ? 'Original key has different input' : 'Original key requires result recovery')
      }
      if (response.status >= 500) {
        throw new GatewayFailure('UPSTREAM_UNAVAILABLE', response.status,
          'Core result unknown; retain the original idempotency key')
      }
      if (code === 'SERVICE_UNAUTHORIZED') throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Core service unavailable')
      if (code === 'ACTOR_UNAUTHORIZED') throw new GatewayFailure('UNAUTHORIZED', 401, 'User session is required')
      if (code === 'INVALID_CREDENTIALS') throw new GatewayFailure('UNAUTHORIZED', 401, 'Invalid credentials')
      if (code === 'CAPTCHA_INVALID') throw new GatewayFailure('CAPTCHA_REQUIRED', 428, 'Verification required')
      if (code === 'ADMIN_ACCESS_DENIED') throw new GatewayFailure('FORBIDDEN', 403, 'Administrator access required')
      if (code === 'FORBIDDEN') throw new GatewayFailure('FORBIDDEN', 403, 'Core operation not granted')
      if (response.status === 404) throw new GatewayFailure('NOT_FOUND', 404, 'Core resource unavailable')
      if (code === 'INVALID_REQUEST') throw new GatewayFailure('VALIDATION_ERROR', 422, 'Invalid Core operation input')
      if (code === 'RATE_LIMITED') throw new GatewayFailure('RATE_LIMITED', 429, 'Core operation rate limited')
      throw new GatewayFailure('UPSTREAM_ERROR', response.status >= 400 ? response.status : 502, 'Core operation rejected')
    }
    if (!Object.hasOwn(payload, 'data') || Object.keys(payload).some(k => !['data', 'meta', 'request_id'].includes(k))) {
      throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core envelope')
    }
    if (contract) {
      const checked = contract.output.safeParse(payload.data)
      if (!checked.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core operation output')
      payload.data = checked.data
      const meta = contract.meta.safeParse(payload.meta ?? {})
      if (!meta.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core operation metadata')
      if (Object.keys(meta.data).length !== 0 || payload.meta !== undefined) payload.meta = meta.data
    }
    return payload as NativeCoreResponse
  }
}

export function loadNativeServiceConfig(
  env: Record<string, string | undefined>, domain: 'user' | 'admin' = 'user',
): NativeServiceConfig | undefined {
  const prefix = domain === 'user' ? 'GATEWAY_CORE_USER_' : 'GATEWAY_CORE_ADMIN_'
  if (env[`${prefix}ENABLED`] !== 'true') return undefined
  return {
    domain, serviceId: env[`${prefix}SERVICE_ID`] ?? '', credential: env[`${prefix}CREDENTIAL`] ?? '',
    epoch: env[`${prefix}EPOCH`] ?? '', caFile: env[`${prefix}CA_FILE`] ?? '',
    certificateFile: env[`${prefix}CERT_FILE`] ?? '', keyFile: env[`${prefix}KEY_FILE`] ?? '',
  }
}
