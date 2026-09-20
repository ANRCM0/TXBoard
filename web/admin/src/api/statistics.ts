import { apiClient } from './client'
import { unwrap } from '../lib/api'

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

export async function getDashboardStats() {
  const { data } = await apiClient.get('/stat/getStats')
  return unwrap<DashboardStats>(data) || {}
}

export async function getOrderChart(params: { start_date?: string; end_date?: string } = {}) {
  const { data } = await apiClient.get('/stat/getOrder', { params })
  return unwrap<{ list?: Array<Record<string, unknown>>; summary?: Record<string, unknown> }>(data) || {}
}

export async function getTrafficRank(type: 'node' | 'user', startTime?: number, endTime?: number) {
  const { data } = await apiClient.get('/stat/getTrafficRank', {
    params: {
      type,
      ...(startTime ? { start_time: startTime } : {}),
      ...(endTime ? { end_time: endTime } : {}),
    },
  })
  return unwrap<Array<Record<string, unknown>>>(data) || []
}

export async function getAuditLogs(params: {
  current?: number
  page_size?: number
  action?: string
  admin_id?: number
  keyword?: string
} = {}) {
  const { data } = await apiClient.get('/system/getAuditLog', { params })
  return data as Paged<AuditLog>
}
