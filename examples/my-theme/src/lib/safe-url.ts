// Browser URL hygiene, mirroring the Gateway-era theme safety rules.
// External links use HTTPS; plain HTTP is loopback-dev only. No javascript:,
// no data:, no embedded credentials, no control characters.
const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]', '::1'])

export function safeWebUrl(
  value: unknown,
  { origin, allowRelative = false, allowHttpLoopback = false }: {
    origin?: string; allowRelative?: boolean; allowHttpLoopback?: boolean
  } = {},
): string | null {
  if (typeof value !== 'string' || !value.trim() || value.length > 8192) return null
  const raw = value.trim()
  if (/[\\\u0000-\u001f\u007f]/.test(raw)) return null
  if (!/^https?:\/\//i.test(raw) && !(allowRelative && raw.startsWith('/') && !raw.startsWith('//'))) return null
  const base = origin ?? (typeof location !== 'undefined' ? location.origin : 'https://example.test')
  try {
    const url = new URL(raw, base)
    if (url.username || url.password || url.protocol === 'javascript:' || url.protocol === 'data:') return null
    if (url.protocol !== 'https:' && !(allowHttpLoopback && url.protocol === 'http:' && LOOPBACK.has(url.hostname))) return null
    if (allowRelative && !/^https?:\/\//i.test(raw) && url.origin !== new URL(base).origin) return null
    return url.href
  } catch { return null }
}

export function safeImageUrl(value: unknown): string | null {
  const safe = safeWebUrl(value, { allowRelative: true, allowHttpLoopback: true })
  if (!safe) return null
  const url = new URL(safe)
  const page = typeof location !== 'undefined' ? location : null
  if (url.protocol === 'http:' && page && url.origin !== page.origin) return null
  return safe
}
