import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import { fetchServers } from './server'

const originalNative = nativeApi.defaults.adapter
const originalLegacy = api.defaults.adapter
let seen: Array<{ url?: string; baseURL?: string; method?: string }> = []
let body: unknown
beforeEach(() => {
  seen = []
  localStorage.clear()
  saveAuthData('Bearer nodes-client')
  body = { data: [{ id: 7, type: 'vmess', name: 'Node 7', rate: 1.5,
    tags: ['fast'], is_online: true, last_check_at: 1790000000 }],
    request_id: 'node-list-trace' }
  nativeApi.defaults.adapter = async config => {
    seen.push(config)
    return { data: body, status: 200, statusText: 'OK', headers: {}, config } as never
  }
  api.defaults.adapter = async () => { throw new Error('legacy nodes called') }
})
afterEach(() => {
  nativeApi.defaults.adapter = originalNative
  api.defaults.adapter = originalLegacy
  localStorage.clear()
})
describe('LR-12 native user node listing', () => {
  it('fetches a whitelisted node list from native TXAPI', async () => {
    expect(await fetchServers()).toMatchObject([{ id: 7, name: 'Node 7', rate: 1.5 }])
    expect(seen[0]).toMatchObject({ url: '/me/nodes', baseURL: '/txapi', method: 'get' })
  })
  it('rejects malformed native response instead of silently showing no nodes', async () => {
    body = { data: { error: true }, request_id: 'node-list-trace' }
    await expect(fetchServers()).rejects.toThrow('Invalid TXAPI node list')
  })
})
