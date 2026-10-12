import { upstreamRequest } from '../services/upstream.js'
import { toPublicOrderStatus, toPublicPaymentMethods } from '../adapters/txboard-v1.js'
import {
  ORDER_STATUSES,
  PREFIX,
  TRADE_NO_PATTERN,
} from '../middleware/request-context.js'
import {
  orderDetailSchema,
  orderListSchema,
  orderPageSchema,
  noticeCurrentPattern,
  noticePageSizePattern,
  orderStatusSchema,
  paymentMethodsSchema,
} from '../contracts/schemas.js'
import { authBearer } from '../middleware/auth.js'
import { fail, ok, validated, type RouteContext, type RouteDeps } from './shared.js'

/**
 * routes/orders.ts — order and payment read routes plus the write ban.
 *
 * Order creation defaults to a local 405; the explicit order-write opt-in
 * registers its separate handler. Order ownership is decided by Core.
 */

export type OrdersRouteDeps = RouteDeps

function requireBearer(c: RouteContext): string | Response {
  const auth = authBearer(c)
  if (!auth) return fail(c, 'UNAUTHORIZED', 'Bearer token required', 401)
  return auth
}

/** Explicit bounded pagination; no unchecked query parameter is forwarded. */
function parseOrderQuery(c: RouteContext): { status?: number; current: number; pageSize: number } | Response {
  const status = c.req.query('status')
  const params = c.req.queries()
  const current = c.req.query('current') ?? '1'
  const pageSize = c.req.query('page_size') ?? '100'
  if (Object.keys(params).some(key => !['status', 'current', 'page_size'].includes(key))
    || (status !== undefined && !ORDER_STATUSES.has(status))
    || Object.values(params).some(values => values.length !== 1)
    || !noticeCurrentPattern.test(current) || !noticePageSizePattern.test(pageSize)) {
    return fail(c, 'VALIDATION_ERROR', 'Invalid order query', 400)
  }
  return { ...(status === undefined ? {} : { status: Number(status) }), current: Number(current), pageSize: Number(pageSize) }
}

/** Validate an order identifier path parameter. */
function parseTradeNo(c: RouteContext): string | Response {
  const tradeNo = c.req.param('tradeNo')
  if (typeof tradeNo !== 'string') {
    return fail(c, 'VALIDATION_ERROR', 'Invalid order identifier', 400)
  }
  if (!TRADE_NO_PATTERN.test(tradeNo)) {
    return fail(c, 'VALIDATION_ERROR', 'Invalid order identifier', 400)
  }
  return tradeNo
}

export function registerOrdersRoutes(
  app: {
    get(path: string, handler: (c: RouteContext) => Promise<Response> | Response): unknown
    post(path: string, handler: (c: RouteContext) => Promise<Response> | Response): unknown
  },
  deps: OrdersRouteDeps,
): void {
  const { config, fetcher } = deps
  app.get(`${PREFIX}/orders`, async c => {
    const auth = requireBearer(c)
    if (auth instanceof Response) return auth
    const query = parseOrderQuery(c)
    if (query instanceof Response) return query
    return ok(c, validated(orderListSchema, await upstreamRequest(config, fetcher, 'userOrders',
      { auth, ...query, signal: c.req.raw.signal })))
  })

  // Additive paged response; keeps the v1 envelope and /orders array intact.
  // Register before the identifier route so "page" is not treated as an ID.
  app.get(`${PREFIX}/orders/page`, async c => {
    const auth = requireBearer(c)
    if (auth instanceof Response) return auth
    const query = parseOrderQuery(c)
    if (query instanceof Response) return query
    return ok(c, validated(orderPageSchema, await upstreamRequest(config, fetcher, 'userOrders',
      { auth, ...query, paginated: true, signal: c.req.raw.signal })))
  })

  app.get(`${PREFIX}/orders/:tradeNo`, async c => {
    const auth = requireBearer(c)
    if (auth instanceof Response) return auth
    const tradeNo = parseTradeNo(c)
    if (tradeNo instanceof Response) return tradeNo
    return ok(c, validated(orderDetailSchema,
      await upstreamRequest(config, fetcher, 'userOrderDetail', { auth, tradeNo, signal: c.req.raw.signal })))
  })

  app.get(`${PREFIX}/orders/:tradeNo/status`, async c => {
    const auth = requireBearer(c)
    if (auth instanceof Response) return auth
    const tradeNo = parseTradeNo(c)
    if (tradeNo instanceof Response) return tradeNo
    const status = validated(orderStatusSchema,
      await upstreamRequest(config, fetcher, 'userOrderStatus', { auth, tradeNo }))
    return ok(c, toPublicOrderStatus(tradeNo, status))
  })

  app.get(`${PREFIX}/payments`, async c => {
    const auth = requireBearer(c)
    if (auth instanceof Response) return auth
    const methods = validated(paymentMethodsSchema,
      await upstreamRequest(config, fetcher, 'userPaymentMethods', { auth }))
    return ok(c, toPublicPaymentMethods(methods))
  })

  // Write ban: never proxied upstream, so no transaction side effect is
  // possible. GW-304: when the order-write adaptation layer is enabled, the
  // real handler is registered by routes/orders-write.ts instead — this
  // module must not also register a 405 handler on the same path, because
  // Hono dispatches to the FIRST match and the ban would shadow the write.
  if (!config.orderWriteEnabled) {
    app.post(`${PREFIX}/orders`, c =>
      fail(c, 'METHOD_NOT_ALLOWED', 'Order creation is not available in Gateway v1 Phase 1', 405))
  }
}
