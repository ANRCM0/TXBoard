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
import { fetchSettings, saveSettings, testSendMail, setTelegramWebhook } from './config'
import { getGroups, saveGroup, deleteGroup, getRoutes, saveRoute, sortRoutes, simulateRoute, deleteRoute } from './server'
import { getMachines, saveMachine, getMachineCredentials, resetMachineToken, updateMachineRuntime, deleteMachine, getMachineNodes, getMachineHistory } from './server'
import { getAuditLogs, getDashboardStats, getOrderChart, getTrafficRank, getAnalyticsRanking, getUserTrafficStats } from './statistics'
import { getPlans, getOrders, savePlan, updatePlanFlags, deletePlan, sortPlans, getOrderDetail, markOrderPaid, cancelOrder, assignOrder, updateOrderCommission } from './finance'
import { getTickets, getTicketDetail, replyTicket, closeTicket } from './ticket'
import { getTrafficResetLogs, getTrafficResetStats, resetUserTraffic, getUserTrafficResetHistory } from './traffic-reset'
import { deletePayment, getPayments, getPaymentMethods, getPaymentForm, savePayment, togglePayment, sortPayments } from './payment'
import { getQueueSnapshot, getQueueFailures, getQueueFailure } from './queueMonitor'
import { getCoupons, saveCoupon, toggleCoupon, deleteCoupon } from './coupon'
import { listMailTemplates, getMailTemplate, saveMailTemplate, resetMailTemplate, testMailTemplate } from './mail'
import {
  getKnowledgePage, getKnowledgeAll, getKnowledgeDetail, getKnowledgeCategories,
  saveKnowledge, toggleKnowledge, sortKnowledge, deleteKnowledge,
  getNoticePage, getNoticeAll, saveNotice, toggleNotice, sortNotice, deleteNotice,
} from './content'
import { getUsers, getUserDetail, getUserSubscriptionLink, resetUserSecret, destroyUser, banUsers, updateUser, generateUser, sendUsersMail } from './user-admin'
import { copyNode, generateSecret, getProtocolDefinitions, getNodes, saveNode, updateNode, batchUpdateNodes, saveNodeOrder, deleteNode } from './server'
import { resolvePluginAppUrl, resolvePluginCrudApiPath, fetchPluginCrudList, savePluginCrudRecord } from './plugin'
import { getThemes, getThemeConfig, saveThemeConfig, deleteTheme, uploadTheme } from './theme'
import { getAgentTokens, createAgentToken, revokeAgentToken, getAgentAbilities, getAgentActions, approveAgentAction, rejectAgentAction, getAgentFleetHealth, getAgentInspections, runAgentInspection, getAgentSupportReplies, approveAgentSupportReply, rejectAgentSupportReply } from './agent'
import { getPlugins, installPlugin, uninstallPlugin, enablePlugin, disablePlugin, upgradePlugin, deletePlugin, getPluginConfig, updatePluginConfig, uploadPlugin } from './plugin'
import { getModuleRegistry } from './module'
import { login } from './auth'
import { fetchGuestConfig } from './comm'
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
  it('fetches a scoped settings group via native TXAPI', async () => {
    setAdminSecurePath('settings-admin')
    responder = () => ({ data: { data: { site: { app_name: 'TX' } }, request_id: 'settings-get' } })

    const result = await fetchSettings('site')

    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/admin/settings-admin/settings/site')
    expect(seen[0].baseURL).toBe('/txapi')
    expect(result).toEqual({ app_name: 'TX' })
  })

  it('falls back to the whole payload when the group is absent', async () => {
    setAdminSecurePath('settings-admin')
    responder = () => ({ data: { data: { app_name: 'TX' }, request_id: 'settings-fallback' } })

    await expect(fetchSettings('site')).resolves.toEqual({ app_name: 'TX' })
  })

  it('saves settings through native TXAPI with a JSON body', async () => {
    setAdminSecurePath('settings-admin')
    responder = () => ({ data: { data: { ok: true }, request_id: 'settings-save' } })

    await saveSettings({ app_name: 'TX' })

    expect(seen[0].method).toBe('post')
    expect(seen[0].url).toBe('/admin/settings-admin/settings')
    expect(JSON.parse(String(seen[0].data))).toEqual({ app_name: 'TX' })
  })

  it('attaches the stored bearer token to admin requests', async () => {
    setAccessToken('secret-token')
    setAdminSecurePath('settings-admin')
    responder = () => ({ data: { data: {}, request_id: 'settings-auth' } })

    await fetchSettings('site')

    expect(String(seen[0].headers.Authorization)).toBe('Bearer secret-token')
  })
})

describe('native network group and route contracts', () => {
  it('uses the rotating administrator path for group and route operations only', async () => {
    setAdminSecurePath('network-admin')
    responder = config => ({ data: {
      data: config.method === 'get'
        ? [{ id: 5, name: 'one', remarks: 'one' }]
        : config.url?.endsWith('/simulate')
          ? { node: { id: 3 }, target: 'example.com', authoritative: true, evaluated_routes: [], unresolved_patterns: [] }
          : { ok: true, id: 5 },
      request_id: 'network-native',
    } })

    await getGroups()
    await saveGroup({ name: 'one' })
    await deleteGroup(5)
    await getRoutes()
    await saveRoute({ remarks: 'one', action: 'direct', match: ['example.com'] })
    await sortRoutes([{ id: 5, sort: 10 }])
    await simulateRoute(3, 'example.com')
    await deleteRoute(5)

    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['get', '/admin/network-admin/network-groups'],
      ['post', '/admin/network-admin/network-groups'],
      ['delete', '/admin/network-admin/network-groups/5'],
      ['get', '/admin/network-admin/network-routes'],
      ['post', '/admin/network-admin/network-routes'],
      ['put', '/admin/network-admin/network-routes/sort'],
      ['post', '/admin/network-admin/network-routes/simulate'],
      ['delete', '/admin/network-admin/network-routes/5'],
    ])
    expect(JSON.parse(String(seen[6].data))).toEqual({ node_id: 3, target: 'example.com' })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })
})

