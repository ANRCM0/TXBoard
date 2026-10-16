import { z } from 'zod'
import { GatewayFailure } from '../services/failures.js'
import type { UpstreamOperation } from '../services/upstream.js'

// Native Core DTOs are validated before projection into the frozen Gateway v1
// display contract. z.object strips extension fields; none are passed through.
const integer = z.number().int().min(0).max(Number.MAX_SAFE_INTEGER)
const id = integer.min(1)
const nullableLimit = integer.nullable()
const iso = z.iso.datetime({ offset: true })
const nullableDate = iso.nullable()
const planRef = z.object({ id, name: z.string() })
const periodNames = {
  monthly: 'month_price', quarterly: 'quarter_price', half_yearly: 'half_year_price',
  yearly: 'year_price', two_yearly: 'two_year_price', three_yearly: 'three_year_price',
  onetime: 'onetime_price', reset_traffic: 'reset_price',
} as const
const period = z.enum(Object.keys(periodNames) as [keyof typeof periodNames, ...Array<keyof typeof periodNames>])
const plan = z.object({
  id, name: z.string(), content: z.string(), tags: z.array(z.string()),
  traffic_limit_bytes: integer, speed_limit_mbps: nullableLimit, device_limit: nullableLimit,
  capacity_limit: nullableLimit, reset_traffic_method: nullableLimit,
  prices: z.array(z.object({ period, amount_minor: integer })), renewable: z.boolean(),
})
const profile = z.object({
  id, email: z.email(), plan_id: id.nullable(), balance_minor: integer,
  commission_balance_minor: integer, expired_at: nullableDate, telegram_id: z.number().int().safe().nullable(),
  traffic: z.object({ upload_bytes: integer, download_bytes: integer, limit_bytes: integer }),
})
const subscription = z.object({
  plan: planRef.extend({ traffic_limit_bytes: integer }).nullable(),
  reset_day: integer.max(31).nullable(), upload_bytes: integer, download_bytes: integer,
  traffic_limit_bytes: integer, device_limit: nullableLimit,
  speed_limit_mbps: z.number().nonnegative().finite().nullable(), expired_at: nullableDate,
  next_reset_at: nullableDate,
})
const order = z.object({
  id, trade_no: z.string().regex(/^[a-zA-Z0-9_-]{1,128}$/), plan_id: id,
  period, type: integer, status: integer.max(4), amount_minor: integer,
  plan: planRef.nullable(), paid_at: nullableDate, created_at: iso,
})
const detail = order.extend({
  plan: planRef.extend({ traffic_limit_bytes: integer }), payment_id: id.nullable(),
  balance_amount_minor: integer, discount_amount_minor: integer,
})
const payment = z.object({
  id, name: z.string(), provider: z.string(), icon: z.string().nullable(),
  fee_fixed_minor: integer, fee_percent: z.number().nonnegative().finite(),
})
const notice = z.object({
  id, title: z.string(), content: z.string(), img_url: z.string().nullable(),
  tags: z.array(z.string()), created_at: iso,
})
const stats = z.object({ unpaid_orders: integer, open_tickets: integer, invited_users: integer })
const auth = z.object({ auth_data: z.string().regex(/^Bearer \S{8,4096}$/) })
const site = z.object({
  app_name: z.string().optional(), app_description: z.string().nullable().optional(),
  app_url: z.string().nullable().optional(), logo: z.string().nullable().optional(),
  frontend_theme: z.string().optional(),
  // PHP serializes an empty associative array as []; accept only the empty
  // array variant, while rejecting nonempty lists masquerading as config.
  theme_config: z.union([z.record(z.string(), z.unknown()), z.tuple([]).transform(() => ({}))]).optional(),
  is_captcha: z.union([z.boolean(), z.literal(0), z.literal(1), z.literal('0'), z.literal('1')]).optional(),
  captcha_type: z.string().nullable().optional(), turnstile_site_key: z.string().nullable().optional(),
  recaptcha_site_key: z.string().nullable().optional(), recaptcha_v3_site_key: z.string().nullable().optional(),
})
const pagination = z.strictObject({ page: id, per_page: id.max(100), total: integer, last_page: id })

export const nativeSuccessEnvelope = z.strictObject({
  data: z.unknown(), meta: z.record(z.string(), z.unknown()).optional(), request_id: z.string().uuid(),
}).refine(value => Object.hasOwn(value, 'data'))
export const nativeErrorEnvelope = z.strictObject({
  error: z.object({ code: z.string().min(1), message: z.string(), fields: z.unknown().optional() }),
  request_id: z.string().uuid(),
})

