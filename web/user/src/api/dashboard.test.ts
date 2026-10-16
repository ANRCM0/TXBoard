import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import { fetchSubscribe } from './subscribe'
import { fetchUserStat } from './user'

const savedNative = nativeApi.defaults.adapter
const savedLegacy = api.defaults.adapter
let seen: Array<{ url?: string; method?: string; baseURL?: string }> = []
beforeEach(() => {
  seen = []
  localStorage.clear()
  saveAuthData('Bearer dashboard-test')
  nativeApi.defaults.adapter = async config => {
    seen.push(config)
    const data = config.url === '/me/subscription'
      ? {
          schema: 'core-entitlement/v1', authorized: true,
          reason: 'ENTITLEMENT_GRANTED', subscription_id: '7',
          credential_status: 'provider_managed', upload_bytes: '111',
          download_bytes: '222', quota_bytes: '1000',
          device_limit: null, speed_limit_mbps: null,
          expires_at: null, next_reset_at: null,
        }
      : { unpaid_orders: 2, open_tickets: 3, invited_users: 4 }
    return { data: { data, request_id: 'dashboard-trace' },
      status: 200, statusText: 'OK', headers: {}, config } as never
  }
  api.defaults.adapter = async () => {
    throw new Error('Legacy dashboard endpoints must not be called')
  }
})
afterEach(() => {
  nativeApi.defaults.adapter = savedNative
  api.defaults.adapter = savedLegacy
  localStorage.clear()
})
describe('LR-10 native dashboard and subscription', () => {
  it('reads private subscription without raw token field', async () => {
    const item = await fetchSubscribe()
    expect(item).not.toHaveProperty('subscribe_url')
    expect(item).not.toHaveProperty('token')
    expect(item.u).toBe(111)
    expect(item.subscription_id).toBe('7')
    expect(seen[0]).toMatchObject({ url: '/me/subscription', baseURL: '/txapi', method: 'get' })
  })
  it('maps named stat counters into existing dashboard order', async () => {
    expect(await fetchUserStat()).toEqual([2, 3, 4])
    expect(seen[0]).toMatchObject({ url: '/me/dashboard-stats', baseURL: '/txapi', method: 'get' })
  })
})
