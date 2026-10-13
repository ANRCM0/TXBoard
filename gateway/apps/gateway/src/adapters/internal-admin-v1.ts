import { z } from 'zod'

const decimal = z.string().regex(/^(?:0|-?[1-9][0-9]{0,18})$(?![\s\S])/)
  .refine(value => BigInt(value) >= -9223372036854775808n && BigInt(value) <= 9223372036854775807n)
const count = z.number().int().nonnegative().safe()
const traffic = z.strictObject({ upload: decimal, download: decimal, total: decimal })
export const adminPath = z.string().regex(/^[A-Za-z0-9_-]{1,128}$(?![\s\S])/)
export const adminReadInput = z.strictObject({ admin_path: adminPath })
export const adminPlanListInput = adminReadInput.extend({ page: z.number().int().min(1).max(9999),
  per_page: z.number().int().min(1).max(100) })
export const adminOrderListInput = adminReadInput.extend({ page: z.number().int().min(1).max(9999),
  per_page: z.number().int().min(1).max(100), status: z.number().int().min(0).max(4).optional() })
export const adminLoginInput = z.strictObject({
  email: z.email().max(254).refine(value => value.endsWith('@example.test')),
  password: z.string().min(8).max(1024),
  turnstile_token: z.string().max(4096).optional(),
  recaptcha_v3_token: z.string().max(4096).optional(),
  recaptcha_data: z.string().max(4096).optional(),
})
const success = z.strictObject({
  data: z.unknown(), request_id: z.string().length(36).uuid(),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.string() }),
})
export const adminLoginSuccess = success.extend({
  data: z.strictObject({ auth_data: z.string().regex(/^Bearer [A-Za-z0-9|._-]{8,4089}$(?![\s\S])/),
    is_admin: z.literal(true), secure_path: adminPath }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-isolated-admin-login/1') }),
})
export const adminIdentitySuccess = success.extend({
  data: z.strictObject({ id: z.string().regex(/^[1-9][0-9]{0,17}$(?![\s\S])/),
    email: z.email().max(254), is_admin: z.literal(true) }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-admin-identity/1') }),
})
export const adminDashboardSuccess = success.extend({
  data: z.strictObject({
    todayIncome: decimal, currentMonthIncome: decimal, lastMonthIncome: decimal,
    currentMonthCommissionPayout: decimal, lastMonthCommissionPayout: decimal,
    commissionPendingTotal: count, currentMonthNewUsers: count, totalUsers: count, activeUsers: count,
    onlineUsers: count, onlineDevices: count, ticketPendingTotal: count, onlineNodes: count,
    dayIncomeGrowth: z.number().finite(), monthIncomeGrowth: z.number().finite(),
    lastMonthIncomeGrowth: z.number().finite(), commissionGrowth: z.number().finite(), userGrowth: z.number().finite(),
    todayTraffic: traffic, monthTraffic: traffic, totalTraffic: traffic,
  }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-admin-dashboard/1') }),
})
const id = z.string().regex(/^[1-9][0-9]{0,17}$(?![\s\S])/)
const nativeId = id.refine(value => BigInt(value) <= 2147483647n)
export const adminUserGetInput = adminReadInput.extend({ id: nativeId })
export const adminUserListInput = adminPlanListInput.extend({ banned: z.boolean().optional(), plan_id: nativeId.optional() })
export const adminSessionRevokeInput = adminReadInput.extend({ session_id: id })
const date = z.number().int().nonnegative().safe()
export const adminOrderListSuccess = success.extend({
  data: z.strictObject({ items: z.array(z.strictObject({
    id, trade_no: z.string().min(1).max(128), user_id: id, plan_id: id,
    plan: z.strictObject({ id, name: z.string() }).nullable(), user: z.strictObject({ id, email: z.email() }).nullable(),
    period: z.enum(['monthly','quarterly','half_yearly','yearly','two_yearly','three_yearly','onetime','reset_traffic']),
    type: z.number().int().min(1).max(4), status: z.number().int().min(0).max(4),
    amount_minor: decimal, handling_amount_minor: decimal, balance_amount_minor: decimal, discount_amount_minor: decimal,
    paid_at: date.nullable(), created_at: date, updated_at: date,
  })).max(100), pagination: z.strictObject({ page: z.number().int().min(1).max(9999), per_page: z.number().int().min(1).max(100),
    total: count, last_page: z.number().int().min(1).safe() }) }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-admin-order-list/1') }),
})
const period = z.enum(['monthly','quarterly','half_yearly','yearly','two_yearly','three_yearly','onetime','reset_traffic'])
const nativePrice = z.string().max(128).regex(/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]{1,4})?$(?![\s\S])/).nullable()
export const adminPlanListSuccess = success.extend({
  data: z.strictObject({ items: z.array(z.strictObject({
    id, name: z.string(), content: z.string().nullable(), group_id: id.nullable(),
    group: z.strictObject({ id, name: z.string() }).nullable(),
    traffic_limit_gib: decimal.refine(value => BigInt(value) >= 0n),
    speed_limit_mbps: count.nullable(), device_limit: count.nullable(), capacity_limit: count.nullable(),
    reset_traffic_method: z.number().int().min(0).max(4).nullable(),
    native_prices_major: z.partialRecord(period, nativePrice), tags: z.array(z.string()),
    show: z.boolean(), sell: z.boolean(), renew: z.boolean(), sort: z.number().int().safe(),
    users_count: count, active_users_count: count,
  })).max(100), pagination: z.strictObject({ page: z.number().int().min(1).max(9999), per_page: z.number().int().min(1).max(100),
    total: count, last_page: z.number().int().min(1).safe() }) }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-admin-plan-list/1') }),
})
const sessionDate = z.iso.datetime({ offset: true }).nullable()
export const adminSessionMutationSuccess = success.extend({ data: z.strictObject({ ok: z.literal(true) }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-isolated-admin-sessions/1') }) })
export const adminSessionListSuccess = adminSessionMutationSuccess.extend({ data: z.strictObject({ items: z.array(z.strictObject({
  id, name: z.string().max(255), abilities: z.array(z.string().max(255)).max(100),
  last_used_at: sessionDate, created_at: sessionDate, updated_at: sessionDate, expires_at: sessionDate,
})).max(100).refine(items => items.every((item, index) => index === 0 || BigInt(items[index-1]!.id) > BigInt(item.id))) }) })
const userItem = z.strictObject({
  id: nativeId, email: z.email().max(254), plan_id: nativeId.nullable(), group_id: nativeId.nullable(),
  plan: z.strictObject({ id: nativeId, name: z.string() }).nullable(), group: z.strictObject({ id: nativeId, name: z.string() }).nullable(),
  balance_minor: decimal, commission_balance_minor: decimal,
  traffic: z.strictObject({ upload_bytes: decimal, download_bytes: decimal, limit_bytes: decimal }),
  expired_at: date.nullable(), created_at: date, next_reset_at: date.nullable(), last_reset_at: date.nullable(), last_login_at: date.nullable(),
  reset_count: count, speed_limit_mbps: z.number().finite().nullable(), device_limit: count.nullable(),
  banned: z.boolean(), is_admin: z.boolean(), is_staff: z.boolean(), remarks: z.string().nullable(),
})
export const adminUserGetSuccess = success.extend({ data: userItem.extend({ invite_user: z.strictObject({ id: nativeId, email: z.email() }).nullable() }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-admin-users/1') }) })
export const adminUserListSuccess = success.extend({ data: z.strictObject({ items: z.array(userItem).max(100),
  pagination: z.strictObject({ page: z.number().int().min(1).max(9999), per_page: z.number().int().min(1).max(100),
    total: count, last_page: z.number().int().min(1).safe() }) }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-admin-users/1') }) })
export const adminNetworkGroupListSuccess = success.extend({ data: z.strictObject({ items: z.array(z.strictObject({
  id: nativeId, name: z.string(), users_count: count, plans_count: count, server_count: count, created_at: date.nullable(), updated_at: date.nullable(),
})).max(100).refine(items => items.every((item, index) => index === 0 || BigInt(items[index-1]!.id) > BigInt(item.id))),
  pagination: z.strictObject({ page: z.number().int().min(1).max(9999), per_page: z.number().int().min(1).max(100), total: count, last_page: z.number().int().min(1).safe() }) }),
  meta: z.strictObject({ current_only: z.literal(true), slice: z.literal('internal-admin-network-groups/1') }) })
export const adminResponses = { login: adminLoginSuccess, identity: adminIdentitySuccess, dashboard: adminDashboardSuccess,
  orders: adminOrderListSuccess, plans: adminPlanListSuccess, sessions: adminSessionListSuccess,
  revokeSession: adminSessionMutationSuccess, logout: adminSessionMutationSuccess,
  networkGroups: adminNetworkGroupListSuccess, users: adminUserListSuccess, user: adminUserGetSuccess }
export type AdminOperation = keyof typeof adminResponses
export type AdminResponses = { [K in AdminOperation]: z.infer<(typeof adminResponses)[K]> }