function checked<T>(schema: z.ZodType<T>, value: unknown): T {
  const parsed = schema.safeParse(value)
  if (!parsed.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream response shape')
  return parsed.data
}
const seconds = (date: string | null) => date === null ? null : Date.parse(date) / 1000
function orderDto(row: z.infer<typeof order>) {
  return {
    id: row.id, trade_no: row.trade_no, plan_id: row.plan_id,
    period: periodNames[row.period], type: row.type, status: row.status,
    total_amount: row.amount_minor, plan: row.plan,
    paid_at: seconds(row.paid_at), created_at: seconds(row.created_at),
  }
}

/** No business decisions, price recalculation or privileged credentials. */
export function adaptNativeData(
  operation: UpstreamOperation, data: unknown, meta: unknown,
  options: { tradeNo?: string; current?: number; pageSize?: number; paginated?: boolean } = {},
): unknown {
  switch (operation) {
    case 'guestConfig': return checked(site, data)
    case 'guestPlans': return checked(z.array(plan), data).map(row => {
      const prices: Record<string, number | null> = Object.fromEntries(Object.values(periodNames).map(key => [key, null]))
      for (const price of row.prices) {
        const key = periodNames[price.period]
        if (prices[key] !== null) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Duplicate upstream plan period')
        prices[key] = price.amount_minor
      }
      return { id: row.id, name: row.name, content: row.content, tags: row.tags,
        transfer_enable: row.traffic_limit_bytes / 1073741824,
        speed_limit: row.speed_limit_mbps, device_limit: row.device_limit,
        capacity_limit: row.capacity_limit, reset_traffic_method: row.reset_traffic_method,
        renew: row.renewable, ...prices }
    })
    case 'login': case 'register': return checked(auth, data)
    case 'sendEmailCode': checked(z.object({ ok: z.literal(true) }), data); return true
    case 'userProfile': {
      const row = checked(profile, data)
      return { id: row.id, email: row.email, plan_id: row.plan_id, balance: row.balance_minor,
        commission_balance: row.commission_balance_minor, expired_at: seconds(row.expired_at),
        telegram_id: row.telegram_id, u: row.traffic.upload_bytes,
        d: row.traffic.download_bytes, transfer_enable: row.traffic.limit_bytes }
    }
    case 'userSubscription': {
      const row = checked(subscription, data)
      return { plan_id: row.plan?.id ?? null, plan: row.plan,
        expired_at: seconds(row.expired_at), u: row.upload_bytes, d: row.download_bytes,
        transfer_enable: row.traffic_limit_bytes, reset_day: row.reset_day,
        device_limit: row.device_limit, speed_limit: row.speed_limit_mbps }
    }
    case 'userStats': {
      const row = checked(stats, data)
      return [row.unpaid_orders, row.open_tickets, row.invited_users]
    }
    case 'userOrders': {
      const page = checked(pagination, meta)
      const rows = checked(z.array(order), data)
      verifyPage(page, rows.length, options.current ?? 1, options.pageSize ?? 100)
      const items = rows.map(orderDto)
      return options.paginated
        ? { items, current: page.page, pageSize: page.per_page, total: page.total, lastPage: page.last_page }
        : items
    }
    case 'userOrderDetail': {
      const row = checked(detail, data)
      verifyOrder(row.trade_no, options.tradeNo)
      return { ...orderDto(row), plan: { id: row.plan.id, name: row.plan.name,
        transfer_enable: row.plan.traffic_limit_bytes / 1073741824 },
        payment_id: row.payment_id, balance_amount: row.balance_amount_minor,
        discount_amount: row.discount_amount_minor }
    }
    case 'userOrderStatus': {
      const row = checked(order, data)
      verifyOrder(row.trade_no, options.tradeNo)
      return row.status
    }
    case 'userPaymentMethods': return checked(z.array(payment), data).map(row => ({
      id: row.id, name: row.name, payment: row.provider, icon: row.icon,
      handling_fee_fixed: row.fee_fixed_minor, handling_fee_percent: row.fee_percent,
    }))
    case 'userNotices': {
      const page = checked(pagination, meta)
      const rows = checked(z.array(notice), data)
      verifyPage(page, rows.length, options.current ?? 1, options.pageSize ?? 5)
      return { data: rows.map(row => ({ ...row, created_at: seconds(row.created_at) })), total: page.total }
    }
    case 'userOrderCreate': return checked(z.object({ trade_no: order.shape.trade_no }), data)
    case 'guestPaymentMethods': throw new GatewayFailure('UPSTREAM_ERROR', 404, 'Public payment catalog unavailable')
  }
}

function verifyOrder(actual: string, expected: string | undefined): void {
  if (actual !== expected) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream order identifier')
}
function verifyPage(page: z.infer<typeof pagination>, count: number, expectedPage: number, expectedSize: number): void {
  if (page.page !== expectedPage || page.per_page !== expectedSize || count > page.per_page
    || page.last_page !== Math.max(1, Math.ceil(page.total / page.per_page))
    || count !== Math.max(0, Math.min(page.per_page, page.total - (page.page - 1) * page.per_page))) {
    throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream pagination')
  }
}