describe('native configuration side-effect contract', () => {
  it('reuses the native notify mail test and registers webhooks under the rotating secure path', async () => {
    setAdminSecurePath('settings-actions')
    responder = () => ({ data: { data: { ok: true }, request_id: 'config-actions' } })

    await expect(testSendMail()).resolves.toEqual({ ok: true })
    await expect(setTelegramWebhook('123456:test-token')).resolves.toEqual({ ok: true })

    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['post', '/admin/settings-actions/mail-templates/notify/test'],
      ['post', '/admin/settings-actions/settings/telegram/webhook'],
    ])
    expect(JSON.parse(String(seen[0].data))).toEqual({})
    expect(JSON.parse(String(seen[1].data))).toEqual({ telegram_bot_token: '123456:test-token' })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })
})

describe('native theme administration contract', () => {
  it('uses scoped GET/PUT per-theme config and native envelope', async () => {
    setAdminSecurePath('extension-admin')
    responder = config => ({
      data: {
        data: config.method === 'get'
          ? { theme_color: 'blue', background_url: '' }
          : { theme_color: 'green' },
        request_id: 'theme-native',
      },
    })
    await expect(getThemeConfig('TXBoard')).resolves.toEqual({
      theme_color: 'blue', background_url: '',
    })
    await expect(saveThemeConfig('TXBoard', { theme_color: 'green' }))
      .resolves.toEqual({ theme_color: 'green' })
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['get', '/admin/extension-admin/themes/TXBoard/config'],
      ['put', '/admin/extension-admin/themes/TXBoard/config'],
    ])
    expect(JSON.parse(String(seen[1].data))).toEqual({ config: { theme_color: 'green' } })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })

  it('normalizes keyed theme maps and marks the built-in default active', async () => {
    setAdminSecurePath('extension-admin')
    responder = () => ({
      data: { data: {
        themes: {
          TXBoard: {
            name: 'TXBoard', description: 'TXBoard default theme',
            version: '1.0.0', is_system: true, can_delete: false,
          },
        },
        active: 'TXBoard',
      }, request_id: 'theme-list' },
    })
    const result = await getThemes()
    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/admin/extension-admin/themes')
    expect(result.active).toBe('TXBoard')
    expect(result.themes).toHaveLength(1)
    expect(result.themes[0]).toMatchObject({
      name: 'TXBoard', is_active: true, is_system: true, can_delete: false,
    })
  })

  it('deletes and uploads themes via native protected management', async () => {
    setAdminSecurePath('extension-admin')
    responder = () => ({ data: { data: { ok: true }, request_id: 'theme-write' } })
    await deleteTheme('demo')
    await uploadTheme(new File(['content'], 'demo.zip', { type: 'application/zip' }))
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['delete', '/admin/extension-admin/themes/demo'],
      ['post', '/admin/extension-admin/themes/upload'],
    ])
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })
})

describe('native plugin management contract', () => {
  it('lists plugins and manages lifecycle without calling old V2 admin routes', async () => {
    setAdminSecurePath('plugin-admin')
    responder = config => ({ data: {
      data: config.method === 'get' && config.url?.endsWith('/plugins')
        ? [{ code: 'demo', name: 'Demo', is_installed: true }]
        : { ok: true },
      request_id: 'plugin-native',
    } })
    await expect(getPlugins({ type: 'feature' })).resolves.toHaveLength(1)
    await installPlugin('demo')
    await enablePlugin('demo')
    await disablePlugin('demo')
    await upgradePlugin('demo')
    await uninstallPlugin('demo')
    await deletePlugin('demo')
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['get', '/admin/plugin-admin/plugins'],
      ['post', '/admin/plugin-admin/plugins/demo/actions/install'],
      ['post', '/admin/plugin-admin/plugins/demo/actions/enable'],
      ['post', '/admin/plugin-admin/plugins/demo/actions/disable'],
      ['post', '/admin/plugin-admin/plugins/demo/actions/upgrade'],
      ['post', '/admin/plugin-admin/plugins/demo/actions/uninstall'],
      ['delete', '/admin/plugin-admin/plugins/demo'],
    ])
    expect(seen[0].params).toEqual({ type: 'feature' })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })

  it('reads and writes plugin config and uploads ZIP with native identity', async () => {
    setAdminSecurePath('plugin-admin')
    responder = config => ({ data: {
      data: config.method === 'get'
        ? { secret: { type: 'text', label: 'Secret', value: 'masked' } }
        : { ok: true },
      request_id: 'plugin-config',
    } })
    await getPluginConfig('demo')
    await updatePluginConfig('demo', { secret: 'new' })
    await uploadPlugin(new File(['content'], 'demo.zip', { type: 'application/zip' }))
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['get', '/admin/plugin-admin/plugins/demo/config'],
      ['put', '/admin/plugin-admin/plugins/demo/config'],
      ['post', '/admin/plugin-admin/plugins/upload'],
    ])
    expect(JSON.parse(String(seen[1].data))).toEqual({ config: { secret: 'new' } })
    expect(() => installPlugin('../admin')).toThrow('Invalid plugin code')
  })
})

