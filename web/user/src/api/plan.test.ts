import type { AxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import { fetchPlanById, fetchPlans } from './plan'

type Seen = AxiosRequestConfig & { headers: Record<string, string> }

const sample = {
  id: 8,
  name: 'TXPlan',
  content: '2 GB plan',
  tags: ['fast', 'popular'],
  traffic_limit_bytes: 2 * 1073741824,
  speed_limit_mbps: null,
  device_limit: 2,
  capacity_limit: null,
  reset_traffic_method: 1,
  prices: [
    { period: 'monthly', amount_minor: 1250 },
    { period: 'yearly', amount_minor: 12000 },
    { period: 'reset_traffic', amount_minor: 300 },
  ],
  renewable: true,
}
let seen: Seen[] = []
let responder: (config: AxiosRequestConfig) => unknown
const legacyAdapter = api.defaults.adapter
const nativeAdapter = nativeApi.defaults.adapter

beforeEach(() => {
  seen = []
  localStorage.clear()
  saveAuthData('old-existing-user-token')
  responder = () => ({ data: [sample], request_id: 'trace-1' })
  nativeApi.defaults.adapter = async config => {
    seen.push(config as Seen)
    return {
      data: responder(config),
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
    } as never
  }
  api.defaults.adapter = async () => {
    throw new Error('Legacy plan fetch must not be called')
  }
})

afterEach(() => {
  api.defaults.adapter = legacyAdapter
  nativeApi.defaults.adapter = nativeAdapter
  localStorage.clear()
})

describe('P2 native subscription client', () => {
  it('fetches visible plans from native catalog without legacy API calls', async () => {
    const plans = await fetchPlans()
    expect(seen).toHaveLength(1)
    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/plans')
    expect(seen[0].baseURL).toBe('/txapi')
    expect(String(seen[0].headers.Authorization)).toBe('Bearer old-existing-user-token')
    expect(plans).toHaveLength(1)
    expect(plans[0]).toMatchObject({
      id: 8,
      name: 'TXPlan',
      content: '2 GB plan',
      tags: ['fast', 'popular'],
      transfer_enable: 2,
      capacity_limit: null,
      month_price: 1250,
      year_price: 12000,
      reset_price: 300,
      two_year_price: null,
      renew: true,
    })
  })

  it('fetches authenticated renewal-aware plan detail for purchase page', async () => {
    responder = () => ({ data: sample, request_id: 'trace-2' })
    const plan = await fetchPlanById(8)
    expect(seen[0].url).toBe('/plans/8')
    expect(plan.name).toBe('TXPlan')
    expect(plan.month_price).toBe(1250)
    expect(plan.transfer_enable).toBe(2)
  })

  it('rejects missing pagination data, malformed prices and invalid plan IDs', async () => {
    responder = () => ({ data: { invalid: true }, request_id: 'trace-1' })
    await expect(fetchPlans()).rejects.toThrow('Invalid TXAPI plan list')

    responder = () => ({ data: [{ ...sample, prices: [
      { period: 'monthly', amount_minor: '12.50' },
    ] }], request_id: 'trace-1' })
    await expect(fetchPlans()).rejects.toThrow('Invalid TXAPI plan price')

    responder = () => ({ data: [sample] })
    await expect(fetchPlans()).rejects.toThrow('Invalid TXAPI response')

    await expect(fetchPlanById(0)).rejects.toThrow('Invalid plan ID')
  })
})
