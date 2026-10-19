import { afterEach, expect, it, vi } from 'vitest'
import { fetchGuestConfig } from '../api/comm'
import { nativeApiClient, pluginApiClient, nativeAdminPath } from '../api/client'

afterEach(() => { vi.unstubAllGlobals() })
it('uses only the public Gateway bootstrap, without cookies or actor credentials', async () => {
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
it('bounds public bootstrap bytes and blocks all dormant original HTTP adapters', async () => {
  const send = vi.fn<typeof fetch>().mockResolvedValue(Response.json({ padding: 'x'.repeat(262_144) }))
  vi.stubGlobal('fetch', send)
  await expect(fetchGuestConfig()).rejects.toThrow('TOO_LARGE')
  send.mockClear()
  await expect(nativeApiClient.get('/admin/old/users')).rejects.toThrow('NOT_ADAPTED')
  await expect(pluginApiClient.get('/plugin/old')).rejects.toThrow('NOT_ADAPTED')
  expect(() => nativeAdminPath('users')).toThrow('NOT_ADAPTED')
  expect(send).not.toHaveBeenCalled()
})
