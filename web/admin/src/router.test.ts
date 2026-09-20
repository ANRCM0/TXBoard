import { afterEach, describe, expect, it, vi } from 'vitest'

/**
 * Regression guard for the GitHub Pages admin white page.
 *
 * The preview builds with VITE_BASE_PATH=/TXBoard/admin/ and routes through the
 * URL fragment (`#/`). A hash router strips `basename` from the *fragment*
 * path, not from `window.location.pathname`, so passing the mount prefix as
 * basename made every route miss and rendered an empty page. The mount prefix
 * must only be used for the browser router, where it really does prefix the
 * pathname.
 */
async function routerFor(preview: boolean, baseUrl: string) {
  vi.resetModules()
  vi.stubEnv('VITE_STATIC_PREVIEW', preview ? '1' : '0')
  vi.stubEnv('BASE_URL', baseUrl)
  const { router } = await import('./router')
  return router
}

afterEach(() => {
  vi.unstubAllEnvs()
})

describe('admin router basename', () => {
  // Importing ./router pulls in the whole route tree (antd plus every page), which
  // costs several seconds on a cold module registry. The default 5s timeout made
  // this file fail whenever the machine was busy, so it gets its own budget.
  const IMPORT_TIMEOUT_MS = 30_000

  it('ignores the Pages mount prefix for the hash router', async () => {
    const router = await routerFor(true, '/TXBoard/admin/')
    expect(router.basename).toBe('/')
  }, IMPORT_TIMEOUT_MS)

  it('keeps the mount prefix for the browser router', async () => {
    const router = await routerFor(false, '/admin/')
    expect(router.basename).toBe('/admin')
  }, IMPORT_TIMEOUT_MS)
})
