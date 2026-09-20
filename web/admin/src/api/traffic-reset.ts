import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type TrafficResetLog = {
  id: number
  user_id?: number
  user_email?: string
  reset_type?: string
  reset_type_name?: string
  reset_time?: number | string
  old_traffic?: { upload?: number; download?: number; total?: number; formatted?: string }
  new_traffic?: { upload?: number; download?: number; total?: number; formatted?: string }
  trigger_source?: string
  trigger_source_name?: string
  metadata?: Record<string, unknown> | null
  created_at?: number
}

export type TrafficResetPage = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: TrafficResetLog[]
}

export type TrafficResetStats = {
  total_resets?: number
  auto_resets?: number
  manual_resets?: number
  cron_resets?: number
  order_resets?: number
  gift_card_resets?: number
}

export async function getTrafficResetLogs(params: {
  page?: number
  per_page?: number
  user_id?: number
  user_email?: string
  reset_type?: string
  trigger_source?: string
  start_date?: string
  end_date?: string
} = {}) {
  const { data } = await apiClient.get('/traffic-reset/logs', { params })
  return data as TrafficResetPage
}

export async function getTrafficResetStats(days = 30) {
  const { data } = await apiClient.get('/traffic-reset/stats', { params: { days } })
  return unwrap<TrafficResetStats>(data) || {}
}

export async function resetUserTraffic(userId: number, reason?: string) {
  const { data } = await apiClient.post('/traffic-reset/reset-user', {
    user_id: userId,
    ...(reason?.trim() ? { reason: reason.trim() } : {}),
  })
  return unwrap(data)
}

export async function getUserTrafficResetHistory(userId: number, limit = 10) {
  const { data } = await apiClient.get(`/traffic-reset/user/${userId}/history`, { params: { limit } })
  return unwrap<{
    user?: {
      id?: number
      email?: string
      reset_count?: number
      last_reset_at?: number | null
      next_reset_at?: number | null
    }
    history?: TrafficResetLog[]
  }>(data)
}
