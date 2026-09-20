/**
 * Resolves the mount point of the admin SPA.
 *
 * TXBoard ships the admin bundle under `/admin/` (see web/Dockerfile), so
 * Vite injects `BASE_URL = /admin/` and every absolute route, redirect or
 * link has to be prefixed with it. When the bundle is served from the domain
 * root, `BASE_URL` is `/` and these helpers degrade to plain root paths.
 */
export function resolveBasePath(): string {
  const raw = String(import.meta.env.BASE_URL || '/').trim()
  if (!raw || raw === '/' || raw === './') return '/'
  return '/' + raw.replace(/^\/+|\/+$/g, '')
}

export function withBasePath(path: string): string {
  const base = resolveBasePath()
  const normalized = '/' + String(path || '').replace(/^\/+/, '')
  return base === '/' ? normalized : base + normalized
}
