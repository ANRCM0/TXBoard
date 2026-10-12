import { z } from 'zod'
import { GatewayFailure } from '../services/failures.js'
import { orderCreateSchema } from '../contracts/schemas.js'

// JS `$` also matches before a final line break; the lookahead requires absolute end.
const unsigned = z.string().regex(/^(?:0|[1-9][0-9]{0,19})$(?![\s\S])/)
const id = unsigned.refine(value => value !== '0')
const signed = z.string().regex(/^(?:0|-?[1-9][0-9]{0,19})$(?![\s\S])/)
const timestamp = z.number().int().nonnegative().safe()
const plan = z.strictObject({ id, name: z.string() })
const periods = {
  monthly: 'month_price', quarterly: 'quarter_price', half_yearly: 'half_year_price', yearly: 'year_price',
  two_yearly: 'two_year_price', three_yearly: 'three_year_price', onetime: 'onetime_price', reset_traffic: 'reset_price',
} as const
const period = z.enum(Object.keys(periods) as [keyof typeof periods, ...Array<keyof typeof periods>])
const profile = z.strictObject({
  id, email: z.email(), plan_id: id.nullable(), balance_minor: unsigned, commission_balance_minor: unsigned,
  expired_at: timestamp.nullable(), telegram_id: signed.nullable(),
  traffic: z.strictObject({ upload_bytes: unsigned, download_bytes: unsigned, limit_bytes: unsigned }),
})
const detail = z.strictObject({
  id, trade_no: z.string().regex(/^[A-Za-z0-9_-]{1,128}$(?![\s\S])/), plan_id: id, period,
  type: z.number().int().min(1).max(4), status: z.number().int().min(0).max(4), amount_minor: unsigned,
  plan: plan.extend({ traffic_limit_bytes: unsigned }), payment_id: id.nullable(),
  balance_amount_minor: unsigned, discount_amount_minor: unsigned,
  paid_at: timestamp.nullable(), created_at: timestamp,
})
const order = detail.omit({ payment_id: true, balance_amount_minor: true, discount_amount_minor: true })
  .extend({ plan: plan.nullable() })
const list = z.strictObject({ items: z.array(order).max(100), pagination: z.strictObject({
  page: z.number().int().min(1).max(9999), per_page: z.number().int().min(1).max(100),
  total: z.number().int().nonnegative().safe(), last_page: z.number().int().min(1).safe(),
}) })
const dashboard = z.strictObject({ unpaid_orders: timestamp, open_tickets: timestamp, invited_users: timestamp })
const methods = z.array(z.strictObject({ id, name: z.string(), provider: z.string(), icon: z.string().nullable(),
  fee_fixed_minor: unsigned, fee_percent: z.number().nonnegative().finite() }))
const subscriptionSummary = z.strictObject({
  plan: plan.extend({ traffic_limit_bytes: unsigned }).nullable(), reset_day: timestamp.max(31).nullable(),
  upload_bytes: unsigned, download_bytes: unsigned, traffic_limit_bytes: unsigned, device_limit: unsigned.nullable(),
  speed_limit_mbps: z.number().nonnegative().finite().nullable(), expired_at: timestamp.nullable(), next_reset_at: timestamp.nullable(),
})
const notices = z.strictObject({ items: z.array(z.strictObject({ id, title: z.string(), content: z.string(),
  img_url: z.string().nullable(), tags: z.array(z.string()), created_at: timestamp })).max(100), pagination: list.shape.pagination })
