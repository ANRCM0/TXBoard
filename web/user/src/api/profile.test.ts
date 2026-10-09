import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import { getUserPreferences, updateUserPreferences } from './profile'

const oldNative = nativeApi.defaults.adapter
const oldLegacy = api.defaults.adapter
let seen: { url?: string; method?: string; data?: unknown; baseURL?: string }[] = []

beforeEach(() => {
  seen = []
  localStorage.clear()
  saveAuthData('Bearer settings-token')
  nativeApi.defaults.adapter = async config => {
    seen.push(config)
    return { data: { data: { remind_expire: true, remind_traffic: false },
      request_id: 'trace-preferences' },
      status: 200, statusText: 'OK', headers: {}, config } as never
  }
  api.defaults.adapter = async () => { throw new Error('legacy update must not run') }
})
afterEach(() => {
  nativeApi.defaults.adapter = oldNative
  api.defaults.adapter = oldLegacy
  localStorage.clear()
})
describe('LR-06 native user preferences', () => {
  it('loads and saves only native preferences', async () => {
    expect(await getUserPreferences()).toEqual({ remind_expire: true, remind_traffic: false })
    expect(await updateUserPreferences({ remind_expire: false, remind_traffic: true }))
      .toEqual({ remind_expire: true, remind_traffic: false })
    expect(seen.map(v => [v.method,v.baseURL,v.url])).toEqual([
      ['get','/txapi','/me/preferences'],
      ['patch','/txapi','/me/preferences'],
    ])
    expect(JSON.parse(String(seen[1].data))).toEqual({ remind_expire: false, remind_traffic: true })
  })
})
