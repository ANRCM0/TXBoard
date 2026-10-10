import type { AxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import { checkOrderStatus, fetchFirstBlockingOrder, fetchOrderDetail, fetchOrders } from './order'

const currentTime = '2026-10-09T02:03:04+00:00'
const nativeOrder = {
  id: 8,
  trade_no: 'TX-PAGINATED-ORDER',
  plan_id: 5,
  plan: { id: 5, name: 'Plan A' },
  period: 'monthly',
  type: 1,
  status: 3,
  amount_minor: 1234,
  created_at: currentTime,
  paid_at: null,
}
let calls: AxiosRequestConfig[] = []
let payload: (config: AxiosRequestConfig) => unknown
const oldNativeAdapter = nativeApi.defaults.adapter
const oldLegacyAdapter = api.defaults.adapter

beforeEach(() => {
  calls = []
  localStorage.clear()
  saveAuthData('Bearer test-session')
  payload = () => ({
    data: [nativeOrder],
    meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
    request_id: 'native-trace',
  })
  nativeApi.defaults.adapter = async config => {
    calls.push(config)
    return { data: payload(config), status: 200, statusText: 'OK', headers: {}, config } as never
  }
  // The list must not pull a full collection from the legacy endpoint.
  api.defaults.adapter = async () => {
    throw new Error('legacy order/fetch must not be called')
  }
})

afterEach(() => {
  nativeApi.defaults.adapter = oldNativeAdapter
  api.defaults.adapter = oldLegacyAdapter
  localStorage.clear()
})

describe('P1-B native orders read migration', () => {
  it('uses native SQL pagination and adapts canonical units/times to existing UI', async () => {
    payload = () => ({
      data: [nativeOrder],
      meta: { page: 2, per_page: 1, total: 25, last_page: 25 },
      request_id: 'native-trace',
    })
    const result = await fetchOrders({ status: 3, page: 2, pageSize: 1 })
    expect(calls).toHaveLength(1)
    expect(calls[0].baseURL).toBe('/txapi')
    expect(calls[0].url).toBe('/orders')
    expect(calls[0].params).toEqual({ page: 2, per_page: 1, status: 3 })
    expect(String((calls[0].headers as Record<string,string>).Authorization)).toBe('Bearer test-session')
    expect(result.total).toBe(25)
    expect(result.current_page).toBe(2)
    expect(result.page_size).toBe(1)
    expect(result.data[0]).toMatchObject({
      trade_no: 'TX-PAGINATED-ORDER',
      plan: { name: 'Plan A' },
      period: 'month_price',
      total_amount: 1234,
      created_at: Date.parse(currentTime) / 1000,
      paid_at: null,
    })
  })

  it('looks for a blocking order with two bounded server queries', async () => {
    payload = config => ({
      data: (config.params as { status: number }).status === 0 ? [] : [nativeOrder],
      meta: { page: 1, per_page: 1, total: 1, last_page: 1 },
      request_id: 'native-trace',
    })
    const blocking = await fetchFirstBlockingOrder()
    expect(blocking?.trade_no).toBe('TX-PAGINATED-ORDER')
    expect(calls.map(call => (call.params as { status: number }).status)).toEqual([0, 1])
    expect(calls.every(call => (call.params as { per_page: number }).per_page === 1)).toBe(true)
  })

  it('does not treat missing native pagination metadata as success', async () => {
    payload = () => ({ data: [nativeOrder], request_id: 'native-trace' })
    await expect(fetchOrders()).rejects.toThrow('Invalid TXAPI order pagination response')
  })

  it('rejects malformed dates rather than showing a fake timestamp', async () => {
    payload = () => ({
      data: [{ ...nativeOrder, created_at: 'invalid-date' }],
      meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
      request_id: 'native-trace',
    })
    await expect(fetchOrders()).rejects.toThrow('Invalid TXAPI order timestamp')
  })
})

describe('LR-02 native order status polling', () => {
  it('uses the owner-scoped native order endpoint instead of legacy order/check', async () => {
    payload = () => ({ data: { ...nativeOrder, status: 1 }, request_id: 'native-trace' })
    await expect(checkOrderStatus('TX-PAGINATED-ORDER')).resolves.toBe(1)
    expect(calls).toHaveLength(1)
    expect(calls[0].baseURL).toBe('/txapi')
    expect(calls[0].url).toBe('/orders/TX-PAGINATED-ORDER')
    expect(String((calls[0].headers as Record<string, string>).Authorization)).toBe('Bearer test-session')
  })

  it('rejects missing or malformed native status instead of faking a payment result', async () => {
    payload = () => ({ data: { status: '1' }, request_id: 'native-trace' })
    await expect(checkOrderStatus('a')).rejects.toThrow('Invalid TXAPI order status')
    payload = () => ({ data: { status: 9 }, request_id: 'native-trace' })
    await expect(checkOrderStatus('a')).rejects.toThrow('Invalid TXAPI order status')
  })

  it('encodes the order reference as a single route segment', async () => {
    payload = () => ({ data: { status: 0 }, request_id: 'native-trace' })
    await checkOrderStatus('a/b')
    expect(calls[0].url).toBe('/orders/a%2Fb')
  })
})

describe('LR-05 native order detail', () => {
  it('reads native private detail without invoking the V1 order detail route', async () => {
    payload = () => ({ data: {
      ...nativeOrder, payment_id: 7, balance_amount_minor: 350, discount_amount_minor: 200,
      plan: { id: 5, name: 'Plan A', traffic_limit_bytes: 2 * 1073741824 },
    }, request_id: 'detail-trace' })
    const order = await fetchOrderDetail('my-trade')
    expect(calls).toHaveLength(1)
    expect(calls[0].url).toBe('/orders/my-trade/detail')
    expect(calls[0].baseURL).toBe('/txapi')
    expect(order).toMatchObject({ trade_no: 'TX-PAGINATED-ORDER',
      balance_amount: 350, discount_amount: 200, payment_id: 7,
      plan: { name: 'Plan A', transfer_enable: 2 } })
  })
  it('rejects missing monetary breakdown and other invalid detail data', async () => {
    payload = () => ({ data: {
      ...nativeOrder, payment_id: null, balance_amount_minor: undefined,
      discount_amount_minor: 0, plan: { id: 5, name: 'A', traffic_limit_bytes: 0 },
    }, request_id: 'trace' })
    await expect(fetchOrderDetail('safe')).rejects.toThrow('Invalid TXAPI order detail')
  })
})
