import { z } from 'zod'
import { CoreClient, type CoreTransport } from './client.js'
import { coreOperations, CORE_SURFACE_OPERATION_LIMIT, type CoreOperation } from './contracts.generated.js'
import { CORE_USER_OPERATIONS } from './user.generated.js'

export type CoreUserClientOptions = Readonly<{
  baseURL?: string
  getToken?: () => string | null | undefined | Promise<string | null | undefined>
  fetchImpl?: typeof fetch
  timeoutMs?: number
  maxResponseBytes?: number
}>
const trace = z.string().min(1).max(128).regex(/^[A-Za-z0-9._:-]+$/)
const success = z.strictObject({ ok: z.literal(true), data: z.unknown(), meta: z.strictObject({
  version: z.literal('1'), requestId: trace, coreRequestId: z.uuid(), coreMeta: z.unknown(),
}) })
const failure = z.strictObject({ ok: z.literal(false), error: z.strictObject({ code: z.string().max(96), message: z.string() }),
  meta: z.strictObject({ version: z.literal('1'), requestId: trace }) })
const capabilities = z.strictObject({ contract: z.enum(['core-user/v1', 'core-admin/v1']), operations: z.array(z.strictObject({
  permission: z.string().nullable().optional(), id: z.string(), version: z.literal('1'), actor: z.string(), idempotency: z.string(),
})).max(CORE_SURFACE_OPERATION_LIMIT) })
const capabilityEnvelope = z.strictObject({ ok: z.literal(true), data: capabilities,
  meta: z.strictObject({ version: z.literal('1'), requestId: trace }) })
export type CoreUserCapabilities = z.output<typeof capabilities>
export type CoreUserClient = CoreClient & { capabilities(options?: { signal?: AbortSignal }): Promise<CoreUserCapabilities> }

/** No upstream message, request body, token or payment URL is included in errors. */
export class CoreUserApiError extends Error {
  constructor(readonly code: string, readonly status: number, readonly requestId?: string,
    readonly needsStateConfirmation = false) {
    super(code)
    this.name = 'CoreUserApiError'
  }
}

const codes = new Set(['UNAUTHORIZED', 'FORBIDDEN', 'NOT_FOUND', 'VALIDATION_ERROR', 'RATE_LIMITED',
  'UPSTREAM_UNAVAILABLE', 'UPSTREAM_ERROR', 'PAYLOAD_TOO_LARGE', 'METHOD_NOT_ALLOWED',
  'BUSINESS_REJECTED', 'IDEMPOTENCY_IN_PROGRESS', 'IDEMPOTENCY_CONFLICT', 'ORIGIN_DENIED', 'CAPTCHA_REQUIRED'])

