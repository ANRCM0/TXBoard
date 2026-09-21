import { pluginApiClient } from '../../api/client'

export type AuditStats = {
  rules_total: number
  rules_enabled: number
  reports_total: number
  reports_today: number
  bans_total: number
  bans_today: number
  logs_total: number
  logs_today: number
  logs_yesterday: number
  reports_yesterday: number
  bans_yesterday: number
  trend_24h: number[]
  nodes_total: number
  nodes_online: number
}

export type AuditNode = {
  node_id: number
  node_name: string
  last_report_at: number
  silent_minutes: number | null
  last_events_count: number
  total_reports: number
  total_events: number
  total_matched: number
  total_banned: number
}

export type AuditLog = {
  id: number
  node_id: number
  node_name: string
  user_id: number
  user_email: string
  target: string
  target_ip?: string
  source_ip?: string
  matched: boolean
  created_at: number
}

export type AuditLogPage = {
  total: number
  page: number
  per_page: number
  pages: number
  list: AuditLog[]
}

export type AuditAnalytics = {
  range: '1h' | '24h' | '7d' | '30d'
  node_id: number | null
  summary: {
    events: number
    matched: number
    match_rate: number | null
    bans: number | null
    active_users: number
  }
  trend: Array<{ time: number; events: number; matched: number; match_rate: number | null }>
  nodes: Array<{ node_id: number; node_name: string; events: number; matched: number; match_rate: number | null }>
  rules: Array<{ rule_id: number; rule_name: string; hits: number; users: number; bans: number | null }>
  top_users: Array<{ user_id: number; user_email: string; events: number; matched: number }>
  top_targets: Array<{ target: string; events: number; matched: number }>
  coverage: {
    trend_source: string
    aggregate_start: number | null
    ranking_from: number
    detail_retention_days: number
    ranking_limited_to_24h: boolean
  }
}

async function getData<T>(path: string, params?: Record<string, unknown>) {
  const { data } = await pluginApiClient.get(path, { params })
  return (data?.data ?? data) as T
}

async function postData<T>(path: string, payload: Record<string, unknown> = {}) {
  const { data } = await pluginApiClient.post(path, payload)
  return (data?.data ?? data) as T
}

export const getAuditStats = () => getData<AuditStats>('/plugin/access-audit/stats')
export const getAuditNodes = () => getData<AuditNode[]>('/plugin/access-audit/nodes')
export const getAuditLogs = (params: { page: number; keyword?: string; matched?: string }) =>
  getData<AuditLogPage>('/plugin/access-audit/logs', params)
export const clearAuditLogs = () =>
  postData<{ deleted: number; message: string }>('/plugin/access-audit/logs/clear')
export const auditUserAction = (action: 'ban' | 'unban', email: string, reason: string) =>
  postData<{ message: string }>(`/plugin/access-audit/${action}`, { email, reason })
export const getAuditAnalytics = (range: '1h' | '24h' | '7d' | '30d', nodeId?: number) =>
  getData<AuditAnalytics>('/plugin/access-audit/analytics', {
    range,
    ...(nodeId ? { node_id: nodeId } : {}),
  })
