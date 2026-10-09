import { type AxiosError } from 'axios'
import { toast } from 'sonner'
import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'

export type CouponItem = {
  id: number
  name?: string
  code?: string
  type?: number
  value?: number
  limit_use?: number | null
  limit_use_with_user?: number | null
  limit_plan_ids?: number[] | null
  limit_period?: string[] | null
  show?: boolean | number
  started_at?: number
  ended_at?: number
  created_at?: number
  updated_at?: number
}

export type CouponPageResult = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: CouponItem[]
}

export type CouponPayload = {
  id?: number
  name: string
  code?: string
  type: number
  value: number
  limit_use?: number | null
  limit_use_with_user?: number | null
  limit_plan_ids?: number[]
  limit_period?: string[]
  started_at?: number | null
  ended_at?: number | null
  generate_count?: number
}


export async function getCoupons(params: {
  current?: number
  pageSize?: number
  filter?: Array<{ id: string; value: string | number | number[] }>
}): Promise<CouponPageResult> {
  const filters = params.filter || []
  const code = filters.find(filter => filter.id === 'code')?.value
  const type = filters.find(filter => filter.id === 'type')?.value
  const { data: response } = await nativeApiClient.get<NativeApiEnvelope<CouponItem[]>>(
    nativeAdminPath('coupons'),
    { params: {
      page: params.current || 1,
      per_page: params.pageSize || 10,
      ...(code !== undefined && code !== '' ? { code } : {}),
      ...(type !== undefined && type !== '' ? { type } : {}),
    } },
  )
  if (!response?.request_id || !Array.isArray(response.data) ||
    !response.meta || !Number.isInteger(response.meta.total) ||
    !Number.isInteger(response.meta.last_page)) {
    throw new Error('Invalid native coupons page response')
  }
  return {
    data: response.data, total: response.meta.total,
    current_page: response.meta.page, per_page: response.meta.per_page,
    last_page: response.meta.last_page,
  }
}

export async function saveCoupon(payload: CouponPayload) {
  const base = nativeAdminPath('coupons')
  if (payload.id) {
    return unwrapNative(nativeApiClient.put<NativeApiEnvelope<{ id: number }>>(base + '/' + payload.id, payload))
  }
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ id: number }>>(base, payload))
}

export async function toggleCoupon(id: number) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid coupon ID')
  return unwrapNative(nativeApiClient.patch<NativeApiEnvelope<{ show: boolean }>>(
    nativeAdminPath('coupons') + '/' + id + '/toggle',
  ))
}

export async function deleteCoupon(id: number) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid coupon ID')
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('coupons') + '/' + id,
  ))
}

export async function generateCouponsCsv(payload: CouponPayload & { generate_count: number }) {
  try {
    const response = await nativeApiClient.post(nativeAdminPath('coupons') + '/export', payload, {
      responseType: 'blob',
    })
    const blob = response.data as Blob
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'coupons.csv'
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (error) {
    const data = (error as AxiosError).response?.data
    if (data instanceof Blob) {
      try {
        const message = (JSON.parse(await data.text()) as { message?: string; error?: { message?: string } })
        const display = message.message || message.error?.message
        if (display) toast.error(display)
      } catch {
        void 0
      }
    }
    throw error
  }
}