describe('module registry contract', () => {
  it('reads the unified Module Registry from native TXAPI only', async () => {
    setAdminSecurePath('module-contract')
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
        request_id: 'module-registry',
      },
    })

    const result = await getModuleRegistry()

    expect(seen).toHaveLength(1)
    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/admin/module-contract/modules')
    expect(seen[0].baseURL).toBe('/txapi')
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

describe('native node editor contract', () => {
  it('requests schema and creates key material through the native admin path', async () => {
    setAdminSecurePath('node-admin')
    responder = config => ({ data: {
      data: config.url?.endsWith('/protocols') ? [{ type: 'socks', label: 'SOCKS' }]
        : { private_key: 'PRIVATE', public_key: 'PUBLIC' },
      request_id: 'node-key',
    } })
    await expect(getProtocolDefinitions()).resolves.toHaveLength(1)
    await expect(generateSecret('x25519')).resolves.toEqual({
      private_key: 'PRIVATE', public_key: 'PUBLIC',
    })
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['get', '/admin/node-admin/network-nodes/protocols'],
      ['post', '/admin/node-admin/network-nodes/secrets'],
    ])
    expect(JSON.parse(String(seen[1].data))).toEqual({ kind: 'x25519' })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })

  it('pages node inventory, creates/edits/copies/deletes and rejects V2 fallback', async () => {
    setAdminSecurePath('node-admin')
    responder = config => ({ data: {
      data: config.method === 'get'
        ? Number((config.params as { page?: number })?.page) === 1
          ? [{ id: 1, name: 'A' }] : [{ id: 2, name: 'B' }]
        : config.url?.endsWith('/copy') ? 42 : { ok: true, id: 7 },
      meta: config.method === 'get'
        ? { page: Number((config.params as { page?: number })?.page), per_page: 100, total: 2, last_page: 2 }
        : undefined,
      request_id: 'node-admin',
    } })
    await expect(getNodes()).resolves.toHaveLength(2)
    await saveNode({ name: 'New', host: 'node.test' })
    await saveNode({ id: 7, name: 'Updated' })
    await updateNode(7, { enabled: false })
    await batchUpdateNodes([7], { enabled: true })
    await saveNodeOrder([{ id: 7, order: 2 }])
    await expect(copyNode(7)).resolves.toBe(42)
    await deleteNode(7)
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['get', '/admin/node-admin/network-nodes'],
      ['get', '/admin/node-admin/network-nodes'],
      ['post', '/admin/node-admin/network-nodes'],
      ['put', '/admin/node-admin/network-nodes/7'],
      ['patch', '/admin/node-admin/network-nodes/7'],
      ['patch', '/admin/node-admin/network-nodes/batch'],
      ['put', '/admin/node-admin/network-nodes/sort'],
      ['post', '/admin/node-admin/network-nodes/7/copy'],
      ['delete', '/admin/node-admin/network-nodes/7'],
    ])
    expect(seen[0].params).toEqual({ page: 1, per_page: 100 })
    expect(seen[1].params).toEqual({ page: 2, per_page: 100 })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })
})

