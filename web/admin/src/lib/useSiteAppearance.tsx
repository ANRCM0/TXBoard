import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { publicBootstrapData, readPublicBootstrap } from '../native/publicBootstrap'
import './site-appearance.css'

export const siteAppearanceDefaults = { admin_sidebar_style: 'normal', admin_header_style: 'normal', admin_color_scheme: 'default', admin_login_background_url: '' } as const
export type SiteAppearance = {
  admin_sidebar_style: 'normal' | 'contrast'; admin_header_style: 'normal' | 'accent'
  admin_color_scheme: 'default' | 'black' | 'blackblue' | 'green'; admin_login_background_url: string
}
export function parseSiteAppearance(value: unknown): SiteAppearance | undefined {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return undefined
  const { admin_sidebar_style: sidebar, admin_header_style: header, admin_color_scheme: color,
    admin_login_background_url: background } = value as Record<string, unknown>
  if ((sidebar !== 'normal' && sidebar !== 'contrast') || (header !== 'normal' && header !== 'accent')
    || (color !== 'default' && color !== 'black' && color !== 'blackblue' && color !== 'green')
    || typeof background !== 'string' || background.length > 2048) return undefined
  return { admin_sidebar_style: sidebar, admin_header_style: header, admin_color_scheme: color, admin_login_background_url: background }
}
export function safeLoginBackground(value: unknown): string {
  if (typeof value !== 'string' || value.length > 2048 || /[\s\\\u0000-\u001f\u007f]/.test(value) || /%(?![0-9a-f]{2})/i.test(value)) return ''
  if (value === '') return ''
  if (value.startsWith('/') && !value.startsWith('//')) {
    try { if (/[\s\\\u0000-\u001f\u007f]/.test(decodeURIComponent(value))) return '' } catch { return '' }
    return value
  }
  try {
    const url = new URL(value)
    return url.protocol === 'https:' && !url.username && !url.password ? value : ''
  } catch { return '' }
}
export async function fetchSiteAppearance({ signal }: { signal?: AbortSignal } = {}): Promise<SiteAppearance> {
  const bootstrap = publicBootstrapData(await readPublicBootstrap(signal))
  const parsed = parseSiteAppearance(bootstrap.admin_appearance)
  if (!parsed) return { ...siteAppearanceDefaults }
  return { ...parsed, admin_login_background_url: safeLoginBackground(parsed.admin_login_background_url) }
}
export function useSiteAppearance() {
  return useQuery({ queryKey: ['admin', 'site-appearance'], queryFn: fetchSiteAppearance, staleTime: 300_000, retry: false })
}

/** Changes shell defaults without writing the local light/dark/system preference or data-theme. */
export function SiteAppearanceConsumer() {
  const query = useSiteAppearance(), appearance = query.isError ? siteAppearanceDefaults : query.data ?? siteAppearanceDefaults
  useEffect(() => {
    const root = document.documentElement
    const values = { adminSidebarStyle: appearance.admin_sidebar_style, adminHeaderStyle: appearance.admin_header_style, adminColorScheme: appearance.admin_color_scheme }
    const previous = Object.fromEntries(Object.keys(values).map(key => [key, root.dataset[key]]))
    for (const [key, value] of Object.entries(values)) root.dataset[key] = value
    return () => { for (const [key, value] of Object.entries(values)) if (root.dataset[key] === value) {
      if (previous[key] === undefined) delete root.dataset[key]
      else root.dataset[key] = previous[key]
    } }
  }, [appearance.admin_sidebar_style, appearance.admin_header_style, appearance.admin_color_scheme])
  return null
}

export function SiteLoginBackground() {
  const query = useSiteAppearance(), src = query.isError ? '' : safeLoginBackground(query.data?.admin_login_background_url)
  const [failed, setFailed] = useState('')
  if (!src || failed === src) return null
  return <div className="site-login-background" aria-hidden="true"><img src={src} alt="" referrerPolicy="no-referrer" onError={() => setFailed(src)} /></div>
}
