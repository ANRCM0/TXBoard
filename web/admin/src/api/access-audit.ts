import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'

export type AuditRule = {
  id: number
  name: string
  match_type: 'domain' | 'domain_suffix' | 'keyword' | 'ip_cidr'
  match_value: string
  enabled: boolean
}
export type AuditEvent = {
  id: number
  server_id: number
  user_id: number
  target: string
  target_ip: string | null
  source_ip: string | null
  matched: boolean
  created_at: number
}
export type AuditEventPage = {
  rows: AuditEvent[]
  total: number
  page: number
  last_page: number
}
export async function getAccessAuditRules() {
  return (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<AuditRule[]>>(
    nativeAdminPath('access-audit') + '/rules',
  ))) || []
}
export function saveAccessAuditRule(data: Omit<AuditRule, 'id'> & { id?: number }) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ id: number; ok: boolean }>>(
    nativeAdminPath('access-audit') + '/rules', data,
  ))
}
export function deleteAccessAuditRule(id: number) {
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('access-audit') + '/rules/' + id,
  ))
}
export async function getAccessAuditEvents(filters: { page: number; keyword?: string; server_id?: number; user_id?: number; matched?: boolean }): Promise<AuditEventPage> {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<AuditEvent[]>>(
    nativeAdminPath('access-audit') + '/events', { params: { ...filters, per_page: 30 } },
  )
  if (!data?.request_id || !Array.isArray(data.data)) throw new Error('Invalid native audit response')
  return { rows: data.data, total: data.meta?.total || 0, page: data.meta?.page || 1, last_page: data.meta?.last_page || 1 }
}
