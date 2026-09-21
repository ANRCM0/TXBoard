import { type AxiosError } from 'axios'
import { toast } from 'sonner'
import { apiClient } from './client'
import { unwrap } from '../lib/api'

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
}) {
  const { data } = await apiClient.post<CouponPageResult>('/coupon/fetch', params)
  return data
}

export async function saveCoupon(payload: CouponPayload) {
  const { data } = await apiClient.post('/coupon/generate', payload)
  return unwrap(data)
}

export async function toggleCoupon(id: number) {
  const { data } = await apiClient.post('/coupon/show', { id })
  return unwrap(data)
}

export async function deleteCoupon(id: number) {
  const { data } = await apiClient.post('/coupon/drop', { id })
  return unwrap(data)
}

export async function generateCouponsCsv(payload: CouponPayload & { generate_count: number }) {
  try {
    const response = await apiClient.post('/coupon/generate', payload, {
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
        const message = (JSON.parse(await data.text()) as { message?: string }).message
        if (message) toast.error(message)
      } catch {
        void 0
      }
    }
    throw error
  }
}
