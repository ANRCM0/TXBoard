import { nativeApi, nativeRequest } from './client'

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

type NativePlanPrice = {
  period: string
  amount_minor: number
}

type NativePlan = {
  id: number
  name: string
  content: string
  tags: string[]
  traffic_limit_bytes: number
  speed_limit_mbps: number | null
  device_limit: number | null
  capacity_limit: number | null
  reset_traffic_method: number | null
  prices: NativePlanPrice[]
  renewable: boolean
}

type PriceKey =
  | 'month_price'
  | 'quarter_price'
  | 'half_year_price'
  | 'year_price'
  | 'two_year_price'
  | 'three_year_price'
  | 'onetime_price'
  | 'reset_price'

const NATIVE_PERIODS: Record<string, PriceKey> = {
  monthly: 'month_price',
  quarterly: 'quarter_price',
  half_yearly: 'half_year_price',
  yearly: 'year_price',
  two_yearly: 'two_year_price',
  three_yearly: 'three_year_price',
  onetime: 'onetime_price',
  reset_traffic: 'reset_price',
}

/** Presentation adapter only: TXAPI never returns Xboard price field names. */
function toPagePlan(input: NativePlan): PlanItem {
  if (!input || !Number.isSafeInteger(input.id) || input.id < 1 ||
      typeof input.name !== 'string' ||
      typeof input.content !== 'string' ||
      !Array.isArray(input.tags) ||
      !Number.isSafeInteger(input.traffic_limit_bytes) ||
      input.traffic_limit_bytes < 0 || !Array.isArray(input.prices) ||
      typeof input.renewable !== 'boolean') {
    throw new Error('Invalid TXAPI plan response')
  }

  const plan: PlanItem = {
    id: input.id,
    name: input.name,
    content: input.content,
    tags: input.tags,
    transfer_enable: input.traffic_limit_bytes / 1073741824,
    month_price: null,
    quarter_price: null,
    half_year_price: null,
    year_price: null,
    two_year_price: null,
    three_year_price: null,
    onetime_price: null,
    reset_price: null,
    capacity_limit: input.capacity_limit,
    renew: input.renewable,
  }

  for (const price of input.prices) {
    const key = NATIVE_PERIODS[price.period]
    if (!key || !Number.isSafeInteger(price.amount_minor) || price.amount_minor <= 0) {
      throw new Error('Invalid TXAPI plan price')
    }
    plan[key] = price.amount_minor
  }
  return plan
}

export async function fetchPlans(): Promise<PlanItem[]> {
  const plans = await nativeRequest<NativePlan[]>(nativeApi.get('/plans'))
  if (!Array.isArray(plans)) throw new Error('Invalid TXAPI plan list')
  return plans.map(toPagePlan)
}

export async function fetchPlanById(id: number): Promise<PlanItem> {
  if (!Number.isSafeInteger(id) || id < 1) {
    throw new Error('Invalid plan ID')
  }
  const plan = await nativeRequest<NativePlan>(nativeApi.get('/plans/' + id))
  return toPagePlan(plan)
}