describe('admin secure path resolution', () => {
  it('re-points the admin client at a rotated secure path', () => {
    setAdminSecurePath('rotated2024')
    expect(apiClient.defaults.baseURL).toBe('/api/v2/rotated2024')
  })

  it('switches the admin client immediately after a secure-path save succeeds', async () => {
    setAdminSecurePath('before-rotation')
    responder = () => ({ data: { data: { ok: true }, request_id: 'settings-rotate' } })

    await saveSettings({ secure_path: 'after-rotation' })

    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/before-rotation/settings')
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

describe('native administrator plan write contract', () => {
  it('uses audit-friendly POST routes for create edit flags ordering and deletion', async () => {
    setAdminSecurePath('plan-native-secret')
    responder = config => ({ data: {
      data: config.url === '/admin/plan-native-secret/plans' ? { id: 43 } : { ok: true },
      request_id: 'native-plan-mutation',
    } })
    await expect(savePlan({ name: 'New', transfer_enable: 2, prices: { monthly: 10 } })).resolves.toBe(43)
    await expect(updatePlanFlags(43, { show: true, sell: false })).resolves.toBe(true)
    await expect(sortPlans([43, 41])).resolves.toBe(true)
    await expect(deletePlan(43)).resolves.toBe(true)

    expect(seen.map(item => item.url)).toEqual([
      '/admin/plan-native-secret/plans',
      '/admin/plan-native-secret/plans/43/flags',
      '/admin/plan-native-secret/plans/sort',
      '/admin/plan-native-secret/plans/43/delete',
    ])
    expect(seen.map(item => item.method)).toEqual(['post', 'post', 'post', 'post'])
    expect(JSON.parse(String(seen[1].data))).toEqual({ show: true, sell: false })
    await expect(deletePlan(0)).rejects.toThrow('Invalid plan ID')
  })
})

describe('native administrator notice and knowledge editors', () => {
  it('loads paginated admin-only drafts with validated TXAPI envelopes', async () => {
    setAdminSecurePath('editor-only')
    setAccessToken('admin-access')
    responder = () => ({
      data: {
        data: [{ id: 3, title: 'Draft', show: false }],
        meta: { page: 2, per_page: 10, total: 11, last_page: 2 },
        request_id: 'native-content-1',
      },
    })
    const notices = await getNoticePage({ current: 2, pageSize: 10, title: 'Draft' })
    expect(notices.total).toBe(11)
    expect(notices.data[0].show).toBe(false)
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/editor-only/content/notices')
    expect(seen[0].params).toEqual({ page: 2, per_page: 10, title: 'Draft' })
    expect(String(seen[0].headers.Authorization)).toBe('Bearer admin-access')
    await getKnowledgePage({ category: 'guides' })
    expect(seen[1].url).toBe('/admin/editor-only/content/knowledge')
    expect(seen[1].params).toEqual({ page: 1, per_page: 20, category: 'guides' })
  })

  it('uses native scoped create edit visibility sort and delete HTTP verbs', async () => {
    setAdminSecurePath('content-editor')
    responder = () => ({ data: { data: { id: 7 }, request_id: 'saved' } })
    await saveKnowledge({ title: 'Article', category: 'faq', language: 'zh-CN', body: 'text' })
    await saveKnowledge({ id: 7, title: 'Edit', category: 'faq', language: 'zh-CN', body: 'text' })
    await toggleKnowledge(7)
    await sortKnowledge([7, 8])
    await deleteKnowledge(7)
    expect(seen.map(x => x.method)).toEqual(['post', 'put', 'patch', 'put', 'delete'])
    expect(seen[0].url).toBe('/admin/content-editor/content/knowledge')
    expect(seen[1].url).toBe('/admin/content-editor/content/knowledge/7')
    expect(seen[2].url).toBe('/admin/content-editor/content/knowledge/7/visibility')
    expect(seen[3].url).toBe('/admin/content-editor/content/knowledge/sort')
    expect(JSON.parse(String(seen[3].data))).toEqual({ ids: [7, 8] })
    expect(seen[4].url).toBe('/admin/content-editor/content/knowledge/7')
    await saveNotice({ title: 'Hello', content: 'world' })
    await saveNotice({ id: 2, title: 'Edit', content: 'world' })
    await toggleNotice(2)
    await sortNotice([2, 1])
    await deleteNotice(2)
    expect(seen.slice(5).map(x => x.method)).toEqual(['post', 'put', 'patch', 'put', 'delete'])
    expect(seen[5].url).toBe('/admin/content-editor/content/notices')
    expect(seen[9].url).toBe('/admin/content-editor/content/notices/2')
  })

  it('reads all pages for drag-sort, category lists and article details', async () => {
    setAdminSecurePath('content-admin')
    responder = config => {
      const url = String(config.url)
      if (url.endsWith('/categories')) {
        return { data: { data: ['news', 'faq'], request_id: 'categories' } }
      }
      if (url.endsWith('/7')) {
        return { data: { data: { id: 7, body: 'Private draft' }, request_id: 'article' } }
      }
      const current = Number((config.params as { page?: number } | undefined)?.page || 1)
      return { data: {
        data: [{ id: current, title: 'Article ' + current }],
        meta: { page: current, per_page: 100, total: 2, last_page: 2 },
        request_id: 'paged-' + current,
      } }
    }
    expect((await getKnowledgeAll()).map(x => x.id)).toEqual([1, 2])
    expect((await getNoticeAll()).map(x => x.id)).toEqual([1, 2])
    expect(await getKnowledgeCategories()).toEqual(['news', 'faq'])
    expect((await getKnowledgeDetail(7)).body).toBe('Private draft')
    expect(seen.slice(0, 4).map(x => (x.params as { page: number }).page))
      .toEqual([1, 2, 1, 2])
    await expect(getKnowledgeDetail(0)).rejects.toThrow('Invalid content ID')
  })

  it('never treats malformed metadata as an empty successful collection', async () => {
    setAdminSecurePath('content-editor')
    responder = () => ({ data: { data: [], request_id: 'missing-meta' } })
    await expect(getNoticePage()).rejects.toThrow('Invalid native content pagination')
    await expect(getKnowledgeAll()).rejects.toThrow('Invalid native content pagination')
  })
})

describe('native administrator order detail and state actions', () => {
  it('reads secret-free detail behind native admin path and rejects invalid ids', async () => {
    setAdminSecurePath('order-admin')
    responder = () => ({
      data: { data: { id: 7, trade_no: 'ORDER-7', user_id: 1, plan_id: 2, total_amount: 500, status: 0, type: 1 }, request_id: 'order-detail-native' },
    })
    const detail = await getOrderDetail(7)
    expect(detail.trade_no).toBe('ORDER-7')
    expect(seen[0].url).toBe('/admin/order-admin/orders/7/detail')
    expect(seen[0].baseURL).toBe('/txapi')
    await expect(getOrderDetail(0)).rejects.toThrow('Invalid order ID')
  })

  it('sends paid and cancellation requests only to admin-scoped native POSTs', async () => {
    setAdminSecurePath('order-admin')
    responder = () => ({ data: { data: { ok: true }, request_id: 'order-action' } })
    await expect(markOrderPaid('TX-2026')).resolves.toBe(true)
    await expect(cancelOrder('TX-2026')).resolves.toBe(true)
    expect(seen.map(x => x.url)).toEqual([
      '/admin/order-admin/orders/TX-2026/paid',
      '/admin/order-admin/orders/TX-2026/cancel',
    ])
    expect(seen.map(x => x.method)).toEqual(['post', 'post'])
  })
})

describe('native guarded administrator account mutations', () => {
  it('never sends user deletion or secret rotation to legacy V2', async () => {
    setAdminSecurePath('security-admin')
    responder = () => ({ data: { data: { ok: true }, request_id: 'native-mutation' } })
    await expect(resetUserSecret(7)).resolves.toBe(true)
    await expect(destroyUser(7)).resolves.toBe(true)
    expect(seen.map(x => x.url)).toEqual([
      '/admin/security-admin/users/7/subscription-credentials/rotate',
      '/admin/security-admin/users/7/delete',
    ])
    expect(seen.map(x => x.method)).toEqual(['post', 'post'])
    await expect(destroyUser(0)).rejects.toThrow('Invalid admin user ID')
  })

  it('accepts only actual counted native ban acknowledgements', async () => {
    setAdminSecurePath('security-admin')
    responder = () => ({ data: { data: { updated: 2 }, request_id: 'ban-2' } })
    await expect(banUsers({ scope: 'selected', user_ids: [2, 4] })).resolves.toBe(2)
    expect(seen[0].url).toBe('/admin/security-admin/users/ban')
    expect(JSON.parse(String(seen[0].data))).toEqual({
      scope: 'selected', user_ids: [2, 4],
    })
    responder = () => ({ data: { data: {}, request_id: 'missing-count' } })
    await expect(banUsers({ scope: 'selected', user_ids: [2] }))
      .rejects.toThrow('Native ban operation not acknowledged')
  })
})

describe('native admin user editor contract', () => {
  it('uses native scoped edit with original minor-unit balances', async () => {
    setAdminSecurePath('editable-admin')
    responder = () => ({ data: { request_id: 'saved-user', data: { ok: true, id: 7 } } })
    await expect(updateUser({
      id: 7, email: 'change@example.test',
      balance: 12.34, expected_balance_minor: 1234,
      commission_balance: 5.67, expected_commission_balance_minor: 567,
    })).resolves.toBe(true)
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/editable-admin/users/7/update')
    expect(JSON.parse(String(seen[0].data))).toEqual({
      email: 'change@example.test',
      balance: 12.34, expected_balance_minor: 1234,
      commission_balance: 5.67, expected_commission_balance_minor: 567,
    })
    await expect(updateUser({ id: 7, balance: 14.2 }))
      .rejects.toThrow('Expected balance snapshot required')
  })

  it('rejects implicit email passwords and writes create to TXAPI only', async () => {
    setAdminSecurePath('editable-admin')
    await expect(generateUser({
      email_prefix: 'user', email_suffix: 'example.test',
    })).rejects.toThrow('explicit password')
    expect(seen).toHaveLength(0)
    responder = () => ({ data: { request_id: 'new-user', data: { id: 51 } } })
    await expect(generateUser({
      email_prefix: 'user', email_suffix: 'example.test',
      password: 'VeryStrongPassword123', return_credentials: true,
    })).resolves.toBe(51)
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/editable-admin/users')
    expect(seen[0].method).toBe('post')
  })
})

describe('native administrator traffic reset contract', () => {
  it('requests a scoped bounded log page and maps TXAPI meta to existing table props', async () => {
    setAdminSecurePath('traffic-admin')
    responder = () => ({ data: {
      data: [{ id: 11, user_id: 4, reset_type: 'manual', old_traffic: { total: 200 } }],
      request_id: 'traffic-request',
      meta: { page: 2, per_page: 20, total: 32, last_page: 2 },
    } })
    const page = await getTrafficResetLogs({ page: 2, per_page: 20, user_id: 4 })
    expect(page.total).toBe(32)
    expect(page.current_page).toBe(2)
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/traffic-admin/traffic-resets')
    expect(seen[0].params).toEqual({ page: 2, per_page: 20, user_id: 4 })
  })

  it('uses secured native stats and user history routes, not V2', async () => {
    setAdminSecurePath('traffic-admin')
    responder = config => ({ data: {
      data: config.url?.endsWith('/stats') ? { total_resets: 2, manual_resets: 1 }
        : { user: { id: 4, email: 'x@example.test' }, history: [{ id: 11 }] },
      request_id: 'traffic-stats',
    } })
    expect((await getTrafficResetStats(14)).total_resets).toBe(2)
    expect((await getUserTrafficResetHistory(4, 5)).history).toHaveLength(1)
    expect(seen[0].url).toBe('/admin/traffic-admin/traffic-resets/stats')
    expect(seen[0].params).toEqual({ days: 14 })
    expect(seen[1].url).toBe('/admin/traffic-admin/traffic-resets/users/4')
    expect(seen[1].params).toEqual({ limit: 5 })
  })

  it('sends audited native manual reset with reason and checks acknowledgement', async () => {
    setAdminSecurePath('traffic-admin')
    responder = () => ({ data: {
      data: { user_id: 4, email: 'x@example.test', reset_time: '2026-10-09T00:00:00Z' },
      request_id: 'traffic-reset-ok',
    } })
    await expect(resetUserTraffic(4, 'customer asked')).resolves.toMatchObject({ user_id: 4 })
    expect(seen[0].url).toBe('/admin/traffic-admin/traffic-resets/users/4/reset')
    expect(seen[0].method).toBe('post')
    expect(JSON.parse(String(seen[0].data))).toEqual({ reason: 'customer asked' })
    await expect(resetUserTraffic(0)).rejects.toThrow('Invalid traffic reset user ID')
  })

  it('rejects malformed native log pages', async () => {
    setAdminSecurePath('traffic-admin')
    responder = () => ({ data: { data: [], request_id: 'no-meta' } })
    await expect(getTrafficResetLogs()).rejects.toThrow('Invalid native traffic reset logs response')
  })
})

describe('native administrator payment deletion guard', () => {
  it('uses scoped TXAPI and checks deletion confirmation', async () => {
    setAdminSecurePath('secure-payment-admin')
    responder = () => ({ data: { data: { ok: true }, request_id: 'payment-delete-ok' } })
    await expect(deletePayment(9)).resolves.toBe(true)
    expect(seen[0].url).toBe('/admin/secure-payment-admin/payment-methods/9/delete')
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].method).toBe('post')
    await expect(deletePayment(0)).rejects.toThrow('Invalid payment method ID')
  })

  it('does not treat malformed responses as a successful payment deletion', async () => {
    setAdminSecurePath('secure-payment-admin')
    responder = () => ({ data: { data: { ok: false }, request_id: 'failed' } })
    await expect(deletePayment(9)).rejects.toThrow('not acknowledged')
  })
})

describe('native administrator payment management contract', () => {
  it('reads payment methods, providers and plugin forms through the secured TXAPI', async () => {
    setAdminSecurePath('payment-admin')
    responder = config => ({
      data: {
        data: config.url?.endsWith('/providers')
          ? ['EPay']
          : config.url?.endsWith('/form')
            ? { key: { type: 'string', label: 'Key', value: 'secret' } }
            : [{ id: 7, name: 'Test', payment: 'EPay', config: { key: 'secret' } }],
        request_id: 'payment-read',
      },
    })
    await expect(getPayments()).resolves.toMatchObject([{ id: 7 }])
    await expect(getPaymentMethods()).resolves.toEqual(['EPay'])
    await expect(getPaymentForm('EPay', 7)).resolves.toHaveProperty('key.value', 'secret')
    expect(seen.map(request => request.url)).toEqual([
      '/admin/payment-admin/payment-methods',
      '/admin/payment-admin/payment-methods/providers',
      '/admin/payment-admin/payment-methods/form',
    ])
    expect(seen.map(request => request.baseURL)).toEqual(['/txapi', '/txapi', '/txapi'])
    expect(JSON.parse(String(seen[2].data))).toEqual({ payment: 'EPay', id: 7 })
  })

  it('saves, toggles and sorts through native endpoints without V2 fallback', async () => {
    setAdminSecurePath('payment-admin')
    responder = config => ({
      data: {
        data: config.url?.endsWith('/toggle') ? { enable: false }
          : config.url?.endsWith('/sort') ? { ok: true } : { id: 7 },
        request_id: 'payment-mutation',
      },
    })
    const payload = { name: 'Card', payment: 'EPay', config: { api_key: 'secret' } }
    await expect(savePayment(payload)).resolves.toEqual({ id: 7 })
    await expect(savePayment({ ...payload, id: 7 })).resolves.toEqual({ id: 7 })
    await expect(togglePayment(7)).resolves.toEqual({ enable: false })
    await expect(sortPayments([7, 8])).resolves.toEqual({ ok: true })
    expect(seen.map(request => [request.method, request.url])).toEqual([
      ['post', '/admin/payment-admin/payment-methods'],
      ['put', '/admin/payment-admin/payment-methods/7'],
      ['patch', '/admin/payment-admin/payment-methods/7/toggle'],
      ['put', '/admin/payment-admin/payment-methods/sort'],
    ])
    expect(JSON.parse(String(seen[3].data))).toEqual({ ids: [7, 8] })
  })

  it('rejects malformed TXAPI envelopes without silently succeeding', async () => {
    setAdminSecurePath('payment-admin')
    responder = () => ({ data: { data: [{ id: 1 }] } })
    await expect(getPayments()).rejects.toThrow('Invalid TXAPI response')
  })
})

describe('native administrator queue monitoring contract', () => {
  it('uses native TXAPI for snapshot, bounded failures and redacted details', async () => {
    setAdminSecurePath('queue-admin')
    responder = config => ({
      data: {
        data: config.url?.endsWith('/snapshot') ? {
          status: 'inactive', connection: 'redis', processes: 0, observed_at: '2026-10-09T00:00:00Z',
        } : config.url?.endsWith('/failures/12') ? {
          id: 12, job: 'App\\Jobs\\SendEmailJob', exception: '[REDACTED]',
        } : [{
          id: 12, job: 'App\\Jobs\\SendEmailJob', message: '[REDACTED]',
        }],
        request_id: 'native-queue',
      },
    })
    await expect(getQueueSnapshot()).resolves.toMatchObject({ status: 'inactive' })
    await expect(getQueueFailures()).resolves.toMatchObject([{ id: 12 }])
    await expect(getQueueFailure(12)).resolves.toMatchObject({ id: 12 })
    expect(seen.map(request => [request.baseURL, request.url])).toEqual([
      ['/txapi', '/admin/queue-admin/queue/snapshot'],
      ['/txapi', '/admin/queue-admin/queue/failures'],
      ['/txapi', '/admin/queue-admin/queue/failures/12'],
    ])
    expect(seen[1].params).toEqual({ limit: 10 })
    await expect(getQueueFailure(0)).rejects.toThrow('Invalid queue failure ID')
  })

  it('does not accept a malformed native queue response', async () => {
    setAdminSecurePath('queue-admin')
    responder = () => ({ data: { data: { status: 'running' } } })
    await expect(getQueueSnapshot()).rejects.toThrow('Invalid TXAPI response')
  })
})

describe('native administrator coupon management contract', () => {
  it('translates legacy table filters and native pagination, without calling V2', async () => {
    setAdminSecurePath('coupon-admin')
    responder = () => ({ data: {
      data: [{ id: 7, code: 'SAVE50', type: 1, value: 5000 }],
      meta: { page: 2, per_page: 10, total: 11, last_page: 2 },
      request_id: 'coupon-read',
    } })
    const page = await getCoupons({ current: 2, pageSize: 10,
      filter: [{ id: 'code', value: 'SAVE' }, { id: 'type', value: 1 }] })
    expect(page).toMatchObject({ current_page: 2, per_page: 10, total: 11, last_page: 2 })
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/admin/coupon-admin/coupons')
    expect(seen[0].params).toEqual({ page: 2, per_page: 10, code: 'SAVE', type: 1 })
  })

  it('calls native create/edit, toggle and guarded delete', async () => {
    setAdminSecurePath('coupon-admin')
    responder = config => ({ data: {
      data: config.url?.endsWith('/toggle') ? { show: true }
        : config.method === 'delete' ? { ok: true } : { id: 7 },
      request_id: 'coupon-write',
    } })
    const payload = { name: 'Save', type: 1, value: 5000 }
    await saveCoupon(payload)
    await saveCoupon({ ...payload, id: 7 })
    await toggleCoupon(7)
    await deleteCoupon(7)
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['post', '/admin/coupon-admin/coupons'],
      ['put', '/admin/coupon-admin/coupons/7'],
      ['patch', '/admin/coupon-admin/coupons/7/toggle'],
      ['delete', '/admin/coupon-admin/coupons/7'],
    ])
    await expect(deleteCoupon(0)).rejects.toThrow('Invalid coupon ID')
  })

  it('rejects malformed coupon pagination envelopes', async () => {
    setAdminSecurePath('coupon-admin')
    responder = () => ({ data: { data: [] } })
    await expect(getCoupons({})).rejects.toThrow('Invalid native coupons page response')
  })
})

