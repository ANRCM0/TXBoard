import { describe, expect, it } from 'vitest'
import { adminNavigation, adminSection, coreNavigationGroups } from './core'
import { availableAdminPaths } from '../native/routes'

describe('core admin navigation', () => {
  it('keeps every native destination reachable once from the grouped navigation', () => {
    const paths = adminNavigation.flatMap(group => group.items.map(([path]) => path))
    expect(new Set(paths).size).toBe(paths.length)
    expect([...paths].sort()).toEqual(['/', ...availableAdminPaths].sort())
    expect(coreNavigationGroups.flatMap(group => group.items.map(([path]) => path)).sort()).toEqual([...availableAdminPaths].sort())
  })
  it('selects nested settings before their parent and resolves resource details to the matching list', () => {
    expect(adminSection('/config/system/subscribe-template').item[0]).toBe('/config/system/subscribe-template')
    expect(adminSection('/server/node/9007199254740993').item[0]).toBe('/server/manage')
    expect(adminSection('/server/machine/9007199254740997').item[0]).toBe('/server/machine')
    expect(adminSection('/user/9007199254740993').group.key).toBe('users')
    expect(adminSection('/user/ticket').group.key).toBe('content')
  })
})
