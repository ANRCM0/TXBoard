import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import { fetchTrafficLog } from './traffic'

const nativeAdapter = nativeApi.defaults.adapter
const legacyAdapter = api.defaults.adapter
let seen: { url?: string; params?: unknown; baseURL?: string }[] = []
let payload: unknown

beforeEach(() => {
  seen = []
  localStorage.clear()
  saveAuthData('Bearer traffic-test')
  payload = { data: [{ id: 2, upload_bytes: 1024, download_bytes: 2048,
    record_at: 1760000000, server_rate: 1.5 }],
    meta: { page: 2, per_page: 1, total: 4, last_page: 4 }, request_id: 'trace-traffic' }
  nativeApi.defaults.adapter = async config => {
    seen.push(config)
    return { data: payload, status: 200, statusText: 'OK', headers: {}, config } as never
  }
  api.defaults.adapter = async () => { throw Error('legacy traffic must not be called') }
})
afterEach(() => {
  nativeApi.defaults.adapter = nativeAdapter
  api.defaults.adapter = legacyAdapter
  localStorage.clear()
})
describe('LR-04 native traffic', () => {
  it('reads paginated native records with explicit units', async () => {
    const response = await fetchTrafficLog(2, 1)
    expect(seen).toHaveLength(1)
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/traffic/logs')
    expect(seen[0].params).toEqual({ page: 2, per_page: 1 })
    expect(response).toEqual({ data: [{ id: 2, u: 1024, d: 2048, record_at: 1760000000,
      server_rate: 1.5 }], total: 4, page: 2, per_page: 1 })
  })
  it('rejects malformed native envelopes and bytes', async () => {
    payload = { data: [], request_id: 'missing-meta' }
    await expect(fetchTrafficLog()).rejects.toThrow('Invalid TXAPI traffic pagination')
    payload = { data: [{ id: 1, upload_bytes: -10, download_bytes: 2,
      record_at: 1760000000, server_rate: 1 }],
      meta: { page: 1, per_page: 20, total: 1 }, request_id: 'trace' }
    await expect(fetchTrafficLog()).rejects.toThrow('Invalid TXAPI traffic record')
  })
})