export function createBrowserCoreClient(options: CoreUserClientOptions, surface: {
  domain: 'user' | 'admin'; contract: string; prefix: string; operations?: readonly CoreOperation[];
  getAdminPath?: () => string | null | undefined | Promise<string | null | undefined>
}): CoreUserClient {
  const base = (options.baseURL ?? surface.prefix).replace(/\/+$/, '')
  if (base.startsWith('/') && !base.startsWith('//')) {
    if (/[?#\\\s]/.test(base)) throw new Error('Invalid Core user base URL')
  } else {
    const url = new URL(base)
    if (url.username || url.password || url.search || url.hash
        || url.protocol !== 'https:' && !(url.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(url.hostname))) {
      throw new Error('Core user access requires a fixed HTTPS URL')
    }
  }
  const timeoutMs = options.timeoutMs ?? 10000
  const maximum = options.maxResponseBytes ?? 1048576
  if (!Number.isSafeInteger(timeoutMs) || timeoutMs < 500 || timeoutMs > 60000
      || !Number.isSafeInteger(maximum) || maximum < 1024 || maximum > 8388608) throw new Error('Invalid Core user client bounds')
  const send = options.fetchImpl ?? fetch

  async function read(response: Response, signal: AbortSignal): Promise<unknown> {
    if (response.headers.get('content-type')?.split(';', 1)[0]?.trim().toLowerCase() !== 'application/json'
        || !response.body) throw new Error('Invalid response')
    const reader = response.body.getReader()
    const abort = () => { void reader.cancel().catch(() => {}) }
    signal.addEventListener('abort', abort, { once: true })
    const chunks: Uint8Array[] = []
    let size = 0
    try {
      for (;;) {
        signal.throwIfAborted()
        const part = await reader.read()
        signal.throwIfAborted()
        if (part.done) break
        size += part.value.byteLength
        if (size > maximum) { await reader.cancel(); throw new Error('Response limit exceeded') }
        chunks.push(part.value)
      }
    } finally { signal.removeEventListener('abort', abort); reader.releaseLock() }
    const bytes = new Uint8Array(size)
    let offset = 0
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes)) as unknown
  }

  async function request(path: string, operation?: CoreOperation, input?: unknown,
    callOptions: Parameters<CoreTransport>[2] = {}): Promise<unknown> {
    const headers = new Headers({ Accept: 'application/json' })
    if (operation && (!coreOperations[operation].domains.includes(surface.domain as never)
        || surface.operations && !surface.operations.includes(operation))) throw new CoreUserApiError('FORBIDDEN', 403)
    const spec = operation ? coreOperations[operation] : undefined
    const mutation = spec !== undefined && spec.idempotency !== 'read'
    if (spec && spec.actor !== 'anonymous') {
      const raw = String(await options.getToken?.() ?? '').trim()
      const bearer = raw.startsWith('Bearer ') ? raw : `Bearer ${raw}`
      if (!/^Bearer \S{8,4096}$/.test(bearer)) throw new CoreUserApiError('UNAUTHORIZED', 401)
      headers.set('Authorization', bearer)
      if (surface.domain === 'admin') {
        const adminPath = await surface.getAdminPath?.()
        if (!/^[a-zA-Z0-9_-]{1,128}$/.test(adminPath ?? '')) throw new CoreUserApiError('ADMIN_CONTEXT_REQUIRED', 401)
        headers.set('X-Core-Admin-Path', adminPath!)
      }
    }
    if (spec) headers.set('Content-Type', 'application/json')
    if (mutation && callOptions.idempotencyKey) headers.set('Idempotency-Key', callOptions.idempotencyKey)
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), timeoutMs)
    const signal = callOptions.signal ? AbortSignal.any([controller.signal, callOptions.signal]) : controller.signal
    try {
      let response: Response
      let value: unknown
      try {
        response = await send(`${base}${path}`, { method: spec ? 'POST' : 'GET', headers,
          ...(spec ? { body: JSON.stringify({ input }) } : {}), signal, credentials: 'omit', redirect: 'error', cache: 'no-store' })
        value = await read(response, signal)
      } catch {
        throw new CoreUserApiError('RESULT_UNKNOWN', 0, undefined, mutation)
      }
      if (!response.ok || value !== null && typeof value === 'object' && Object.hasOwn(value, 'error')) {
        const parsed = failure.safeParse(value)
        const code = parsed.success && codes.has(parsed.data.error.code) ? parsed.data.error.code : 'UPSTREAM_ERROR'
        const uncertain = mutation && !['UNAUTHORIZED', 'FORBIDDEN', 'NOT_FOUND', 'VALIDATION_ERROR',
          'BUSINESS_REJECTED', 'IDEMPOTENCY_CONFLICT', 'METHOD_NOT_ALLOWED', 'ORIGIN_DENIED'].includes(code)
        throw new CoreUserApiError(code, response.status, parsed.success ? parsed.data.meta.requestId : undefined, uncertain)
      }
      if (!spec) {
        const result = capabilityEnvelope.safeParse(value)
        if (!result.success || result.data.data.contract !== surface.contract || new Set(result.data.data.operations.map(row => row.id)).size !== result.data.data.operations.length) {
          throw new CoreUserApiError('INVALID_RESPONSE', response.status)
        }
        for (const row of result.data.data.operations) {
          if (!Object.hasOwn(coreOperations, row.id) || surface.operations && !surface.operations.includes(row.id as CoreOperation)) throw new CoreUserApiError('VERSION_MISMATCH', response.status)
          const known = coreOperations[row.id as CoreOperation]
          if (row.actor !== known.actor || row.idempotency !== known.idempotency) throw new CoreUserApiError('VERSION_MISMATCH', response.status)
        }
        return result.data.data
      }
      const result = success.safeParse(value)
      if (!result.success || !Object.hasOwn(result.data, 'data') || !Object.hasOwn(result.data.meta, 'coreMeta')) {
        throw new CoreUserApiError('INVALID_RESPONSE', response.status, undefined, mutation)
      }
      const data = spec.output.safeParse(result.data.data)
      const meta = spec.meta.safeParse(result.data.meta.coreMeta)
      if (!data.success || !meta.success) throw new CoreUserApiError('INVALID_RESPONSE', response.status, result.data.meta.requestId, mutation)
      return { data: data.data, meta: meta.data, requestId: result.data.meta.coreRequestId }
    } finally { clearTimeout(timer) }
  }
  const resultTransport: CoreTransport = (operation, input, settings) => request(`/operations/${encodeURIComponent(operation)}`, operation, input, settings)
  const transport: CoreTransport = async (operation, input, settings) => {
    const result = await resultTransport(operation, input, settings) as { data: unknown }
    return result.data
  }
  return Object.assign(new CoreClient(transport, resultTransport), {
    capabilities: (settings: { signal?: AbortSignal } = {}) => request('/capabilities', undefined, undefined, settings) as Promise<CoreUserCapabilities>,
  })
}

export function createCoreUserClient(options: CoreUserClientOptions = {}): CoreUserClient {
  return createBrowserCoreClient(options, { domain: 'user', contract: 'core-user/v1', prefix: '/gateway/v1/core/user', operations: CORE_USER_OPERATIONS })
}
