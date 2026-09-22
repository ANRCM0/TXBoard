import { describe, expect, it } from 'vitest'
import type { ModuleDescriptor } from '../api/module'
import {
  buildModuleNavigationGroups,
  normalizeModuleNavigationPath,
} from './registry'

function module(
  overrides: Partial<ModuleDescriptor> = {},
): ModuleDescriptor {
  return {
    id: 'access_audit',
    name: 'Access Audit',
    version: '1.2.0',
    type: 'plugin',
    source: 'user',
    installed: true,
    enabled: true,
    active: null,
    health: 'healthy',
    capabilities: ['admin.menu'],
    compatibility: { txboard: '*' },
    admin: {
      navigation: [
        { id: 'reports', title: 'Reports', path: 'reports', order: 20 },
        { id: 'dashboard', title: 'Dashboard', path: 'dashboard', order: 10 },
      ],
    },
    ...overrides,
  }
}

describe('Module Navigation Registry', () => {
  it('builds sorted host routes from enabled Plugin Module navigation', () => {
    const groups = buildModuleNavigationGroups([module()])

    expect(groups).toHaveLength(1)
    expect(groups[0]).toMatchObject({
      moduleId: 'access_audit',
      title: 'Access Audit',
      version: '1.2.0',
    })
    expect(groups[0].items.map(item => [item.title, item.href])).toEqual([
      ['Dashboard', '/plugins/access_audit/dashboard'],
      ['Reports', '/plugins/access_audit/reports'],
    ])
  })

  it('does not render disabled, uninstalled, or non-Plugin Module navigation', () => {
    expect(buildModuleNavigationGroups([
      module({ id: 'disabled', enabled: false }),
      module({ id: 'available', installed: false }),
      module({ id: 'theme.txboard', type: 'theme' }),
    ])).toEqual([])
  })

  it('defensively rejects unsafe navigation paths even after backend validation', () => {
    expect(normalizeModuleNavigationPath('reports/daily')).toBe('reports/daily')
    expect(normalizeModuleNavigationPath('/reports/')).toBe('reports')
    expect(normalizeModuleNavigationPath('../config')).toBeNull()
    expect(normalizeModuleNavigationPath('https://example.com')).toBeNull()
    expect(normalizeModuleNavigationPath('reports\\daily')).toBeNull()

    const groups = buildModuleNavigationGroups([
      module({
        admin: {
          navigation: [
            { id: 'safe', title: 'Safe', path: 'safe' },
            { id: 'unsafe', title: 'Unsafe', path: '../config' },
          ],
        },
      }),
    ])

    expect(groups[0].items.map(item => item.id)).toEqual(['safe'])
  })
})
