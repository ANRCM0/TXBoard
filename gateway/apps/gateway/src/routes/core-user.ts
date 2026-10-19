import { z } from 'zod'
import { coreOperations, CORE_SURFACE_OPERATION_LIMIT } from '../contracts/core.generated.js'
import { CORE_USER_OPERATIONS, CORE_USER_PREFIX, requiresCommerceWrites } from '../contracts/core-user.js'
import { NativeCoreClient } from '../services/native-internal.js'
import { GatewayFailure } from '../services/failures.js'
import { policyBodyOf } from '../middleware/policy.js'
import { authBearer } from '../middleware/auth.js'
import type { AccountLimiter } from '../services/redis-security.js'
import { type RouteContext, type RouteDeps, fail, ok } from './shared.js'

const wrapper = z.strictObject({ input: z.unknown() })
const key = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const capability = z.object({ id: z.string(), version: z.literal('1'), actor: z.string(), idempotency: z.string() })
const discovery = z.object({ contract: z.literal('native-internal-api/v1'), domain: z.literal('user'),
  operations: z.array(capability).max(CORE_SURFACE_OPERATION_LIMIT) })

export function registerCoreUserRoutes(app: {
  get(path: string, handler: (c: RouteContext) => Promise<Response>): unknown
  post(path: string, handler: (c: RouteContext) => Promise<Response>): unknown
}, { config, limiter }: RouteDeps & { limiter?: AccountLimiter }): void {
  if (!config.coreUserOperationsEnabled) return
  if (!config.nativeUserService) throw new Error('Core user operations require an isolated user service')
  const client = new NativeCoreClient(config.upstream, config.nativeUserService, config.timeoutMs, config.maxResponseBytes)

  app.get(`${CORE_USER_PREFIX}/capabilities`, async c => {
    if (new URL(c.req.url).search) return fail(c, 'VALIDATION_ERROR', 'Query fields are not supported', 400)
    const result = await client.call('system.capabilities.get', { signal: c.req.raw.signal })
    const parsed = discovery.safeParse(result.data)
    if (!parsed.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core capability response')
    const ids = parsed.data.operations.map(row => row.id)
    if (new Set(ids).size !== ids.length) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Invalid Core capability response')
    const operations = CORE_USER_OPERATIONS.flatMap(id => {
      const row = parsed.data.operations.find(row => row.id === id)
      const spec = coreOperations[id]
      if (!row || requiresCommerceWrites(id) && !config.orderWriteEnabled) return []
      if (row.actor !== spec.actor || row.idempotency !== spec.idempotency) {
        throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Core capability contract changed')
      }
      return [{ id, version: '1' as const, actor: spec.actor, idempotency: spec.idempotency }]
    })
    return ok(c, { contract: 'core-user/v1', operations })
  })

  for (const id of CORE_USER_OPERATIONS) {
    if (requiresCommerceWrites(id) && !config.orderWriteEnabled) continue
    app.post(`${CORE_USER_PREFIX}/operations/${id}`, async c => {
      if (new URL(c.req.url).search) return fail(c, 'VALIDATION_ERROR', 'Query fields are not supported', 400)
      const parsed = wrapper.safeParse(policyBodyOf(c))
      const spec = coreOperations[id]
      if (!parsed.success || !spec.input.safeParse(parsed.data.input).success) {
        return fail(c, 'VALIDATION_ERROR', 'Invalid Core operation input', 422)
      }
      const idempotencyKey = c.req.header('Idempotency-Key')
      if (spec.idempotency !== 'read' && !key.test(idempotencyKey ?? '')) {
        return fail(c, 'VALIDATION_ERROR', 'Original UUID idempotency key required', 400)
      }
      if (id === 'identity.session.create') {
        if (!limiter) throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Login account limiter unavailable')
        const email = (parsed.data.input as { email: string }).email.trim().toLowerCase()
        await limiter.check('login', email, (c.req.raw as unknown as { __clientIp?: string }).__clientIp)
      }
      const result = await client.call(id, {
        input: parsed.data.input as Record<string, unknown>, authorization: spec.actor === 'anonymous' ? undefined : authBearer(c) ?? undefined,
        ...(spec.idempotency === 'read' ? {} : { idempotencyKey }), signal: c.req.raw.signal,
      })
      // The canonical DTO and owner metadata have already passed generated validation.
      // This new namespace adds metadata without changing the historical theme envelope.
      c.header('Cache-Control', 'private, no-store')
      return c.json({ ok: true, data: result.data, meta: { version: '1', requestId: c.get('requestId'),
        coreRequestId: result.request_id, coreMeta: result.meta ?? {} } })
    })
  }
}
