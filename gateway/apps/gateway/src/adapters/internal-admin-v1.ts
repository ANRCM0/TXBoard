import { z } from 'zod'

const decimal = z.string().regex(/^(?:0|-?[1-9][0-9]{0,18})$(?![\s\S])/)
  .refine(value => BigInt(value) >= -9223372036854775808n && BigInt(value) <= 9223372036854775807n)
const count = z.number().int().nonnegative().safe()
const traffic = z.strictObject({ upload: decimal, download: decimal, total: decimal })
export const adminPath = z.string().regex(/^[A-Za-z0-9_-]{1,128}$(?![\s\S])/)
export const adminReadInput = z.strictObject({ admin_path: adminPath })
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
export const adminResponses = { login: adminLoginSuccess, identity: adminIdentitySuccess, dashboard: adminDashboardSuccess }
export type AdminOperation = keyof typeof adminResponses
export type AdminResponses = { [K in AdminOperation]: z.infer<(typeof adminResponses)[K]> }
