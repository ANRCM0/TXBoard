import type { AxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { unwrap } from '../lib/api'
import { removeAccessToken, setAccessToken } from '../lib/storage'
import {
  apiClient,
  nativeApiClient,
  unwrapNative,
  clearAdminSecurePath,
  getResolvedApiPrefixes,
  setAdminSecurePath,
} from './client'
import { fetchSettings, saveSettings } from './config'
import { getAuditLogs } from './statistics'
import { getPlans, getOrders } from './finance'
import { getTickets, getTicketDetail, replyTicket, closeTicket } from './ticket'
import { getUsers, getUserDetail, getUserSubscriptionLink } from './user-admin'
import { copyNode, generateSecret } from './server'
import { resolvePluginAppUrl } from './plugin'
import { getThemes, getThemeConfig, saveThemeConfig } from './theme'
import { getModuleRegistry } from './module'
import { normalizePluginNavigationTarget } from '../plugins/bridge'

type Seen = AxiosRequestConfig & { headers: Record<string, string> }

let seen: Seen[] = []
let responder: (config: AxiosRequestConfig) => { data: unknown; status?: number }
let originalBaseURL: string | undefined

function installAdapter() {
  const adapter = async (config: AxiosRequestConfig) => {
    seen.push(config as unknown as Seen)
    const { data, status = 200 } = responder(config as unknown as AxiosRequestConfig)
    return { data, status, statusText: 'OK', headers: {}, config } as never
  }
  apiClient.defaults.adapter = adapter
  nativeApiClient.defaults.adapter = adapter
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
  it('loads and saves per-theme settings through administrator-scoped endpoints', async () => {
    responder = config => ({
      data: { data: config.url === '/theme/getThemeConfig'
        ? { theme_color: 'blue', background_url: '' }
        : { theme_color: 'green' },
      },
    })
    await expect(getThemeConfig('TXBoard')).resolves.toEqual({ theme_color: 'blue', background_url: '' })
    await expect(saveThemeConfig('TXBoard', { theme_color: 'green' })).resolves.toEqual({ theme_color: 'green' })
    expect(seen[0].method).toBe('post')
    expect(seen[0].url).toBe('/theme/getThemeConfig')
    expect(JSON.parse(String(seen[0].data))).toEqual({ name: 'TXBoard' })
    expect(seen[1].url).toBe('/theme/saveThemeConfig')
    expect(JSON.parse(String(seen[1].data))).toEqual({ name: 'TXBoard', config: { theme_color: 'green' } })
  })

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
              description: 'TXBoard default theme',
              author: 'TXBoard',
              type: 'theme',
              source: 'system',
              installed: true,
              enabled: true,
              active: true,
              health: 'healthy',
              health_details: {
                checks: {
                  schedule: true,
                  websocket_server: null,
                },
                observed_at: 1790112000,
              },
              capabilities: ['theme'],
              compatibility: { txboard: '*' },
              admin: {
                navigation: [
                  {
                    id: 'dashboard',
                    title: 'Dashboard',
                    path: 'dashboard',
                    order: 10,
                  },
                ],
              },
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
      description: 'TXBoard default theme',
      author: 'TXBoard',
      type: 'theme',
      source: 'system',
      active: true,
      health: 'healthy',
      health_details: {
        checks: {
          schedule: true,
          websocket_server: null,
        },
        observed_at: 1790112000,
      },
      admin: {
        navigation: [
          {
            id: 'dashboard',
            title: 'Dashboard',
            path: 'dashboard',
            order: 10,
          },
        ],
      },
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


describe('P1-B admin native TXAPI client isolation', () => {
  it('points to /txapi without leaking or depending on the dynamic legacy admin path', async () => {
    setAdminSecurePath('rotated-secret-path')
    setAccessToken('admin-session')
    responder = () => ({ data: { data: { id: 7 }, request_id: 'trace-2' } })

    await expect(unwrapNative<{ id: number }>(nativeApiClient.get('/me'))).resolves.toEqual({ id: 7 })
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/me')
    expect(String(seen[0].headers.Authorization)).toBe('Bearer admin-session')
    expect(apiClient.defaults.baseURL).toBe('/api/v2/rotated-secret-path')
    expect(getResolvedApiPrefixes().native).toBe('/txapi')
  })

  it('rejects invalid native envelopes without a fake success', async () => {
    await expect(unwrapNative(Promise.resolve({ data: { data: null } as never })))
      .rejects.toThrow('Invalid TXAPI response')
  })
})

describe('native administrator audit contract', () => {
  it('uses dynamic admin path, native bearer and bounded server pagination', async () => {
    setAdminSecurePath('native-audit-secure')
    setAccessToken('native-admin-bearer')
    responder = () => ({
      data: {
        data: [{ id: 1, action: 'config.save', request_data: '{"password":"[REDACTED]"}' }],
        meta: { total: 1, page: 2, per_page: 1, last_page: 2 },
        request_id: 'test-native-admin-audit',
      },
    })

    const result = await getAuditLogs({ current: 2, page_size: 1, action: 'config.save' })
    expect(result.total).toBe(1)
    expect(result.current_page).toBe(2)
    expect(result.last_page).toBe(2)
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/native-audit-secure/audit-logs')
    expect(seen[0].params).toEqual({ page: 2, per_page: 1, action: 'config.save' })
    expect(String(seen[0].headers.Authorization)).toBe('Bearer native-admin-bearer')

    setAdminSecurePath('native-audit-rotated')
    await getAuditLogs()
    expect(seen[1].url).toBe('/admin/native-audit-rotated/audit-logs')
  })

  it('does not guess native path or conceal malformed admin responses', async () => {
    clearAdminSecurePath()
    await expect(getAuditLogs()).rejects.toThrow('Administrator secure path is unavailable')
    expect(seen).toHaveLength(0)
    setAdminSecurePath('admin-known')
    responder = () => ({ data: { data: [], request_id: 'without-paging' } })
    await expect(getAuditLogs()).rejects.toThrow('Invalid native administrator audit response')
  })
})

describe('native admin plans and orders', () => {
  it('collects complete paged plans through secure-path-scoped TXAPI', async () => {
    setAdminSecurePath('admin-plans')
    responder = config => {
      const page = Number((config.params as { page: number }).page)
      return {
        data: {
          data: [{ id: page, name: 'Plan ' + page, transfer_enable: 2 }],
          meta: { page, per_page: 100, total: 2, last_page: 2 },
          request_id: 'plans-' + page,
        },
      }
    }
    const plans = await getPlans()
    expect(plans.map(p => p.id)).toEqual([1, 2])
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/admin-plans/plans')
    expect(seen[0].params).toEqual({ page: 1, per_page: 100 })
    expect(seen[1].params).toEqual({ page: 2, per_page: 100 })
  })

  it('maps existing order UI filters exactly and preserves pagination', async () => {
    setAdminSecurePath('admin-orders')
    responder = () => ({
      data: {
        data: [{ id: 1, trade_no: 'TX123', user_id: 5, plan_id: 2, total_amount: 1200, status: 3, type: 1 }],
        meta: { page: 2, per_page: 10, total: 13, last_page: 2 },
        request_id: 'orders-native',
      },
    })
    const result = await getOrders({
      current: 2, pageSize: 10, is_commission: true,
      filter: [{ id: 'email', value: 'buyer' }, { id: 'status', value: [3] }],
    })
    expect(result.total).toBe(13)
    expect(result.last_page).toBe(2)
    expect(result.data[0].trade_no).toBe('TX123')
    expect(seen[0].url).toBe('/admin/admin-orders/orders')
    expect(seen[0].params).toEqual({
      page: 2, per_page: 10, is_commission: true, email: 'buyer', status: 3,
    })
    await expect(getOrders({ filter: [{ id: 'private_field', value: 'x' }] }))
      .rejects.toThrow('Unsupported native order filter')
  })

  it('rejects malformed native commerce envelopes', async () => {
    setAdminSecurePath('admin-commerce')
    responder = () => ({ data: { data: [], request_id: 'missing-meta' } })
    await expect(getPlans()).rejects.toThrow('Invalid TXAPI plan catalog response')
    await expect(getOrders()).rejects.toThrow('Invalid TXAPI order list response')
  })
})

describe('native administrator tickets', () => {
  it('uses native owner-guarded ticket pagination and detail', async () => {
    setAdminSecurePath('support-admin')
    setAccessToken('support-session')
    responder = config => ({
      data: {
        data: config.method === 'get' && config.url?.endsWith('/42')
          ? { id: 42, messages: [{ id: 1, message: 'Help' }] }
          : [{ id: 42, subject: 'Help' }],
        meta: { page: 2, per_page: 10, total: 11, last_page: 2 },
        request_id: 'support-ticket',
      },
    })
    const page = await getTickets({
      current: 2, pageSize: 10, status: 0, reply_status: [1], email: 'help@example.test',
    })
    expect(page.total).toBe(11)
    expect(page.last_page).toBe(2)
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/support-admin/tickets')
    expect(seen[0].params).toEqual({
      page: 2, per_page: 10, status: 0, reply_status: 1, email: 'help@example.test',
    })
    expect(String(seen[0].headers.Authorization)).toBe('Bearer support-session')
    const detail = await getTicketDetail(42)
    expect(detail.messages?.[0].message).toBe('Help')
    expect(seen[1].url).toBe('/admin/support-admin/tickets/42')
    await expect(getTicketDetail(0)).rejects.toThrow('Invalid ticket ID')
  })

  it('uses native reply and close POSTs with envelope acknowledgements', async () => {
    setAdminSecurePath('support-admin')
    responder = () => ({ data: { data: { ok: true }, request_id: 'mutation-ok' } })
    await expect(replyTicket(42, 'Resolved')).resolves.toBe(true)
    await expect(closeTicket(42)).resolves.toBe(true)
    expect(seen[0].url).toBe('/admin/support-admin/tickets/42/reply')
    expect(JSON.parse(String(seen[0].data))).toEqual({ message: 'Resolved' })
    expect(seen[1].url).toBe('/admin/support-admin/tickets/42/close')
  })

  it('rejects malformed native pages instead of silently discarding tickets', async () => {
    setAdminSecurePath('support-admin')
    responder = () => ({ data: { data: [], request_id: 'missing-meta' } })
    await expect(getTickets({})).rejects.toThrow('Invalid native ticket list response')
    await expect(getTickets({ reply_status: [0, 1] })).rejects.toThrow('single reply status')
  })
})

describe('native administrator user reads', () => {
  it('keeps existing UI filter/sort semantics without exposing subscription tokens in list', async () => {
    setAdminSecurePath('people-admin')
    responder = () => ({ data: {
      data: [{ id: 7, email: 'test@example.test', balance: 12.34 }],
      meta: { page: 2, per_page: 20, total: 30, last_page: 2 },
      request_id: 'native-user-list',
    } })
    const users = await getUsers({
      current: 2, pageSize: 20,
      filter: [
        { id: 'email', value: 'test' },
        { id: 'plan_id', value: 'eq:3' },
        { id: 'banned', value: 'eq:0' },
      ],
      sort: [{ id: 'total_used', desc: true }],
    })
    expect(users.data).toHaveLength(1)
    expect(users.data[0].balance).toBe(12.34)
    expect(users.last_page).toBe(2)
    expect(seen[0].url).toBe('/admin/people-admin/users')
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].params).toEqual({
      page: 2, per_page: 20, email: 'test', plan_id: 3, banned: 0,
      sort: 'total_used', descending: true,
    })
  })

  it('reads private subscription only when the operator explicitly requests it', async () => {
    setAdminSecurePath('people-admin')
    responder = config => ({ data: {
      data: config.url?.endsWith('/subscription-link')
        ? { subscribe_url: 'https://example.test/s/secret' }
        : { id: 7, email: 'test@example.test' },
      request_id: 'native-user-detail',
    } })
    const user = await getUserDetail(7)
    expect(user.id).toBe(7)
    const link = await getUserSubscriptionLink(7)
    expect(link).toBe('https://example.test/s/secret')
    expect(seen[0].url).toBe('/admin/people-admin/users/7')
    expect(seen[1].url).toBe('/admin/people-admin/users/7/subscription-link')
    await expect(getUserSubscriptionLink(0)).rejects.toThrow('Invalid admin user ID')
  })

  it('rejects unknown filter fields before issuing requests', async () => {
    setAdminSecurePath('people-admin')
    await expect(getUsers({ filter: [{ id: 'token', value: 'x' }] }))
      .rejects.toThrow('Unsupported native user filter')
    expect(seen).toHaveLength(0)
  })
})
