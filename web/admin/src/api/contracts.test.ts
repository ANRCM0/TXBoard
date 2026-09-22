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
import { copyNode, generateSecret } from './server'
import { resolvePluginAppUrl } from './plugin'
import { getThemes } from './theme'
import { getModuleRegistry } from './module'
import { normalizePluginNavigationTarget } from '../plugins/bridge'

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

describe('theme adapter contract', () => {
  it('normalizes keyed theme maps and marks the built-in default active', async () => {
    responder = () => ({
      data: {
        data: {
          themes: {
            TXBoard: {
              name: 'TXBoard',
              description: 'TXBoard default theme',
              version: '1.0.0',
              is_system: true,
              can_delete: false,
            },
          },
          active: 'TXBoard',
        },
      },
    })

    const result = await getThemes()

    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/theme/getThemes')
    expect(result.active).toBe('TXBoard')
    expect(result.themes).toHaveLength(1)
    expect(result.themes[0]).toMatchObject({
      name: 'TXBoard',
      is_active: true,
      is_system: true,
      can_delete: false,
    })
  })
})

describe('module registry contract', () => {
  it('reads the unified Module Registry from GET /module only', async () => {
    responder = () => ({
      data: {
        data: {
          modules: [
            {
              id: 'theme.txboard',
              name: 'TXBoard',
              version: '1.0.0',
              type: 'theme',
              source: 'system',
              installed: true,
              enabled: true,
              active: true,
              health: 'healthy',
              capabilities: ['theme'],
              compatibility: { txboard: '*' },
            },
          ],
          errors: [],
          summary: {
            total: 1,
            health: {
              healthy: 1,
              degraded: 0,
              disabled: 0,
              failed: 0,
              incompatible: 0,
              missing_dependency: 0,
            },
            discovery_errors: 0,
          },
        },
      },
    })

    const result = await getModuleRegistry()

    expect(seen).toHaveLength(1)
    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/module')
    expect(result.modules).toHaveLength(1)
    expect(result.modules[0]).toMatchObject({
      id: 'theme.txboard',
      type: 'theme',
      source: 'system',
      active: true,
      health: 'healthy',
    })
    expect(result.summary.total).toBe(1)
  })
})

describe('node editor endpoints', () => {
  it('asks the panel to mint key material for a protocol field', async () => {
    responder = () => ({ data: { data: { private_key: 'PRIVATE', public_key: 'PUBLIC' } } })

    await expect(generateSecret('x25519')).resolves.toEqual({ private_key: 'PRIVATE', public_key: 'PUBLIC' })

    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/server/manage/generateSecret')
    expect(seen[0].params).toEqual({ kind: 'x25519' })
  })

  it('copies a node and unwraps the new node id', async () => {
    responder = () => ({ data: { data: 42 } })

    await expect(copyNode(7)).resolves.toBe(42)

    expect(seen[0].method).toBe('post')
    expect(seen[0].url).toBe('/server/manage/copy')
    expect(JSON.parse(String(seen[0].data))).toEqual({ id: 7 })
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


describe('plugin package v1 admin app contract', () => {
  it('resolves plugin-owned admin assets below the plugin asset base', () => {
    expect(resolvePluginAppUrl(
      { code: 'access_audit', asset_base: '/plugins/access_audit' },
      { app: 'admin/index.html#/dashboard' },
    )).toBe('/plugins/access_audit/admin/index.html#/dashboard')
  })

  it('rejects external and traversal plugin app references', () => {
    expect(resolvePluginAppUrl({ code: 'demo' }, { app: 'https://example.com/app.html' })).toBeNull()
    expect(resolvePluginAppUrl({ code: 'demo' }, { app: 'admin/../index.html' })).toBeNull()
    expect(resolvePluginAppUrl({ code: 'demo' }, { app: '/admin/index.html' })).toBeNull()
  })

  it('only accepts relative host navigation targets from plugin apps', () => {
    expect(normalizePluginNavigationTarget('rules')).toBe('rules')
    expect(normalizePluginNavigationTarget('reports/page')).toBe('reports/page')
    expect(normalizePluginNavigationTarget('../config')).toBeNull()
    expect(normalizePluginNavigationTarget('https://example.com')).toBeNull()
  })
})
