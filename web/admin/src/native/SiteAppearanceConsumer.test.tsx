import { act, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { fetchSiteAppearance, parseSiteAppearance, safeLoginBackground, SiteAppearanceConsumer, SiteLoginBackground, siteAppearanceDefaults } from '../lib/useSiteAppearance'
import { readPublicBootstrap } from './publicBootstrap'

vi.mock('./publicBootstrap', () => ({ readPublicBootstrap: vi.fn() }))
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); vi.mocked(readPublicBootstrap).mockReset()
  document.documentElement.dataset.theme = 'dark'; localStorage.setItem('theme', 'dark')
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); for (const key of ['adminSidebarStyle', 'adminHeaderStyle', 'adminColorScheme', 'theme']) delete document.documentElement.dataset[key]; localStorage.clear() })
it('applies persisted shell styles while preserving the local dark preference and cleans up on unmount', async () => {
  const appearance = { ...siteAppearanceDefaults, admin_sidebar_style: 'contrast', admin_header_style: 'accent', admin_color_scheme: 'green' }
  client.setQueryData(['admin', 'site-appearance'], appearance)
  await act(async () => root.render(<StrictMode><QueryClientProvider client={client}><SiteAppearanceConsumer /></QueryClientProvider></StrictMode>))
  expect(document.documentElement.dataset.adminSidebarStyle).toBe('contrast'); expect(document.documentElement.dataset.adminHeaderStyle).toBe('accent'); expect(document.documentElement.dataset.adminColorScheme).toBe('green')
  expect(document.documentElement.dataset.theme).toBe('dark'); expect(localStorage.getItem('theme')).toBe('dark')
  await act(async () => root.render(<div />)); expect(document.documentElement.dataset.adminColorScheme).toBeUndefined()
})
it('renders the real login image without referrers and removes it on load failure', async () => {
  client.setQueryData(['admin', 'site-appearance'], { ...siteAppearanceDefaults, admin_login_background_url: 'https://images.example.test/login.jpg' })
  await act(async () => root.render(<QueryClientProvider client={client}><div className="admin-auth-page"><SiteLoginBackground /></div></QueryClientProvider>))
  const image = host.querySelector('img')!; expect(image.src).toBe('https://images.example.test/login.jpg'); expect(image.getAttribute('referrerpolicy')).toBe('no-referrer'); expect(image.getAttribute('crossorigin')).toBeNull()
  await act(async () => image.dispatchEvent(new Event('error'))); expect(host.querySelector('img')).toBeNull()
})
it('fails closed for unsafe image URLs and malformed bootstrap appearance without reading any administrator credentials', async () => {
  for (const value of ['javascript:bad', 'data:image/png;base64,a', '//images.example.test/x', 'https://user:pass@example.test/x', '/%5cevil.test/x', 'http://images.example.test/x']) expect(safeLoginBackground(value)).toBe('')
  expect(safeLoginBackground('/images/login.jpg')).toBe('/images/login.jpg')
  vi.mocked(readPublicBootstrap).mockResolvedValue({ ok: true, data: { admin_appearance: { admin_color_scheme: 'unknown', smtp_password: 'must-not-be-used' } } })
  await expect(fetchSiteAppearance()).resolves.toEqual(siteAppearanceDefaults)
  expect(readPublicBootstrap).toHaveBeenCalledWith(undefined)
})
it('projects only supported appearance values and bounds the background field', () => {
  expect(parseSiteAppearance({ ...siteAppearanceDefaults, smtp_password: 'hidden' })).toEqual(siteAppearanceDefaults)
  for (const value of [null, [], {}, { ...siteAppearanceDefaults, admin_header_style: ['normal'] },
    { ...siteAppearanceDefaults, admin_sidebar_style: 'unknown' }, { ...siteAppearanceDefaults, admin_color_scheme: 'unknown' },
    { ...siteAppearanceDefaults, admin_login_background_url: 'x'.repeat(2049) }]) {
    expect(parseSiteAppearance(value)).toBeUndefined()
  }
})
