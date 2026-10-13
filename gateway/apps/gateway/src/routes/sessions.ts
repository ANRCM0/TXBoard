import { sessionEmptySchema, sessionRevokeSchema, sealedRequestSchema } from '../contracts/schemas.js'
import { authBearer } from '../middleware/auth.js'
import { readJsonRequest } from '../middleware/validation.js'
import { PREFIX } from '../middleware/request-context.js'
import type { CryptoService, CryptoOperation } from '../services/crypto.js'
import { internalUserRequest } from '../services/internal-user-client.js'
import type { AccountLimiter } from '../services/redis-security.js'
import { fail, ok, type RouteContext } from './shared.js'
import type { GatewayConfig } from '../config/env.js'

/** Request plumbing only. Actual ownership, User role and delete remain Core authority. */
export function registerSessionRoutes(app: {
  get(path: string, handler: (c: RouteContext) => Promise<Response>): unknown
  post(path: string, handler: (c: RouteContext) => Promise<Response>): unknown
}, deps: { config: GatewayConfig; crypto: CryptoService; limiter: AccountLimiter }): void {
  const { config, crypto, limiter } = deps
  async function limited(c: RouteContext, auth: string) {
    const ip = (c.req.raw as Request & { __clientIp?: string }).__clientIp ?? '0.0.0.0'
    await limiter.check('sessions', auth, ip)
  }
  app.get(`${PREFIX}/user/sessions`, async c => {
    const auth = authBearer(c)
    if (!auth) return fail(c, 'UNAUTHORIZED', 'Bearer token required', 401)
    if (Object.keys(c.req.queries()).length) return fail(c, 'VALIDATION_ERROR', 'Invalid session query', 400)
    await limited(c, auth)
    return ok(c, await internalUserRequest(config, 'userSessionList', { auth, body: {}, signal: c.req.raw.signal }))
  })
  const mutation = (operation: Extract<CryptoOperation, 'session-revoke' | 'session-logout'>) => async (c: RouteContext) => {
    const auth = authBearer(c)
    if (!auth) return fail(c, 'UNAUTHORIZED', 'Bearer token required', 401)
    if (Object.keys(c.req.queries()).length) return fail(c, 'VALIDATION_ERROR', 'Invalid session query', 400)
    const sealed = sealedRequestSchema.safeParse(await readJsonRequest(c.req.raw, config.maxRequestBytes))
    if (!sealed.success) return fail(c, 'VALIDATION_ERROR', 'Invalid encrypted request', 400)
    const data = await crypto.open(sealed.data, operation)
    const input = (operation === 'session-revoke' ? sessionRevokeSchema : sessionEmptySchema).safeParse(data)
    if (!input.success) return fail(c, 'VALIDATION_ERROR', 'Invalid session request', 400)
    await limited(c, auth)
    return ok(c, await internalUserRequest(config, operation === 'session-revoke' ? 'userSessionRevoke' : 'userSessionLogout',
      { auth, body: input.data, signal: c.req.raw.signal }))
  }
  app.post(`${PREFIX}/secure/auth/session-revoke`, mutation('session-revoke'))
  app.post(`${PREFIX}/secure/auth/session-logout`, mutation('session-logout'))
}
