import type { ComponentType } from 'react'
import type { PluginAdminMenu, PluginItem } from '../api/plugin'

export type PluginRendererProps = {
  plugin: PluginItem
  menu: PluginAdminMenu
}

// Host-native renderers are intentionally exceptional. Plugin Package v1
// plugins should ship admin/dist and declare admin_menus[].app instead of
// requiring a TXBoard frontend rebuild.
const renderers: Record<string, ComponentType<PluginRendererProps>> = {}

export function resolvePluginRenderer(name?: string) {
  if (!name) return null
  return renderers[name] || null
}
