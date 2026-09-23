import { afterEach, describe, expect, it, vi } from 'vitest'

async function helpersAt(path: string) {
  vi.resetModules()
  vi.stubEnv('VITE_STATIC_PREVIEW', '0')
  window.history.replaceState({}, '', path)
  return import('./basePath')
}

afterEach(() => {
  vi.unstubAllEnvs()
  window.history.replaceState({}, '', '/')
})

describe('admin secure-path redirect normalization', () => {
  it('strips the browser basename from a nested admin target', async () => {
    const { currentRouterTarget, toRouterTarget } = await helpersAt(
      '/rotated-admin-entry/config/system?tab=safe',
    )

    expect(currentRouterTarget()).toBe('/config/system?tab=safe')
    expect(
      toRouterTarget('/rotated-admin-entry/config/system?tab=safe'),
    ).toBe('/config/system?tab=safe')
  })

  it('maps the secure-path root back to the router root', async () => {
    const { currentRouterTarget, toRouterTarget } = await helpersAt(
      '/rotated-admin-entry/',
    )

    expect(currentRouterTarget()).toBe('/')
    expect(toRouterTarget('/rotated-admin-entry')).toBe('/')
  })

  it('leaves an already router-relative redirect unchanged', async () => {
    const { toRouterTarget } = await helpersAt('/rotated-admin-entry/sign-in')

    expect(toRouterTarget('/config/system?tab=safe')).toBe(
      '/config/system?tab=safe',
    )
  })

  it('does not turn protocol-relative redirects into local paths', async () => {
    const { toRouterTarget } = await helpersAt('/rotated-admin-entry/sign-in')

    expect(toRouterTarget('//example.com/admin')).toBe('//example.com/admin')
  })
})
