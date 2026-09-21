import type { ComponentType } from 'react'
import type { PluginAdminMenu, PluginItem } from '../api/plugin'
import { AccessAuditAnalytics } from './access-audit/AccessAuditAnalytics'
import { AccessAuditDashboard } from './access-audit/AccessAuditDashboard'

export type PluginRendererProps = {
  plugin: PluginItem
  menu: PluginAdminMenu
}

const renderers: Record<string, ComponentType<PluginRendererProps>> = {
  'access-audit-dashboard': AccessAuditDashboard,
  'access-audit-analytics': AccessAuditAnalytics,
}

export function resolvePluginRenderer(name?: string) {
  if (!name) return null
  return renderers[name] || null
}
