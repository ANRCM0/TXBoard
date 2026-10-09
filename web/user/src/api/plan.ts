import { nativeApi, nativeRequest } from './client'

// Native plan DTO: keep the backend's canonical period names, integer minor
// currency units and byte units. UI formatting belongs to the view.
export const PERIODS = [
  'monthly',
  'quarterly',
  'half_yearly',
  'yearly',
  'two_yearly',
  'three_yearly',
  'onetime',
  'reset_traffic',
] as const

export type PlanPeriod = typeof PERIODS[number]

export type PlanPrice = {
  period: PlanPeriod
  amount_minor: number
}

export type PlanItem = {
  id: number
  name: string
  content: string
  tags: string[]
  traffic_limit_bytes: number
  speed_limit_mbps: number | null
  device_limit: number | null
  capacity_limit: number | null
  reset_traffic_method: number | null
  prices: PlanPrice[]
  renewable: boolean
}

const validPeriods: ReadonlySet<string> = new Set(PERIODS)

function validatePlan(input: PlanItem): PlanItem {
  if (!input || !Number.isSafeInteger(input.id) || input.id < 1 ||
      typeof input.name !== 'string' ||
      typeof input.content !== 'string' ||
      !Array.isArray(input.tags) || !input.tags.every(tag => typeof tag === 'string') ||
      !Number.isSafeInteger(input.traffic_limit_bytes) ||
      input.traffic_limit_bytes < 0 || !Array.isArray(input.prices) ||
      typeof input.renewable !== 'boolean' ||
      (input.capacity_limit !== null && !Number.isSafeInteger(input.capacity_limit))) {
    throw new Error('Invalid TXAPI plan response')
  }
  const seen = new Set<string>()
  for (const price of input.prices) {
    if (!price || !validPeriods.has(price.period) || seen.has(price.period) ||
        !Number.isSafeInteger(price.amount_minor) || price.amount_minor <= 0) {
      throw new Error('Invalid TXAPI plan price')
    }
    seen.add(price.period)
  }
  return input
}

export async function fetchPlans(): Promise<PlanItem[]> {
  const plans = await nativeRequest<PlanItem[]>(nativeApi.get('/plans'))
  if (!Array.isArray(plans)) throw new Error('Invalid TXAPI plan list')
  return plans.map(validatePlan)
}

export async function fetchPlanById(id: number): Promise<PlanItem> {
  if (!Number.isSafeInteger(id) || id < 1) {
    throw new Error('Invalid plan ID')
  }
  return validatePlan(await nativeRequest<PlanItem>(nativeApi.get('/plans/' + id)))
}
