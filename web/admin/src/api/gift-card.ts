import { apiClient, nativeApiClient, nativeAdminPath, type NativeApiEnvelope, unwrapNative } from './client'
import { unwrap } from '../lib/api'

export type GiftTemplate = {
  id: number
  name: string
  description?: string | null
  type: number
  type_name?: string
  status?: boolean | number
  rewards?: Record<string, unknown>
  conditions?: Record<string, unknown> | null
  limits?: Record<string, unknown> | null
  special_config?: Record<string, unknown> | null
  icon?: string | null
  background_image?: string | null
  theme_color?: string | null
  sort?: number
  codes_count?: number
  used_count?: number
  created_at?: number
  updated_at?: number
}

export type GiftCode = {
  id: number
  template_id?: number
  template_name?: string
  code?: string
  batch_id?: string
  status?: number
  status_name?: string
  user_id?: number | null
  user_email?: string | null
  used_at?: number | null
  expires_at?: number | null
  usage_count?: number
  max_usage?: number
  created_at?: number
}

export type GiftUsage = {
  id: number
  code?: string
  template_name?: string
  user_email?: string
  invite_user_email?: string | null
  rewards_given?: Record<string, unknown>
  invite_rewards?: Record<string, unknown> | null
  multiplier_applied?: number | null
  created_at?: number
}

export type GiftStats = {
  total_stats?: {
    templates_count?: number
    active_templates_count?: number
    codes_count?: number
    used_codes_count?: number
    usages_count?: number
  }
  daily_usages?: Array<{ date?: string; count?: number }>
  type_stats?: Array<{ template_name?: string; type_name?: string; count?: number }>
}

export type GiftPage<T> = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: T[]
}

export async function getGiftTypes() {
  return unwrapNative<Record<string, string>>(nativeApiClient.get(nativeAdminPath('gift-cards') + '/types'))
}

export async function getGiftTemplates(params: { page?: number; per_page?: number; type?: number; status?: number } = {}) {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<GiftTemplate[]>>(nativeAdminPath('gift-cards') + '/templates', { params })
  return { ...(data.meta || { page: 1, per_page: 15, total: 0, last_page: 1 }), current_page: data.meta?.page ?? 1, data: data.data } as GiftPage<GiftTemplate>
}

export async function createGiftTemplate(payload: Omit<GiftTemplate, 'id'>) {
  const { data } = await apiClient.post('/gift-card/create-template', payload)
  return unwrap(data)
}

export async function updateGiftTemplate(id: number, payload: Partial<GiftTemplate>) {
  const { data } = await apiClient.post('/gift-card/update-template', { id, ...payload })
  return unwrap(data)
}

export async function deleteGiftTemplate(id: number) {
  const { data } = await apiClient.post('/gift-card/delete-template', { id })
  return unwrap(data)
}

export async function generateGiftCodes(payload: {
  template_id: number
  count: number
  prefix?: string
  expires_hours?: number
  max_usage?: number
}) {
  const { data } = await apiClient.post('/gift-card/generate-codes', payload)
  return unwrap<{ batch_id?: string; count?: number }>(data)
}

export async function getGiftCodes(params: {
  page?: number
  per_page?: number
  template_id?: number
  batch_id?: string
  status?: number
} = {}) {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<GiftCode[]>>(nativeAdminPath('gift-cards') + '/codes', { params })
  return { ...(data.meta || { page: 1, per_page: 15, total: 0, last_page: 1 }), current_page: data.meta?.page ?? 1, data: data.data } as GiftPage<GiftCode>
}

export async function toggleGiftCode(id: number, action: 'disable' | 'enable') {
  const { data } = await apiClient.post('/gift-card/toggle-code', { id, action })
  return unwrap(data)
}

export async function updateGiftCode(id: number, payload: { expires_at?: number | null; max_usage?: number; status?: number }) {
  const { data } = await apiClient.post('/gift-card/update-code', { id, ...payload })
  return unwrap(data)
}

export async function deleteGiftCode(id: number) {
  return unwrapNative(nativeApiClient.delete(nativeAdminPath('gift-cards') + `/codes/${id}`))
}

export async function getGiftUsages(params: { page?: number; per_page?: number; template_id?: number; user_id?: number } = {}) {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<GiftUsage[]>>(nativeAdminPath('gift-cards') + '/usages', { params })
  return { ...(data.meta || { page: 1, per_page: 15, total: 0, last_page: 1 }), current_page: data.meta?.page ?? 1, data: data.data } as GiftPage<GiftUsage>
}

export async function getGiftStatistics(startDate?: string, endDate?: string) {
  const { data } = await apiClient.get('/gift-card/statistics', {
    params: {
      ...(startDate ? { start_date: startDate } : {}),
      ...(endDate ? { end_date: endDate } : {}),
    },
  })
  return unwrap<GiftStats>(data) || {}
}
