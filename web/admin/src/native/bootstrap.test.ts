import { afterEach, expect, it, vi } from 'vitest'
import { fetchGuestConfig } from '../api/comm'

afterEach(() => { vi.unstubAllGlobals() })
it('uses only the public Core browser bootstrap, without cookies or actor credentials', async () => {
  const send = vi.fn<typeof fetch>().mockResolvedValue(Response.json({ ok: true, data: { security: {
    captcha: { enabled: true, type: 'turnstile', siteKey: 'public-site-key' },
  } } }))
  vi.stubGlobal('fetch', send)
  expect(await fetchGuestConfig()).toEqual({ is_captcha: 1, captcha_type: 'turnstile', turnstile_site_key: 'public-site-key' })
  expect(send).toHaveBeenCalledWith('/gateway/v1/bootstrap', expect.objectContaining({
    credentials: 'omit', redirect: 'error', headers: { Accept: 'application/json' },
  }))
})
it('rejects malformed or unavailable enabled CAPTCHA instead of disabling it', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ ok: true, data: { security: {
    captcha: { enabled: true, type: null, siteKey: null },
  } } })))
  await expect(fetchGuestConfig()).rejects.toBeDefined()
})
it('bounds public bootstrap response bytes', async () => {
  const send = vi.fn<typeof fetch>().mockResolvedValue(Response.json({ padding: 'x'.repeat(262_144) }))
  vi.stubGlobal('fetch', send)
  await expect(fetchGuestConfig()).rejects.toThrow('TOO_LARGE')
})
