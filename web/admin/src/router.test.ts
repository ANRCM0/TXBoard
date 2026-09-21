import { afterEach, describe, expect, it, vi } from 'vitest'

/**
 * The production admin SPA is built under a fixed asset namespace, but the
 * browser router mounts at the first pathname segment (the live secure_path).
 * Static previews keep hash routing and therefore have no pathname basename.
 */
async function routerFor(preview: boolean, baseUrl: string, pathname: string) {
  vi.resetModules()
  vi.stubEnv('VITE_STATIC_PREVIEW', preview ? '1' : '0')
  vi.stubEnv('BASE_URL', baseUrl)
  window.history.replaceState({}, '', pathname)
  const { router } = await import('./router')
  return router
}

afterEach(() => {
  vi.unstubAllEnvs()
  window.history.replaceState({}, '', '/')
})

describe('admin router basename', () => {
  const IMPORT_TIMEOUT_MS = 30_000

  it('ignores the Pages mount prefix for the hash router', async () => {
    const router = await routerFor(true, '/TXBoard/admin/', '/TXBoard/admin/')
    expect(router.basename).toBe('/')
  }, IMPORT_TIMEOUT_MS)

  it('uses the runtime secure path instead of the Vite asset base', async () => {
    const router = await routerFor(
      false,
      '/.txboard-admin/',
      '/rotated-admin-entry/config/system',
    )
    expect(router.basename).toBe('/rotated-admin-entry')
  }, IMPORT_TIMEOUT_MS)
})
