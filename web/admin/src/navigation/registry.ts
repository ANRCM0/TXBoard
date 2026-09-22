import type { ModuleDescriptor } from '../api/module'

export type ModuleNavigationItem = {
  id: string
  title: string
  path: string
  href: string
  icon?: string
  order: number
}

export type ModuleNavigationGroup = {
  moduleId: string
  title: string
  version?: string
  items: ModuleNavigationItem[]
}

export function normalizeModuleNavigationPath(value: unknown) {
  const path = String(value || '').trim().replace(/^\/+|\/+$/g, '')
  if (!path || path === '.' || path === '..') return null
  if (path.includes('\\') || /^[a-z][a-z0-9+.-]*:/i.test(path)) return null
  if (!/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(path)) return null
  return path
}

export function buildModuleNavigationGroups(
  modules: readonly ModuleDescriptor[],
): ModuleNavigationGroup[] {
  return modules
    .filter(module =>
      module.type === 'plugin' &&
      module.installed &&
      module.enabled &&
      Boolean(module.admin?.navigation?.length),
    )
    .map(module => {
      const items = (module.admin?.navigation || [])
        .flatMap(item => {
          const path = normalizeModuleNavigationPath(item.path)
          if (!path) return []

          const navigation: ModuleNavigationItem = {
            id: item.id,
            title: item.title,
            path,
            href: `/plugins/${module.id}/${path}`,
            order: Number.isInteger(item.order) ? Number(item.order) : 0,
          }

          if (item.icon) navigation.icon = item.icon

          return [navigation]
        })
        .sort((a, b) =>
          a.order - b.order ||
          a.title.localeCompare(b.title) ||
          a.path.localeCompare(b.path),
        )

      return {
        moduleId: module.id,
        title: module.name || module.id,
        version: module.version,
        items,
      }
    })
    .filter(group => group.items.length > 0)
    .sort((a, b) =>
      a.title.localeCompare(b.title) ||
      a.moduleId.localeCompare(b.moduleId),
    )
}