const orderStatus = z.strictObject({ trade_no: detail.shape.trade_no, status: detail.shape.status })
const publicSite = z.strictObject({
  app_name: z.string().optional(), app_description: z.string().nullable().optional(),
  app_url: z.string().nullable().optional(), logo: z.string().nullable().optional(), frontend_theme: z.string().optional(),
  theme_config: z.union([z.record(z.string(), z.unknown()), z.tuple([]).transform(() => ({}))]).optional(),
  is_captcha: z.union([z.boolean(), z.literal(0), z.literal(1), z.literal('0'), z.literal('1')]).optional(),
  captcha_type: z.string().nullable().optional(), turnstile_site_key: z.string().nullable().optional(),
  recaptcha_site_key: z.string().nullable().optional(), recaptcha_v3_site_key: z.string().nullable().optional(),
})
const publicPlan = z.strictObject({ id, name: z.string(), content: z.string(), tags: z.array(z.string()),
  traffic_limit_bytes: unsigned, speed_limit_mbps: unsigned.nullable(), device_limit: unsigned.nullable(),
  capacity_limit: unsigned.nullable(), reset_traffic_method: unsigned.nullable(), renewable: z.boolean(),
  prices: z.array(z.strictObject({ period, amount_minor: unsigned })).max(8),
})
export const internalSuccessV2 = z.strictObject({
  data: z.unknown(), request_id: z.string().length(36).uuid(),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-readonly/2') }),
})
export const internalError = z.strictObject({
  error: z.strictObject({ code: z.string().min(1).max(64), message: z.string(), fields: z.array(z.string()).optional() }),
  request_id: z.string().length(36).uuid(),
})
export const internalOrderWriteSuccess = internalSuccessV2.extend({
  data: z.strictObject({ trade_no: detail.shape.trade_no }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-order-write/1') }),
})
export const internalPublicSuccess = internalSuccessV2.extend({
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-public-readonly/1') }),
})
export const internalLoginSuccess = internalSuccessV2.extend({
  data: z.strictObject({ auth_data: z.string().regex(/^Bearer [A-Za-z0-9|._-]{8,4089}$(?![\s\S])/) }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-isolated-login/1') }),
})
export const internalEmailCodeSuccess = internalSuccessV2.extend({
  data: z.strictObject({ ok: z.literal(true) }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-isolated-email-code/1') }),
})
export function adaptInternalPublic(operation: 'guestConfig' | 'guestPlans', value: unknown): unknown {
  if (operation === 'guestConfig') {
    const parsed = publicSite.safeParse(value); if (!parsed.success) fail()
    return parsed.data
  }
  const parsed = z.array(publicPlan).safeParse(value); if (!parsed.success) fail()
  if (new Set(parsed.data.map(row => row.id)).size !== parsed.data.length) fail()
  return parsed.data.map(row => {
    const prices: Record<string, number | null> = Object.fromEntries(Object.values(periods).map(key => [key, null]))
    for (const price of row.prices) {
      const key = periods[price.period]
      if (prices[key] !== null) fail()
      prices[key] = safe(price.amount_minor)
    }
    return { id: safe(row.id), name: row.name, content: row.content, tags: row.tags,
      transfer_enable: safe(row.traffic_limit_bytes) / 1073741824,
      speed_limit: row.speed_limit_mbps === null ? null : safe(row.speed_limit_mbps),
      device_limit: row.device_limit === null ? null : safe(row.device_limit),
      capacity_limit: row.capacity_limit === null ? null : safe(row.capacity_limit),
      reset_traffic_method: row.reset_traffic_method === null ? null : safe(row.reset_traffic_method),
      renew: row.renewable, ...prices }
  })
}
export function internalOrderCreateInput(value: unknown, key: unknown): Record<string, string> {
  const parsed = orderCreateSchema.safeParse(value)
  if (!parsed.success || !Number.isSafeInteger(parsed.data.plan_id) || parsed.data.plan_id > 2147483647
    || typeof key !== 'string' || !/^[A-Za-z0-9._~-]{1,128}$(?![\s\S])/.test(key)) {
    throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid order request')
  }
  const period = Object.entries(periods).find(([, native]) => native === parsed.data.period)?.[0]
  if (!period) fail()
  return { plan_id: String(parsed.data.plan_id), period, idempotency_key: key,
    ...(parsed.data.coupon_code === undefined ? {} : { coupon_code: parsed.data.coupon_code }) }
}
function fail(): never { throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response') }
function safe(value: string): number {
  const number = BigInt(value)
  if (number > BigInt(Number.MAX_SAFE_INTEGER) || number < BigInt(Number.MIN_SAFE_INTEGER)) fail()
  return Number(number)
}
export function adaptInternalOrderStatus(value: unknown, tradeNo?: string): number {
  const parsed = orderStatus.safeParse(value)
  if (!parsed.success || parsed.data.trade_no !== tradeNo) fail()
  return parsed.data.status
}
export function adaptInternalContent(operation: 'userSubscription' | 'userNotices', value: unknown,
  options: { current?: number; pageSize?: number }): unknown {
  if (operation === 'userSubscription') {
    const parsed = subscriptionSummary.safeParse(value); if (!parsed.success) fail()
    const row = parsed.data
    return { plan_id: row.plan === null ? null : safe(row.plan.id),
      plan: row.plan === null ? null : { id: safe(row.plan.id), name: row.plan.name, traffic_limit_bytes: safe(row.plan.traffic_limit_bytes) },
      expired_at: row.expired_at, u: safe(row.upload_bytes), d: safe(row.download_bytes),
      transfer_enable: safe(row.traffic_limit_bytes), reset_day: row.reset_day,
      device_limit: row.device_limit === null ? null : safe(row.device_limit), speed_limit: row.speed_limit_mbps }
  }
  const parsed = notices.safeParse(value); if (!parsed.success) fail()
  const { items, pagination: page } = parsed.data
  if (page.page !== (options.current ?? 1) || page.per_page !== (options.pageSize ?? 5)
    || page.last_page !== Math.max(1, Math.ceil(page.total / page.per_page))
    || items.length !== Math.max(0, Math.min(page.per_page, page.total - (page.page - 1) * page.per_page))
    || new Set(items.map(row => row.id)).size !== items.length) fail()
  return { data: items.map(row => ({ ...row, id: safe(row.id) })), total: page.total }
}
export function adaptInternalDisplay(operation: 'userStats' | 'userPaymentMethods', value: unknown): unknown {
  if (operation === 'userStats') {
    const parsed = dashboard.safeParse(value); if (!parsed.success) fail()
    const row = parsed.data
    return [row.unpaid_orders, row.open_tickets, row.invited_users]
  }
  const parsed = methods.safeParse(value); if (!parsed.success) fail()
  if (new Set(parsed.data.map(row => row.id)).size !== parsed.data.length) fail()
  return parsed.data.map(row => ({ id: safe(row.id), name: row.name, payment: row.provider, icon: row.icon,
    handling_fee_fixed: safe(row.fee_fixed_minor), handling_fee_percent: row.fee_percent }))
}
export function adaptInternalOrderList(value: unknown,
  options: { current?: number; pageSize?: number; status?: number; paginated?: boolean }): unknown {
  const parsed = list.safeParse(value); if (!parsed.success) fail()
  const { items, pagination: page } = parsed.data
  if (page.page !== (options.current ?? 1) || page.per_page !== (options.pageSize ?? 100)
    || page.last_page !== Math.max(1, Math.ceil(page.total / page.per_page))
    || items.length !== Math.max(0, Math.min(page.per_page, page.total - (page.page - 1) * page.per_page))
    || new Set(items.map(row => row.id)).size !== items.length
    || new Set(items.map(row => row.trade_no)).size !== items.length) fail()
  const rows = items.map(row => {
    if (options.status !== undefined && row.status !== options.status) fail()
    return { id: safe(row.id), trade_no: row.trade_no, plan_id: safe(row.plan_id), period: periods[row.period],
      type: row.type, status: row.status, total_amount: safe(row.amount_minor),
      plan: row.plan === null ? null : { id: safe(row.plan.id), name: row.plan.name },
      paid_at: row.paid_at, created_at: row.created_at }
  })
  return options.paginated
    ? { items: rows, current: page.page, pageSize: page.per_page, total: page.total, lastPage: page.last_page }
    : rows
}
export function adaptInternalUserV2(operation: 'userProfile' | 'userOrderDetail', value: unknown, tradeNo?: string): unknown {
  if (operation === 'userProfile') {
    const parsed = profile.safeParse(value); if (!parsed.success) fail()
    const row = parsed.data
    return { id: safe(row.id), email: row.email, plan_id: row.plan_id === null ? null : safe(row.plan_id),
      balance: safe(row.balance_minor), commission_balance: safe(row.commission_balance_minor), expired_at: row.expired_at,
      telegram_id: row.telegram_id === null ? null : safe(row.telegram_id), u: safe(row.traffic.upload_bytes),
      d: safe(row.traffic.download_bytes), transfer_enable: safe(row.traffic.limit_bytes) }
  }
  const parsed = detail.safeParse(value); if (!parsed.success) fail()
  const row = parsed.data
  if (row.trade_no !== tradeNo) fail()
  return { id: safe(row.id), trade_no: row.trade_no, plan_id: safe(row.plan_id), period: periods[row.period],
    type: row.type, status: row.status, total_amount: safe(row.amount_minor),
    plan: { id: safe(row.plan.id), name: row.plan.name, transfer_enable: safe(row.plan.traffic_limit_bytes) / 1073741824 },
    paid_at: row.paid_at, created_at: row.created_at, payment_id: row.payment_id === null ? null : safe(row.payment_id),
    balance_amount: safe(row.balance_amount_minor), discount_amount: safe(row.discount_amount_minor) }
}

/** Standard JSON parsing plus duplicate decoded key rejection; input is byte/depth bounded by transport. */
export function strictInternalJson(text: string): unknown {
  let offset = 0
  const space = () => { while (offset < text.length && /\s/.test(text[offset]!)) offset++ }
  const string = (): string => {
    const start = offset++
    while (offset < text.length) {
      const char = text[offset++]
      if (char === '\\') offset++
      else if (char === '"') return JSON.parse(text.slice(start, offset)) as string
    }
    return fail()
  }
  const scan = (depth: number): void => {
    if (depth > 30) fail()
    space(); const char = text[offset]
    if (char === '"') { string(); return }
    if (char === '{' || char === '[') {
      const object = char === '{', end = object ? '}' : ']'
      offset++; space(); const seen = new Set<string>()
      if (text[offset] === end) { offset++; return }
      for (;;) {
        space()
        if (object) {
          if (text[offset] !== '"') fail()
          const key = string(); if (seen.has(key)) fail(); seen.add(key)
          space(); if (text[offset++] !== ':') fail()
        }
        scan(depth + 1); space(); const separator = text[offset++]
        if (separator === end) return
        if (separator !== ',') fail()
      }
    }
    const start = offset
    while (offset < text.length && !/[\s,\]}]/.test(text[offset]!)) offset++
    if (start === offset) fail()
  }
  try {
    const value = JSON.parse(text) as unknown
    scan(0); space(); if (offset !== text.length) fail()
    return value
  } catch { return fail() }
}
