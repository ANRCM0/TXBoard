import { GatewayFailure, upstreamRequest } from '../services/upstream.js'
import { orderCreateResultSchema, orderCreateSchema } from '../contracts/schemas.js'
import { PREFIX } from '../middleware/request-context.js'
import { authBearer } from '../middleware/auth.js'
import { policyBodyOf } from '../middleware/policy.js'
import {
  orderIdempotencyHint,
  validateIdempotencyKey,
} from '../middleware/idempotency.js'
import { fail, ok, validated, type RouteContext, type RouteDeps } from './shared.js'

/**
 * routes/orders-write.ts — the GW-304 order-write adaptation layer.
 *
 * This module exists ONLY when GATEWAY_ORDER_WRITE_ENABLED is set. It is a
 * thin, stateless pass-through, and every line of it answers to three rules
 * from the M3 boundary (docs/adr-order-idempotency.md §7, GW-304 acceptance):
 *
 *  1. **No admin token, no business rules.** The Gateway forwards the USER's
 *     own bearer verbatim. It never recomputes a price, never checks a
 *     balance, never validates a coupon and never decides whether an order may
 *     proceed — Laravel owns all of that (GW-303/305).
 *
 *  2. **No local idempotency.** The Gateway holds no idempotency state: the
 *     `Idempotency-Key` is forwarded verbatim and the `X-Idempotency-Key-Hint`
 *     is a correlation value derived from the body alone. Laravel's persisted
 *     `idempotency_keys` table is the only authority.
 *
 *  3. **Fail loud, never fail silent.** A missing or malformed key is a 400
 *     before ANY upstream call; an upstream idempotency verdict is surfaced
 *     verbatim (409 + Retry-After), never collapsed to a 502 that would make
 *     the SDK re-post.
 *
 * The route's policy (`orderWrite`) is declared in config/policies.ts and
 * guarantees the bearer check and the JSON body envelope happen BEFORE this
 * handler runs — so this module never sees an uncredentialed or unparsable
 * request.
 */

export type OrdersWriteRouteDeps = RouteDeps

/** The frozen upstream operation this route (and only this route) may call. */
const ORDER_WRITE_OPERATION = 'order.create'

export function registerOrdersWriteRoutes(
  app: {
    post(path: string, handler: (c: RouteContext) => Promise<Response> | Response): unknown
  },
  deps: OrdersWriteRouteDeps,
): void {
  const { config, fetcher } = deps

  app.post(`${PREFIX}/orders`, async c => {
    // Defence in depth: the policy layer already enforced the bearer shape.
    // A request that reaches here without one is a bug, not a client error —
    // refuse rather than forward anything upstream.
    const auth = authBearer(c)
    if (!auth) return fail(c, 'UNAUTHORIZED', 'Bearer token required', 401)

    // GW-304 acceptance: "无幂等键拒绝". No Idempotency-Key, no upstream call.
    // This is the ONLY Gateway-side idempotency rule, and it is a format rule.
    const keyVerdict = validateIdempotencyKey(c.req.header('Idempotency-Key'))
    if (!keyVerdict.valid) {
      return fail(c, 'VALIDATION_ERROR',
        keyVerdict.reason === 'missing'
          ? 'Idempotency-Key header is required'
          : 'Invalid Idempotency-Key header',
        400)
    }

    // The policy layer already read, size-capped and shape-checked the body,
    // and publishes it on the context so the consumed stream is never read
    // twice. Parse it here against the strict order schema: unknown fields
    // must never be smuggled upstream.
    const raw = policyBodyOf(c)
    if (raw === undefined || raw === null) {
      return fail(c, 'VALIDATION_ERROR', 'Invalid order request', 400)
    }
    const checked = orderCreateSchema.safeParse(raw)
    if (!checked.success) {
      return fail(c, 'VALIDATION_ERROR', 'Invalid order request', 400)
    }

    // The hint is derived from the validated body ONLY — never from the
    // bearer, a cookie, an email or any other identity the Gateway cannot
    // verify. Laravel re-derives the authoritative key with the user_id.
    const hint = orderIdempotencyHint(ORDER_WRITE_OPERATION, checked.data)

    let data: unknown
    try {
      data = await upstreamRequest(config, fetcher, 'userOrderCreate', {
        auth,
        body: checked.data,
        signal: c.req.raw.signal,
        headers: {
          'Idempotency-Key': keyVerdict.key,
          'X-Idempotency-Key-Hint': hint,
        },
      })
    } catch (error) {
      if (error instanceof GatewayFailure) {
        // An idempotency verdict is retryable-by-backoff, not by re-posting:
        // surface the status (409/4xx) and the Retry-After the upstream sent.
        if (error.status === 409 || error.status === 429 || error.status >= 400) {
          c.header('Retry-After', '2')
        }
        throw error
      }
      throw error
    }

    return ok(c, validated(orderCreateResultSchema, data), 201)
  })
}
