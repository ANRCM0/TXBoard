/**
 * Resolve the browser mount point of the admin SPA.
 *
 * Vite's BASE_URL is intentionally *not* the router base in production.
 * The bundle is compiled under a fixed, non-entry static namespace
 * (/.txboard-admin/) while the page itself is served from the instance's
 * runtime secure_path, e.g. /a8f3c2d1/config/system.
 *
 * The secure path is a single URL segment, so the first pathname segment is
 * the only mount information the browser router needs. Static previews keep
 * hash routing and therefore use '/' as their router base.
 */
export function resolveBasePath(): string {
  if (import.meta.env.VITE_STATIC_PREVIEW === '1') return '/'
  if (typeof window === 'undefined') return '/'

  const firstSegment = window.location.pathname
    .split('/')
    .map(segment => segment.trim())
    .find(Boolean)

  return firstSegment ? `/${firstSegment}` : '/'
}

export function withBasePath(path: string): string {
  const base = resolveBasePath()
  const normalized = '/' + String(path || '').replace(/^\/+/, '')
  return base === '/' ? normalized : base + normalized
}
