import { nativeApiClient, nativeAdminPath, type NativeApiEnvelope } from './client'

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

type PageMeta = { page: number; per_page: number; last_page: number; total: number }

function unwrapList<T>(payload: NativeApiEnvelope<T[]>, name: string) {
  const meta = payload?.meta
  if (!payload?.request_id || !Array.isArray(payload.data) || !meta ||
      !Number.isSafeInteger(meta.total) || !Number.isSafeInteger(meta.page) ||
      !Number.isSafeInteger(meta.per_page) || !Number.isSafeInteger(meta.last_page)) {
    throw new Error('Invalid native ' + name + ' response')
  }
  return {
    data: payload.data,
    total: meta.total,
    current_page: meta.page,
    per_page: meta.per_page,
    last_page: meta.last_page,
  }
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
  const { data } = await nativeApiClient.get<NativeApiEnvelope<TrafficResetLog[]>>(
    nativeAdminPath('traffic-resets'), { params },
  )
  return unwrapList(data, 'traffic reset logs') satisfies TrafficResetPage
}

export async function getTrafficResetStats(days = 30) {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<TrafficResetStats>>(
    nativeAdminPath('traffic-resets') + '/stats', { params: { days } },
  )
  if (!data?.request_id || !data.data ||
      !Number.isSafeInteger(data.data.total_resets) ||
      !Number.isSafeInteger(data.data.manual_resets)) {
    throw new Error('Invalid native traffic reset statistics')
  }
  return data.data
}

function userResource(userId: number) {
  if (!Number.isSafeInteger(userId) || userId < 1) throw new Error('Invalid traffic reset user ID')
  return nativeAdminPath('traffic-resets') + '/users/' + userId
}

export async function resetUserTraffic(userId: number, reason?: string) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{
    user_id: number; email: string; reset_time: string; next_reset_at: number | null
  }>>(userResource(userId) + '/reset', reason?.trim() ? { reason: reason.trim() } : {})
  if (!data?.request_id || data.data?.user_id !== userId ||
      typeof data.data?.reset_time !== 'string') {
    throw new Error('Native traffic reset not acknowledged')
  }
  return data.data
}

export async function getUserTrafficResetHistory(userId: number, limit = 10) {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<{
    user: {
      id: number; email: string; reset_count: number;
      last_reset_at: number | null; next_reset_at: number | null
    }
    history: TrafficResetLog[]
  }>>(userResource(userId), { params: { limit } })
  if (!data?.request_id || data.data?.user?.id !== userId ||
      !Array.isArray(data.data.history)) {
    throw new Error('Invalid native user traffic reset history')
  }
  return data.data
}
