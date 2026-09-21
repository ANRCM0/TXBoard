import type { AxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { unwrap } from '../lib/api'
import { removeAccessToken, setAccessToken } from '../lib/storage'
import {
  apiClient,
  clearAdminSecurePath,
  getResolvedApiPrefixes,
  setAdminSecurePath,
} from './client'
import { fetchSettings, saveSettings } from './config'

type Seen = AxiosRequestConfig & { headers: Record<string, string> }

let seen: Seen[] = []
let responder: (config: AxiosRequestConfig) => { data: unknown; status?: number }
let originalBaseURL: string | undefined

function installAdapter() {
  apiClient.defaults.adapter = async config => {
    seen.push(config as unknown as Seen)
    const { data, status = 200 } = responder(config as unknown as AxiosRequestConfig)
    return { data, status, statusText: 'OK', headers: {}, config } as never
  }
}

beforeEach(() => {
  seen = []
  responder = () => ({ data: { data: null } })
  originalBaseURL = apiClient.defaults.baseURL
  localStorage.clear()
  removeAccessToken()
  installAdapter()
})

afterEach(() => {
  apiClient.defaults.baseURL = originalBaseURL
  clearAdminSecurePath()
  localStorage.clear()
})

describe('admin api envelope', () => {
  it('unwraps the { data } envelope', () => {
    expect(unwrap({ data: { app_name: 'TX' } })).toEqual({ app_name: 'TX' })
  })

  it('passes through payloads that are not enveloped', () => {
    expect(unwrap({ app_name: 'TX' })).toEqual({ app_name: 'TX' })
  })
})

describe('config adapter contract', () => {
  it('fetches a scoped settings group from GET /config/fetch', async () => {
    responder = () => ({ data: { data: { site: { app_name: 'TX' } } } })

    const result = await fetchSettings('site')

    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/config/fetch')
    expect(seen[0].params).toEqual({ key: 'site' })
    expect(result).toEqual({ app_name: 'TX' })
  })

  it('falls back to the whole payload when the group is absent', async () => {
    responder = () => ({ data: { data: { app_name: 'TX' } } })

    await expect(fetchSettings('site')).resolves.toEqual({ app_name: 'TX' })
  })

  it('saves settings with POST /config/save and a JSON body', async () => {
    responder = () => ({ data: { data: true } })

    await saveSettings({ app_name: 'TX' })

    expect(seen[0].method).toBe('post')
    expect(seen[0].url).toBe('/config/save')
    expect(JSON.parse(String(seen[0].data))).toEqual({ app_name: 'TX' })
  })

  it('attaches the stored bearer token to admin requests', async () => {
    setAccessToken('secret-token')
    responder = () => ({ data: { data: {} } })

    await fetchSettings('site')

    expect(String(seen[0].headers.Authorization)).toBe('Bearer secret-token')
  })
})

describe('admin secure path resolution', () => {
  it('re-points the admin client at a rotated secure path', () => {
    setAdminSecurePath('rotated2024')
    expect(apiClient.defaults.baseURL).toBe('/api/v2/rotated2024')
  })

  it('switches the admin client immediately after a secure-path save succeeds', async () => {
    setAdminSecurePath('before-rotation')
    responder = () => ({ data: { data: true } })

    await saveSettings({ secure_path: 'after-rotation' })

    expect(seen[0].baseURL).toBe('/api/v2/before-rotation')
    expect(apiClient.defaults.baseURL).toBe('/api/v2/after-rotation')
  })

  it('exposes the public prefix separately from the admin prefix', () => {
    expect(getResolvedApiPrefixes().public).toBe('/api/v2')
  })
})
