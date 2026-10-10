import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import { fetchGuestConfig, fetchUserCommConfig } from './comm'

const oldNative = nativeApi.defaults.adapter
const oldLegacy = api.defaults.adapter
let seen: Array<{ method?: string; url?: string; baseURL?: string }> = []
beforeEach(() => {
  seen = []
  localStorage.clear()
  saveAuthData('Bearer test-site-config')
  nativeApi.defaults.adapter = async config => {
    seen.push(config)
    const data = config.url === '/public/site-config'
      ? { app_name: 'TXBoard', is_captcha: 1, captcha_type: 'turnstile' }
      : { currency: 'CNY', invite_enable: 1 }
    return { data: { data, request_id: 'site-config-trace' },
      status: 200, statusText: 'OK', headers: {}, config } as never
  }
  api.defaults.adapter = async () => { throw new Error('legacy site config called') }
})
afterEach(() => {
  nativeApi.defaults.adapter = oldNative
  api.defaults.adapter = oldLegacy
  localStorage.clear()
})
describe('LR-11 shared site config projections', () => {
  it('reads public guest feature and Captcha settings over native TXAPI', async () => {
    expect(await fetchGuestConfig()).toMatchObject({ app_name: 'TXBoard', is_captcha: 1 })
    expect(seen[0]).toMatchObject({ method: 'get', url: '/public/site-config', baseURL: '/txapi' })
  })
  it('reads authenticated user feature settings over native TXAPI', async () => {
    expect(await fetchUserCommConfig()).toMatchObject({ currency: 'CNY', invite_enable: 1 })
    expect(seen[0]).toMatchObject({ method: 'get', url: '/me/site-config', baseURL: '/txapi' })
  })
})
