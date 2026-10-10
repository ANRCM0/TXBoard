import type { PluginItem } from '../api/plugin'
import { nativeAdminPath, nativeApiClient, pluginApiClient } from '../api/client'
import { getAuthorizationHeader } from '../lib/storage'

export const PLUGIN_BRIDGE_VERSION = 1
export const MODULE_BRIDGE_VERSION = 2

export const MODULE_BRIDGE_SERVICES = [
  'navigate',
  'toast',
  'confirm',
  'refresh',
  'open-user',
  'open-node',
  'open-machine',
  'get-theme',
] as const

export type HostThemeMode = 'light' | 'dark'

export type PluginBridgeInit = {
  type: 'txboard:plugin:init'
  version: 1
  plugin: {
    code: string
    name?: string
    version?: string
  }
  route: {
    path: string
  }
  api: {
    root: string
    admin: string
  }
  auth: {
    authorization: string
  }
}

export type ModuleBridgeInit = {
  type: 'txboard:module:init'
  version: 2
  module: {
    id: string
    type: 'plugin'
    name?: string
    version?: string
  }
  route: {
    path: string
  }
  api: {
    root: string
    admin: string
  }
  auth: {
    authorization: string
  }
  theme: {
    mode: HostThemeMode
  }
  services: string[]
}

export type ModuleBridgeRequest =
  | { kind: 'ready' }
  | { kind: 'navigate'; path: string }
  | { kind: 'toast'; level: 'success' | 'info' | 'warning' | 'error'; message: string }
  | {
      kind: 'confirm'
      requestId: string
      title: string
      message?: string
      danger: boolean
      confirmLabel?: string
      cancelLabel?: string
    }
  | { kind: 'refresh' }
  | { kind: 'open-user' | 'open-node' | 'open-machine'; id: string }
  | { kind: 'get-theme'; requestId: string }

/** Plugins adopt TXAPI's rotating administrator scope; no V2 fallback. */
function nativeAdminApiRoot(): string {
  const prefix = String(nativeApiClient.defaults.baseURL || '/txapi').replace(/\/$/, '')
  return prefix + nativeAdminPath('plugins').slice(0, -'/plugins'.length)
}

export function buildPluginBridgeInit(plugin: PluginItem, path: string): PluginBridgeInit {
  return {
    type: 'txboard:plugin:init',
    version: PLUGIN_BRIDGE_VERSION,
    plugin: {
      code: plugin.code,
      name: plugin.name,
      version: plugin.version,
    },
    route: { path },
    api: {
      root: String(pluginApiClient.defaults.baseURL || ''),
      admin: nativeAdminApiRoot(),
    },
    auth: {
      authorization: getAuthorizationHeader(),
    },
  }
}

export function buildModuleBridgeInit(plugin: PluginItem, path: string): ModuleBridgeInit {
  return {
    type: 'txboard:module:init',
    version: MODULE_BRIDGE_VERSION,
    module: {
      id: plugin.code,
      type: 'plugin',
      name: plugin.name,
      version: plugin.version,
    },
    route: { path },
    api: {
      root: String(pluginApiClient.defaults.baseURL || ''),
      admin: nativeAdminApiRoot(),
    },
    auth: {
      authorization: getAuthorizationHeader(),
    },
    theme: {
      mode: getHostThemeMode(),
    },
    services: [...MODULE_BRIDGE_SERVICES],
  }
}

export function getHostThemeMode(): HostThemeMode {
  return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'
}

export function normalizePluginNavigationTarget(value: unknown) {
  const path = String(value || '').trim().replace(/^\/+|\/+$/g, '')
  if (!path || path === '.' || path === '..') return null
  if (path.includes('\\') || /^[a-z][a-z0-9+.-]*:/i.test(path)) return null
  if (!/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(path)) return null
  return path
}

export function parseModuleBridgeRequest(value: unknown): ModuleBridgeRequest | null {
  if (!value || typeof value !== 'object') return null

  const message = value as Record<string, unknown>
  if (message.version !== MODULE_BRIDGE_VERSION || typeof message.type !== 'string') {
    return null
  }

  switch (message.type) {
    case 'txboard:module:ready':
      return { kind: 'ready' }

    case 'txboard:module:navigate': {
      const path = normalizePluginNavigationTarget(message.path)
      return path ? { kind: 'navigate', path } : null
    }

    case 'txboard:module:toast': {
      const level = message.level
      const text = boundedText(message.message, 1, 1000)
      if (
        !text ||
        !['success', 'info', 'warning', 'error'].includes(String(level))
      ) {
        return null
      }

      return {
        kind: 'toast',
        level: String(level) as 'success' | 'info' | 'warning' | 'error',
        message: text,
      }
    }

    case 'txboard:module:confirm': {
      const requestId = requestIdentifier(message.request_id)
      const title = boundedText(message.title, 1, 200)
      if (!requestId || !title) return null

      const confirmLabel = optionalText(message.confirm_label, 80)
      const cancelLabel = optionalText(message.cancel_label, 80)
      const detail = optionalText(message.message, 1000)
      if (
        confirmLabel === false ||
        cancelLabel === false ||
        detail === false ||
        (message.danger !== undefined && typeof message.danger !== 'boolean')
      ) {
        return null
      }

      return {
        kind: 'confirm',
        requestId,
        title,
        message: detail || undefined,
        danger: message.danger === true,
        confirmLabel: confirmLabel || undefined,
        cancelLabel: cancelLabel || undefined,
      }
    }

    case 'txboard:module:refresh':
      return { kind: 'refresh' }

    case 'txboard:module:open-user':
    case 'txboard:module:open-node':
    case 'txboard:module:open-machine': {
      const id = positiveIntegerIdentifier(message.id)
      if (!id) return null

      return {
        kind: message.type.replace('txboard:module:', '') as
          | 'open-user'
          | 'open-node'
          | 'open-machine',
        id,
      }
    }

    case 'txboard:module:get-theme': {
      const requestId = requestIdentifier(message.request_id)
      return requestId ? { kind: 'get-theme', requestId } : null
    }

    default:
      return null
  }
}

export function buildModuleBridgeConfirmResult(requestId: string, confirmed: boolean) {
  return {
    type: 'txboard:module:confirm-result' as const,
    version: MODULE_BRIDGE_VERSION,
    request_id: requestId,
    confirmed,
  }
}

export function buildModuleBridgeThemeResult(requestId: string, mode = getHostThemeMode()) {
  return {
    type: 'txboard:module:theme' as const,
    version: MODULE_BRIDGE_VERSION,
    request_id: requestId,
    mode,
  }
}

function boundedText(value: unknown, min: number, max: number) {
  if (typeof value !== 'string') return null
  const text = value.trim()
  return text.length >= min && text.length <= max ? text : null
}

function optionalText(value: unknown, max: number): string | null | false {
  if (value === undefined || value === null || value === '') return null
  if (typeof value !== 'string') return false
  const text = value.trim()
  return text && text.length <= max ? text : false
}

function requestIdentifier(value: unknown) {
  if (typeof value !== 'string') return null
  const id = value.trim()
  if (!id || id.length > 120 || !/^[A-Za-z0-9._:-]+$/.test(id)) return null
  return id
}

function positiveIntegerIdentifier(value: unknown) {
  const id = String(value ?? '').trim()
  if (!/^[1-9][0-9]*$/.test(id)) return null
  return id
}
