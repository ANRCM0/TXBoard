import { beforeEach, describe, expect, it } from 'vitest'
import type { PluginItem } from '../api/plugin'
import { setAdminSecurePath } from '../api/client'
import {
  MODULE_BRIDGE_SERVICES,
  MODULE_BRIDGE_VERSION,
  buildModuleBridgeConfirmResult,
  buildModuleBridgeInit,
  buildModuleBridgeThemeResult,
  normalizePluginNavigationTarget,
  parseModuleBridgeRequest,
} from './bridge'

const plugin: PluginItem = {
  code: 'access_audit',
  name: 'Access Audit',
  version: '1.2.0',
}

beforeEach(() => {
  document.documentElement.dataset.theme = 'dark'
  setAdminSecurePath('native-bridge')
})

describe('Admin Bridge compatibility', () => {
  it('keeps Bridge v1 navigation normalization strict', () => {
    expect(normalizePluginNavigationTarget('reports/daily')).toBe('reports/daily')
    expect(normalizePluginNavigationTarget('../config')).toBeNull()
    expect(normalizePluginNavigationTarget('https://example.com')).toBeNull()
    expect(normalizePluginNavigationTarget('reports\\daily')).toBeNull()
  })

  it('builds additive Bridge v2 init without changing Plugin identity', () => {
    const init = buildModuleBridgeInit(plugin, 'reports')

    expect(init).toMatchObject({
      type: 'txboard:module:init',
      version: MODULE_BRIDGE_VERSION,
      module: {
        id: 'access_audit',
        type: 'plugin',
        name: 'Access Audit',
        version: '1.2.0',
      },
      route: { path: 'reports' },
      theme: { mode: 'dark' },
    })
    expect(init.api.admin).toBe('/txapi/admin/native-bridge')
    expect(init.services).toEqual([...MODULE_BRIDGE_SERVICES])
  })

  it('parses bounded Bridge v2 host-service requests', () => {
    expect(parseModuleBridgeRequest({
      type: 'txboard:module:navigate',
      version: 2,
      path: 'reports/daily',
    })).toEqual({ kind: 'navigate', path: 'reports/daily' })

    expect(parseModuleBridgeRequest({
      type: 'txboard:module:toast',
      version: 2,
      level: 'success',
      message: 'Saved',
    })).toEqual({ kind: 'toast', level: 'success', message: 'Saved' })

    expect(parseModuleBridgeRequest({
      type: 'txboard:module:open-node',
      version: 2,
      id: 42,
    })).toEqual({ kind: 'open-node', id: '42' })

    expect(parseModuleBridgeRequest({
      type: 'txboard:module:get-theme',
      version: 2,
      request_id: 'theme-1',
    })).toEqual({ kind: 'get-theme', requestId: 'theme-1' })

    expect(parseModuleBridgeRequest({
      type: 'txboard:module:confirm',
      version: 2,
      request_id: 'delete-rule-7',
      title: 'Delete rule?',
      message: 'This action cannot be undone.',
      danger: true,
      confirm_label: 'Delete',
      cancel_label: 'Cancel',
    })).toEqual({
      kind: 'confirm',
      requestId: 'delete-rule-7',
      title: 'Delete rule?',
      message: 'This action cannot be undone.',
      danger: true,
      confirmLabel: 'Delete',
      cancelLabel: 'Cancel',
    })
  })

  it('rejects unsafe, malformed and unknown Bridge v2 requests', () => {
    expect(parseModuleBridgeRequest({
      type: 'txboard:module:navigate',
      version: 2,
      path: '../config',
    })).toBeNull()

    expect(parseModuleBridgeRequest({
      type: 'txboard:module:open-user',
      version: 2,
      id: 0,
    })).toBeNull()

    expect(parseModuleBridgeRequest({
      type: 'txboard:module:toast',
      version: 2,
      level: 'html',
      message: '<script>alert(1)</script>',
    })).toBeNull()

    expect(parseModuleBridgeRequest({
      type: 'txboard:module:shell',
      version: 2,
      command: 'id',
    })).toBeNull()

    expect(parseModuleBridgeRequest({
      type: 'txboard:module:ready',
      version: 1,
    })).toBeNull()
  })

  it('builds correlated confirm and theme responses', () => {
    expect(buildModuleBridgeConfirmResult('request-7', true)).toEqual({
      type: 'txboard:module:confirm-result',
      version: 2,
      request_id: 'request-7',
      confirmed: true,
    })

    expect(buildModuleBridgeThemeResult('theme-7', 'light')).toEqual({
      type: 'txboard:module:theme',
      version: 2,
      request_id: 'theme-7',
      mode: 'light',
    })
  })
})
