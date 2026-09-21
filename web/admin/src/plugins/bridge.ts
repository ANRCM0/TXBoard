import type { PluginItem } from '../api/plugin'
import { getResolvedApiPrefixes, pluginApiClient } from '../api/client'
import { getAuthorizationHeader } from '../lib/storage'

export const PLUGIN_BRIDGE_VERSION = 1

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
      admin: getResolvedApiPrefixes().admin,
    },
    auth: {
      authorization: getAuthorizationHeader(),
    },
  }
}

export function normalizePluginNavigationTarget(value: unknown) {
  const path = String(value || '').trim().replace(/^\/+|\/+$/g, '')
  if (!path || path === '.' || path === '..') return null
  if (!/^[A-Za-z0-9/_-]+$/.test(path)) return null
  if (path.split('/').some(segment => !segment || segment === '.' || segment === '..')) return null
  return path
}