describe('native administrator mail template contract', () => {
  it('reads, saves, resets and tests mail templates through dynamic admin TXAPI', async () => {
    setAdminSecurePath('mail-admin')
    responder = config => ({
      data: { data: config.url === '/admin/mail-admin/mail-templates'
        ? [{ name: 'notify', label: '通知', customized: false }]
        : config.url?.endsWith('/notify') && config.method === 'get'
          ? { name: 'notify', label: '通知', content: '{{content}}' }
          : { ok: true }, request_id: 'mail-native' },
    })
    await expect(listMailTemplates()).resolves.toHaveLength(1)
    await expect(getMailTemplate('notify')).resolves.toMatchObject({ name: 'notify' })
    await expect(saveMailTemplate({ name: 'notify', subject: 'Hi', content: '{{content}}' }))
      .resolves.toEqual({ ok: true })
    await expect(resetMailTemplate('notify')).resolves.toEqual({ ok: true })
    await expect(testMailTemplate('notify', 'admin@example.test')).resolves.toEqual({ ok: true })
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['get', '/admin/mail-admin/mail-templates'],
      ['get', '/admin/mail-admin/mail-templates/notify'],
      ['put', '/admin/mail-admin/mail-templates/notify'],
      ['delete', '/admin/mail-admin/mail-templates/notify'],
      ['post', '/admin/mail-admin/mail-templates/notify/test'],
    ])
    expect(JSON.parse(String(seen[2].data))).toEqual({ subject: 'Hi', content: '{{content}}' })
    expect(JSON.parse(String(seen[4].data))).toEqual({ email: 'admin@example.test' })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })
})


