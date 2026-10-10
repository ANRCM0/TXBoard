import { pluginApiClient } from './client'
import { unwrap } from '../lib/api'

export type PluginOption = {
  label?: string
  value?: string | number
  variant?: string
}

export type PluginConfigField = {
  type?: string
  label?: string
  placeholder?: string
  description?: string
  value?: unknown
  options?: PluginOption[]
  required?: boolean
  hidden?: boolean
  readonly?: boolean
}

export type PluginAdminMenu = {
  id?: string
  title?: string
  label?: string
  path?: string
  icon?: string
  description?: string
  url?: string
  embed?: string
  component?: string
  renderer?: string
  app?: string
}

export type PluginAdminCrudColumn = {
  key: string
  title?: string
  type?: 'string' | 'number' | 'boolean' | 'datetime' | 'tag' | string
  sortable?: boolean
  searchable?: boolean
  width?: number
  options?: PluginOption[]
}

export type PluginAdminCrudFormField = PluginConfigField & {
  name: string
}

export type PluginAdminCrudSchema = {
  version?: number
  title?: string
  description?: string
  id_field?: string
  api?: {
    list?: string
    save?: string
    delete?: string
  }
  columns?: PluginAdminCrudColumn[]
  form?: Record<string, PluginConfigField> | PluginAdminCrudFormField[]
  actions?: {
    create?: boolean
    edit?: boolean
    delete?: boolean
  }
}

export type PluginItem = {
  code: string
  name?: string
  version?: string
  description?: string
  author?: string
  type?: string
  is_installed?: boolean
  is_enabled?: boolean
  is_protected?: boolean
  can_be_deleted?: boolean
  need_upgrade?: boolean
  readme?: string
  config?: Record<string, PluginConfigField>
  admin_crud?: Record<string, PluginAdminCrudSchema> | null
  admin_menus?: PluginAdminMenu[] | null
  package?: { schema?: number; [key: string]: unknown } | null
  asset_base?: string
  [key: string]: unknown
}

export type PluginCrudPage = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: Record<string, unknown>[]
}

export {
  getPlugins, getPluginConfig, updatePluginConfig,
  installPlugin, uninstallPlugin, enablePlugin, disablePlugin,
  upgradePlugin, deletePlugin, uploadPlugin,
} from './plugin-admin'

export function normalizePluginPath(path?: string) {
  if (!path) return ''
  return path.trim().replace(/^\/+/, '').replace(/\/+$/, '')
}


export function resolvePluginAppUrl(plugin: Pick<PluginItem, 'code' | 'asset_base'>, menu: PluginAdminMenu) {
  const app = menu.app?.trim()
  if (!app) return null
  if (
    app.startsWith('/') ||
    app.includes('\\') ||
    /^[a-z][a-z0-9+.-]*:/i.test(app)
  ) return null

  const path = app.split(/[?#]/, 1)[0]
  if (!path.startsWith('admin/') || !path.toLowerCase().endsWith('.html')) return null
  if (!/^[A-Za-z0-9._/-]+$/.test(path)) return null
  if (path.split('/').some(segment => !segment || segment === '.' || segment === '..')) return null

  const base = (plugin.asset_base?.trim() || `/plugins/${encodeURIComponent(plugin.code)}`).replace(/\/+$/, '')
  return `${base}/${app}`
}

export function resolvePluginCrudApiPath(
  _pluginCode: string,
  _subpath: string,
  action: 'list' | 'save' | 'delete',
  schema?: PluginAdminCrudSchema,
) {
  const configured = schema?.api?.[action]?.trim()
  if (!configured || !isPluginApiPath(configured)) return null
  return configured
}

// Plugin-manifest APIs must be owned by the named plugin; no legacy admin fallback.
function isPluginApiPath(path: string): boolean {
  return /^\/plugin\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_.-]+)*\/?(?:\?[A-Za-z0-9_.=%&-]+)?$/.test(path) &&
    !path.split(/[/?]/).some(part => part === '.' || part === '..')
}
function checkedPluginApiPath(path: string): string {
  if (!isPluginApiPath(path)) throw new Error('Plugin API must use a plugin-owned path')
  return path
}

export async function fetchPluginCrudList(
  path: string,
  params: {
    current?: number
    pageSize?: number
    search?: string
    sort_field?: string
    sort_order?: 'asc' | 'desc'
  } = {},
): Promise<PluginCrudPage> {
  const { data } = await pluginApiClient.get(checkedPluginApiPath(path), { params })
  return normalizeCrudPage(data, params.current, params.pageSize)
}

export async function savePluginCrudRecord(path: string, payload: Record<string, unknown>) {
  const { data } = await pluginApiClient.post(checkedPluginApiPath(path), payload)
  return unwrap(data)
}

export async function deletePluginCrudRecord(path: string, payload: Record<string, unknown>) {
  const { data } = await pluginApiClient.post(checkedPluginApiPath(path), payload)
  return unwrap(data)
}

export async function fetchPluginMenuHtml(component?: string): Promise<string | null> {
  const path = component?.trim()
  if (!path || /^https?:\/\//i.test(path)) return null

  if (!isPluginApiPath(path) &&
      !/^\/plugins\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_.-]+)*\/?$/.test(path)) return null

  try {
    const response = await pluginApiClient.get(path, {
      headers: { Accept: 'text/html,application/json' },
      responseType: 'text',
      transformResponse: [(value) => value],
    })
    const data: unknown = response.data

    if (typeof data !== 'string') return null
    const trimmed = data.trim()
    if (!trimmed) return null
    if (trimmed.startsWith('<')) return trimmed

    try {
      const parsed = JSON.parse(trimmed) as { data?: { html?: string }; html?: string }
      return parsed.data?.html ?? parsed.html ?? null
    } catch {
      return null
    }
  } catch {
    return null
  }
}

function normalizeCrudPage(raw: unknown, current = 1, pageSize = 20): PluginCrudPage {
  if (Array.isArray(raw)) {
    return {
      total: raw.length,
      current_page: current,
      per_page: pageSize,
      last_page: Math.max(1, Math.ceil(raw.length / Math.max(1, pageSize))),
      data: raw as Record<string, unknown>[],
    }
  }

  const record = raw && typeof raw === 'object' ? raw as Record<string, unknown> : {}
  const nested = record.data && typeof record.data === 'object' && !Array.isArray(record.data)
    ? record.data as Record<string, unknown>
    : null

  const source = nested && Array.isArray(nested.data) ? nested : record
  const rows = Array.isArray(source.data) ? source.data as Record<string, unknown>[] : []

  return {
    total: Number(source.total ?? rows.length),
    current_page: Number(source.current_page ?? current),
    per_page: Number(source.per_page ?? pageSize),
    last_page: Number(source.last_page ?? Math.max(1, Math.ceil(Number(source.total ?? rows.length) / Math.max(1, pageSize)))),
    data: rows,
  }
}
