import { internalUserRequest } from '../services/internal-user-client.js'
import { authBearer } from '../middleware/auth.js'
import { policyBodyOf } from '../middleware/policy.js'
import { PREFIX } from '../middleware/request-context.js'
import { fail, ok, validated, type RouteContext, type RouteDeps } from './shared.js'
import { z } from 'zod'

const empty = z.strictObject({})
const receipt = z.strictObject({ ok: z.literal(true) })
/** One native cancellation attempt through the isolated Internal boundary. */
export function registerOrderCancelRoutes(app: { post(path: string, handler: (c: RouteContext) => Promise<Response> | Response): unknown }, deps: RouteDeps): void {
  app.post(`${PREFIX}/orders/:tradeNo/cancel`, async c => {
    const auth = authBearer(c)
    if (!auth) return fail(c, 'UNAUTHORIZED', 'Bearer token required', 401)
    const tradeNo = c.req.param('tradeNo')
    const input = empty.safeParse(policyBodyOf(c))
    if (Object.keys(c.req.query()).length !== 0 || !input.success || typeof tradeNo !== 'string' || !/^[A-Za-z0-9_-]{1,128}$(?![\s\S])/.test(tradeNo)) return fail(c, 'VALIDATION_ERROR', 'Invalid cancellation request', 400)
    const result = await internalUserRequest(deps.config, 'userOrderCancel', { auth, tradeNo, body: input.data, signal: c.req.raw.signal })
    return ok(c, validated(receipt, result))
  })
}