describe('native machine management contract', () => {
  it('pages list and nodes through TXAPI with native metadata', async () => {
    setAdminSecurePath('machine-admin')
    responder = config => {
      const page = Number((config.params as { page?: number })?.page)
      return { data: {
        data: page === 1 ? [{ id: 3, name: 'machine-3' }] : [{ id: 4, name: 'machine-4' }],
        meta: { page, per_page: 100, total: 2, last_page: 2 },
        request_id: 'machine-pages',
      } }
    }
    await expect(getMachines()).resolves.toHaveLength(2)
    await expect(getMachineNodes(3)).resolves.toHaveLength(2)
    expect(seen.map(config => config.url)).toEqual([
      '/admin/machine-admin/network-machines', '/admin/machine-admin/network-machines',
      '/admin/machine-admin/network-machines/3/nodes', '/admin/machine-admin/network-machines/3/nodes',
    ])
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
    expect(seen.map(config => (config.params as { page?: number }).page)).toEqual([1, 2, 1, 2])
  })

  it('creates, edits, reveals and rotates credentials without GET secrets', async () => {
    setAdminSecurePath('machine-admin')
    responder = config => ({ data: {
      data: config.url?.endsWith('/credentials') || config.url?.endsWith('/token/rotate')
        ? { token: 'machine-secret', install_command: 'install --token machine-secret' }
        : config.url?.endsWith('/history') ? []
          : config.url?.endsWith('/runtime/update')
            ? { machine_id: 3, request_id: 'mup_test', target: 'latest', status: 'accepted' }
            : config.method === 'post' ? { id: 3, token: 'new-token', install_command: 'install' }
              : { ok: true },
      request_id: 'machine-write',
    } })

    await saveMachine({ name: 'machine-3' })
    await saveMachine({ id: 3, name: 'renamed' })
    await expect(getMachineCredentials(3)).resolves.toHaveProperty('token', 'machine-secret')
    await expect(resetMachineToken(3)).resolves.toHaveProperty('install_command')
    await expect(updateMachineRuntime(3)).resolves.toHaveProperty('request_id', 'mup_test')
    await getMachineHistory(3, 240, 6)
    await deleteMachine(3)

    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['post', '/admin/machine-admin/network-machines'],
      ['put', '/admin/machine-admin/network-machines/3'],
      ['post', '/admin/machine-admin/network-machines/3/credentials'],
      ['post', '/admin/machine-admin/network-machines/3/token/rotate'],
      ['post', '/admin/machine-admin/network-machines/3/runtime/update'],
      ['get', '/admin/machine-admin/network-machines/3/history'],
      ['delete', '/admin/machine-admin/network-machines/3'],
    ])
    expect(JSON.parse(String(seen[4].data))).toEqual({ target: 'latest' })
    expect(seen[5].params).toEqual({ limit: 240, range_hours: 6 })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
    expect(() => getMachineCredentials(0)).toThrow('Invalid machine ID')
  })
})


