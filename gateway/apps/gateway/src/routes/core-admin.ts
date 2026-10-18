import { z } from 'zod'
import { coreOperations } from '../contracts/core.generated.js'
import { CORE_ADMIN_OPERATIONS, CORE_ADMIN_PREFIX } from '../contracts/core-admin.js'
import { NativeCoreClient } from '../services/native-internal.js'
import { GatewayFailure } from '../services/failures.js'
import type { AccountLimiter } from '../services/redis-security.js'
import { policyBodyOf } from '../middleware/policy.js'
import { authBearer } from '../middleware/auth.js'
import { type RouteContext, type RouteDeps, fail, ok } from './shared.js'

const wrapper = z.strictObject({ input: z.unknown() })
const uuid = z.uuid()
const path = /^[a-zA-Z0-9_-]{1,128}$/
const discovery = z.object({ contract: z.literal('native-internal-api/v1'), domain: z.literal('admin'),
  operations: z.array(z.object({ id: z.string(), version: z.literal('1'), actor: z.string(), idempotency: z.string() })).max(100) })

export function registerCoreAdminRoutes(app: {
  get(path: string, handler: (c: RouteContext) => Promise<Response>): unknown
  post(path: string, handler: (c: RouteContext) => Promise<Response>): unknown
}, { config, limiter }: RouteDeps & { limiter?: AccountLimiter }): void {
  if (!config.coreAdminOperationsEnabled) return
  if (!config.nativeAdminService || !limiter) throw new Error('Core admin operations require isolated admin mTLS and Redis account limiter')
  const client = new NativeCoreClient(config.upstream, config.nativeAdminService, config.timeoutMs, config.maxResponseBytes)
  app.get(`${CORE_ADMIN_PREFIX}/capabilities`, async c => {
    if (new URL(c.req.url).search) return fail(c, 'VALIDATION_ERROR', 'Query fields are not supported', 400)
    const result = await client.call('system.capabilities.get', { signal: c.req.raw.signal })
    const parsed = discovery.safeParse(result.data)
    if (!parsed.success || new Set(parsed.data.operations.map(row => row.id)).size !== parsed.data.operations.length) {
      throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core capability response')
    }
    const operations = CORE_ADMIN_OPERATIONS.flatMap(id => {
      const row = parsed.data.operations.find(row => row.id === id)
      const spec = coreOperations[id]
      if (!row) return []
      if (row.actor !== spec.actor || row.idempotency !== spec.idempotency) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Core capability contract changed')
      return [{ id, version: '1', actor: spec.actor, idempotency: spec.idempotency, permission: spec.permission }]
    })
    return ok(c, { contract: 'core-admin/v1', operations })
  })
  for (const id of CORE_ADMIN_OPERATIONS) app.post(`${CORE_ADMIN_PREFIX}/operations/${id}`, async c => {
    if (new URL(c.req.url).search) return fail(c, 'VALIDATION_ERROR', 'Query fields are not supported', 400)
    const parsed = wrapper.safeParse(policyBodyOf(c))
    const spec = coreOperations[id]
    if (!parsed.success || !spec.input.safeParse(parsed.data.input).success) return fail(c, 'VALIDATION_ERROR', 'Invalid Core operation input', 422)
    const adminPath = c.req.header('X-Core-Admin-Path')
    if (spec.actor !== 'anonymous' && !path.test(adminPath ?? '')) return fail(c, 'UNAUTHORIZED', 'Current administrator context required', 401)
    const key = c.req.header('Idempotency-Key')
    if (spec.idempotency !== 'read' && !uuid.safeParse(key).success) return fail(c, 'VALIDATION_ERROR', 'Original UUID idempotency key required', 400)
    if (id === 'identity.admin-session.create') {
      const email = (parsed.data.input as { email: string }).email.trim().toLowerCase()
      await limiter.check('login', email, (c.req.raw as unknown as { __clientIp?: string }).__clientIp)
    }
    const result = await client.call(id, { input: parsed.data.input as Record<string, unknown>,
      authorization: authBearer(c) ?? undefined, adminPath,
      ...(spec.idempotency === 'read' ? {} : { idempotencyKey: key }), signal: c.req.raw.signal })
    c.header('Cache-Control', 'private, no-store')
    return c.json({ ok: true, data: result.data, meta: { version: '1', requestId: c.get('requestId'),
      coreRequestId: result.request_id, coreMeta: result.meta ?? {} } })
  })
}
