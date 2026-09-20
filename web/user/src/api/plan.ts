import { api, request } from './client'

export type PlanItem = {
  id: number
  name: string
  content?: string
  transfer_enable: number
  month_price: number | null
  quarter_price: number | null
  half_year_price: number | null
  year_price: number | null
  two_year_price?: number | null
  three_year_price?: number | null
  onetime_price: number | null
  reset_price: number | null
  capacity_limit?: number | string | null
  show?: boolean | number
  sell?: boolean
  renew?: boolean
  tags?: string[]
}

export const PERIODS = [
  ['month_price', '月付'],
  ['quarter_price', '季付'],
  ['half_year_price', '半年付'],
  ['year_price', '年付'],
  ['two_year_price', '两年付'],
  ['three_year_price', '三年付'],
  ['onetime_price', '一次性'],
  ['reset_price', '重置流量'],
] as const

export async function fetchPlans() {
  return request<PlanItem[]>(api.get('/user/plan/fetch'))
}

export async function fetchPlanById(id: number) {
  return request<PlanItem>(api.get('/user/plan/fetch', { params: { id } }))
}