describe('native Agent administrator contract', () => {
  it('uses only rotating TXAPI paths and paginates the admin-owned token inventory', async () => {
    setAdminSecurePath('agent-native')
    responder = config => {
      const page = Number((config.params as { page?: number })?.page)
      return { data: {
        data: page === 1 ? [{ id: 1, client_name: 'owner', abilities: ['nodes.read'] }]
          : [{ id: 2, client_name: 'scoped', abilities: ['nodes.read'] }],
        meta: { page, per_page: 100, total: 2, last_page: 2 },
        request_id: 'agent-list',
      } }
    }
    await expect(getAgentTokens()).resolves.toHaveLength(2)
    expect(seen.map(config => [config.method, config.url, (config.params as { page?: number })?.page]))
      .toEqual([
        ['get', '/admin/agent-native/agents/tokens', 1],
        ['get', '/admin/agent-native/agents/tokens', 2],
      ])
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })

  it('creates one-time token, scopes abilities and revokes via DELETE not V2 POST', async () => {
    setAdminSecurePath('agent-native')
    responder = () => ({ data: {
      data: { id: 7, client_name: 'agent', plain_text_token: 'once-only', abilities: ['nodes.read'], pairing: null },
      request_id: 'agent-created',
    } })
    await expect(createAgentToken({
      client_name: 'agent', abilities: ['nodes.read'],
      expires_in_days: 7, target_mode: 'restricted', target_node_ids: [8],
    })).resolves.toHaveProperty('plain_text_token', 'once-only')
    await revokeAgentToken(7)
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['post', '/admin/agent-native/agents/tokens'],
      ['delete', '/admin/agent-native/agents/tokens/7'],
    ])
    expect(JSON.parse(String(seen[0].data)).target_node_ids).toEqual([8])
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
    expect(() => revokeAgentToken(0)).toThrow('Invalid Agent token ID')
  })

  it('routes actions, support approvals and fleet insight through native administrator scope', async () => {
    setAdminSecurePath('agent-native')
    responder = config => ({ data: {
      data: config.url?.endsWith('/abilities') ? { default_read: [], all: [] }
        : config.url?.endsWith('/fleet/health') ? { status: 'healthy', summary: {}, nodes: [], generated_at: 1 }
          : config.url?.endsWith('/inspections') && config.method === 'post'
            ? { inspection_id: 'inspection1', status: 'healthy' }
            : config.url?.includes('/support/reply-requests') && config.method === 'post'
              ? { request_id: 'reply1', ticket_id: 4, status: 'rejected', message: 'reviewed', created_at: 1 }
              : config.url?.includes('/actions/') ? { request_id: 'action1', status: 'rejected' }
                : [],
      request_id: 'agent-actions',
    } })
    await getAgentAbilities()
    await getAgentActions('pending')
    await approveAgentAction('action1')
    await rejectAgentAction('action1', 'not approved')
    await getAgentFleetHealth()
    await getAgentInspections(5)
    await runAgentInspection()
    await getAgentSupportReplies()
    await approveAgentSupportReply('reply1')
    await rejectAgentSupportReply('reply1')
    expect(seen.map(config => config.url)).toEqual([
      '/admin/agent-native/agents/abilities',
      '/admin/agent-native/agents/actions',
      '/admin/agent-native/agents/actions/approve',
      '/admin/agent-native/agents/actions/reject',
      '/admin/agent-native/agents/fleet/health',
      '/admin/agent-native/agents/inspections',
      '/admin/agent-native/agents/inspections',
      '/admin/agent-native/agents/support/reply-requests',
      '/admin/agent-native/agents/support/reply-requests/approve',
      '/admin/agent-native/agents/support/reply-requests/reject',
    ])
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
    expect(seen[1].params).toEqual({ status: 'pending', limit: 50 })
    expect(JSON.parse(String(seen[3].data))).toEqual({ request_id: 'action1', reason: 'not approved' })
  })
})


