import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'

export type DashboardStats = {
  todayIncome?: number
  dayIncomeGrowth?: number
  currentMonthIncome?: number
  monthIncomeGrowth?: number
  currentMonthCommissionPayout?: number
  commissionGrowth?: number
  ticketPendingTotal?: number
  commissionPendingTotal?: number
  currentMonthNewUsers?: number
  totalUsers?: number
  activeUsers?: number
  userGrowth?: number
  onlineUsers?: number
  onlineDevices?: number
  onlineNodes?: number
  todayTraffic?: { upload?: number; download?: number; total?: number }
  monthTraffic?: { upload?: number; download?: number; total?: number }
  totalTraffic?: { upload?: number; download?: number; total?: number }
}

export type AuditLog = {
  id?: number
  admin_id?: number
  action?: string
  method?: string
  uri?: string
  request_data?: string
  ip?: string
  created_at?: number
  admin?: { id?: number; email?: string }
}

export type Paged<T> = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: T[]
}

const analyticsPath = (operation: string) => nativeAdminPath('analytics') + '/' + operation

export function getDashboardStats(): Promise<DashboardStats> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<DashboardStats>>(
    analyticsPath('dashboard'),
  ))
}

export function getOrderChart(params: { start_date?: string; end_date?: string } = {}):
  Promise<{ list?: Array<Record<string, unknown>>; summary?: Record<string, unknown> }> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<{
    list?: Array<Record<string, unknown>>
    summary?: Record<string, unknown>
  }>>(analyticsPath('orders/chart'), { params }))
}

export function getTrafficRank(type: 'node' | 'user', startTime?: number, endTime?: number):
  Promise<Array<Record<string, unknown>>> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<Array<Record<string, unknown>>>>(
    analyticsPath('traffic/rank'), {
      params: {
        type,
        ...(startTime !== undefined ? { start_time: startTime } : {}),
        ...(endTime !== undefined ? { end_time: endTime } : {}),
      },
    },
  ))
}

export function getAnalyticsRanking(type: 'server_traffic_rank' | 'user_consumption_rank' | 'invite_rank',
  limit = 20, startTime?: number, endTime?: number) {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<Array<Record<string, unknown>>>>(
    analyticsPath('rankings'), {
      params: {
        type, limit,
        ...(startTime !== undefined ? { start_time: startTime } : {}),
        ...(endTime !== undefined ? { end_time: endTime } : {}),
      },
    },
  ))
}

export async function getUserTrafficStats(userId: number, page = 1, perPage = 20) {
  if (!Number.isSafeInteger(userId) || userId < 1) throw new Error('Invalid user ID')
  const { data: envelope } = await nativeApiClient.get<NativeApiEnvelope<Array<Record<string, unknown>>>>(
    analyticsPath('users/' + userId + '/traffic'),
    { params: { page, per_page: perPage } },
  )
  if (!envelope?.request_id || !Array.isArray(envelope.data) ||
      !envelope.meta || !Number.isInteger(envelope.meta.total) ||
      !Number.isInteger(envelope.meta.last_page)) {
    throw new Error('Invalid native user traffic report')
  }
  return { data: envelope.data, total: envelope.meta.total,
    current_page: envelope.meta.page, per_page: envelope.meta.per_page,
    last_page: envelope.meta.last_page } as Paged<Record<string, unknown>>
}

export async function getAuditLogs(params: {
  current?: number
  page_size?: number
  action?: string
  admin_id?: number
  keyword?: string
} = {}) {
  const { current = 1, page_size = 20, ...filters } = params
  const { data: envelope } = await nativeApiClient.get<NativeApiEnvelope<AuditLog[]>>(
    nativeAdminPath('audit-logs'),
    { params: { page: current, per_page: page_size, ...filters } },
  )
  if (!envelope?.request_id || !Array.isArray(envelope.data) ||
      !envelope.meta || !Number.isInteger(envelope.meta.total) ||
      !Number.isInteger(envelope.meta.last_page)) {
    throw new Error('Invalid native administrator audit response')
  }
  return {
    data: envelope.data,
    total: envelope.meta.total,
    current_page: envelope.meta.page,
    per_page: envelope.meta.per_page,
    last_page: envelope.meta.last_page,
  } as Paged<AuditLog>
}
