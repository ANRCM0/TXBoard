import { afterEach, expect, it, vi } from 'vitest'
import { fetchGuestConfig, publicLogo } from '../api/comm'

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
it('projects branding without weakening CAPTCHA validation and rejects unsafe logo URLs', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ ok: true, data: {
    site: { name: 'Native site', description: 'Configured description', logo: 'javascript:alert(1)' },
    security: { captcha: { enabled: false, type: null, siteKey: null } },
  } })))
  expect(await fetchGuestConfig()).toEqual({ is_captcha: 0, site: { name: 'Native site', description: 'Configured description', logo: null } })
  for (const url of ['data:image/svg+xml,unsafe', '//other.example/logo', '/\\other.example/logo', 'https://user:password@example.com/logo', ' /logo.png']) expect(publicLogo(url)).toBeNull()
  expect(publicLogo('/brand.png')).toBe('/brand.png')
  expect(publicLogo('https://cdn.example/brand.png')).toBe('https://cdn.example/brand.png')
})
it('ignores malformed optional branding while preserving an enabled CAPTCHA', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ ok: true, data: {
    site: { name: ['bad'], description: null }, security: { captcha: { enabled: true, type: 'turnstile', siteKey: 'public-key' } },
  } })))
  expect(await fetchGuestConfig()).toEqual({ is_captcha: 1, captcha_type: 'turnstile', turnstile_site_key: 'public-key' })
})
it.each([
  { enabled: false },
  { enabled: 0, type: null, siteKey: null },
  { enabled: false, type: 'turnstile', siteKey: null },
  { enabled: true, type: ['turnstile'], siteKey: 'public-key' },
  { enabled: true, type: 'turnstile', siteKey: ' '.repeat(12) },
  { enabled: true, type: 'turnstile', siteKey: 'x'.repeat(2049) },
])('rejects an invalid CAPTCHA discriminator or key: %j', async captcha => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ ok: true, data: { security: { captcha } } })))
  await expect(fetchGuestConfig()).rejects.toThrow('PUBLIC_CAPTCHA_INVALID')
})