describe('native analytics administrator API contracts', () => {
  it('reads dashboard cards, order history and node/user traffic rank through TXAPI', async () => {
    setAdminSecurePath('analytics-dynamic')
    responder = config => ({ data: {
      data: config.url?.endsWith('/dashboard') ? { todayIncome: 2500, totalUsers: 14 }
        : config.url?.endsWith('/orders/chart')
          ? { list: [{ date: '2026-10-09', paid_total: 2500 }], summary: { paid_total: 2500 } }
          : config.url?.endsWith('/traffic/rank')
            ? [{ id: '5', name: 'node', value: 1000 }]
            : [{ id: '7', value: 43 }],
      request_id: 'analytics-native',
    } })
    await expect(getDashboardStats()).resolves.toHaveProperty('todayIncome', 2500)
    await expect(getOrderChart({ start_date: '2026-10-01', end_date: '2026-10-09' }))
      .resolves.toHaveProperty('summary.paid_total', 2500)
    await expect(getTrafficRank('node', 1790812800, 1791504000)).resolves.toHaveLength(1)
    await expect(getAnalyticsRanking('invite_rank', 10)).resolves.toHaveLength(1)
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['get', '/admin/analytics-dynamic/analytics/dashboard'],
      ['get', '/admin/analytics-dynamic/analytics/orders/chart'],
      ['get', '/admin/analytics-dynamic/analytics/traffic/rank'],
      ['get', '/admin/analytics-dynamic/analytics/rankings'],
    ])
    expect(seen[1].params).toEqual({ start_date: '2026-10-01', end_date: '2026-10-09' })
    expect(seen[2].params).toEqual({
      type: 'node', start_time: 1790812800, end_time: 1791504000,
    })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })

  it('validates the native paginated user traffic response and refuses unsafe IDs', async () => {
    setAdminSecurePath('analytics-dynamic')
    responder = () => ({ data: {
      data: [{ id: 42, user_id: 9, u: 100, d: 200 }],
      meta: { page: 2, per_page: 1, total: 3, last_page: 3 },
      request_id: 'user-traffic',
    } })
    await expect(getUserTrafficStats(9, 2, 1)).resolves.toMatchObject({
      total: 3, current_page: 2, per_page: 1,
      data: [{ id: 42, user_id: 9 }],
    })
    expect(seen[0].url).toBe('/admin/analytics-dynamic/analytics/users/9/traffic')
    expect(seen[0].params).toEqual({ page: 2, per_page: 1 })
    expect(seen[0].baseURL).toBe('/txapi')
    await expect(getUserTrafficStats(-2)).rejects.toThrow('Invalid user ID')
  })
})

describe('strict native administrator closeout contracts', () => {
  it('reads module registry under rotating TXAPI administrator prefix', async () => {
    setAdminSecurePath('cutover')
    responder = () => ({ data: {
      data: { modules: [{ id: 'agent_ops', type: 'agent' }], errors: [], summary: { total: 1 } },
      request_id: 'modules-native',
    } })
    await expect(getModuleRegistry()).resolves.toHaveProperty('summary.total', 1)
    expect(seen.map(config => [config.method, config.url, config.baseURL])).toEqual([
      ['get', '/admin/cutover/modules', '/txapi'],
    ])
  })

  it('creates pending order and reviews commission without V2 fallback', async () => {
    setAdminSecurePath('cutover')
    responder = config => ({ data: {
      data: config.url?.endsWith('/assign') ? { trade_no: 'TX-PENDING-01' } : { ok: true },
      request_id: 'orders-native',
    } })
    await expect(assignOrder({
      email: 'test@example.test', plan_id: 1, period: 'month_price', total_amount: 1900,
    })).resolves.toBe('TX-PENDING-01')
    await expect(updateOrderCommission('TX-PENDING-01', 1)).resolves.toBe(true)
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['post', '/admin/cutover/orders/assign'],
      ['post', '/admin/cutover/orders/TX-PENDING-01/commission-review'],
    ])
    expect(JSON.parse(String(seen[1].data))).toEqual({ commission_status: 1 })
    expect(seen.every(config => config.baseURL === '/txapi')).toBe(true)
  })

  it('queues selected user mail using the native, bounded admin request', async () => {
    setAdminSecurePath('cutover')
    responder = () => ({ data: { data: { queued: 2 }, request_id: 'mail-native' } })
    await expect(sendUsersMail({
      scope: 'selected', user_ids: [2, 3],
      subject: 'Notice', content: 'Message',
    })).resolves.toBe(2)
    expect(seen.map(config => [config.method, config.url])).toEqual([
      ['post', '/admin/cutover/users/mail'],
    ])
    expect(seen[0].baseURL).toBe('/txapi')
    expect(JSON.parse(String(seen[0].data)).user_ids).toEqual([2, 3])
  })
})

describe('plugin-owned boundary remains separate from legacy V2 admin', () => {
  it('accepts explicit plugin routes but rejects retired admin and external paths', async () => {
    expect(resolvePluginCrudApiPath('demo', 'items', 'list', {
      api: { list: '/plugin/demo/items', save: '/plugin/demo/items' },
    })).toBe('/plugin/demo/items')
    expect(resolvePluginCrudApiPath('demo', 'items', 'list', {
      api: { list: '/api/v2/secure/user/fetch' },
    })).toBeNull()
    await expect(fetchPluginCrudList('/api/v2/secure/module')).rejects
      .toThrow('Plugin API must use a plugin-owned path')
    await expect(savePluginCrudRecord('/admin/secure/users', { id: 1 })).rejects
      .toThrow('Plugin API must use a plugin-owned path')
  })
})

describe('native management bootstrap contract', () => {
  it('reads CAPTCHA provider configuration only from native public site config', async () => {
    responder = () => ({ data: {
      data: { is_captcha: 1, captcha_type: 'turnstile', turnstile_site_key: 'site-key' },
      request_id: 'public-site',
    } })
    await expect(fetchGuestConfig()).resolves.toMatchObject({
      is_captcha: 1, captcha_type: 'turnstile', turnstile_site_key: 'site-key',
    })
    expect(seen.map(config => [config.method, config.url, config.baseURL]))
      .toEqual([['get', '/public/site-config', '/txapi']])
  })

  it('requires an explicit CAPTCHA activation flag, never silently disables it', async () => {
    responder = () => ({ data: { data: { captcha_type: 'turnstile' }, request_id: 'invalid-site' } })
    await expect(fetchGuestConfig()).rejects.toThrow('Invalid public CAPTCHA configuration')
  })

  it('signs in through native admin-auth, preserving captcha and scoped path', async () => {
    responder = () => ({ data: {
      data: {
        auth_data: 'Bearer generated-admin-token', is_admin: true, secure_path: 'rotated-secret',
      },
      request_id: 'native-admin-sign-in',
    } })
    await expect(login('admin@example.test', 'pass', { turnstile: 'captcha-test' }))
      .resolves.toMatchObject({ is_admin: true, secure_path: 'rotated-secret' })
    expect(seen.map(config => [config.method, config.url, config.baseURL]))
      .toEqual([['post', '/auth/admin/login', '/txapi']])
    expect(JSON.parse(String(seen[0].data))).toEqual({
      email: 'admin@example.test', password: 'pass', turnstile: 'captcha-test',
    })
  })
})
