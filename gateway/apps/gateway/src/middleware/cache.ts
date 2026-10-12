import type { Context, Next } from 'hono'
import type { RoutePolicy } from '../config/policies.js'
import { PREFIX } from './request-context.js'

/**
 * middleware/cache.ts — public content cache (GW-218).
 *
 * Caches responses from `publicRead` routes (bootstrap, theme/config, plans,
 * catalog/payment-methods) in an in-memory TTL cache. The cache key includes
 * the full path and query string, so different query parameters produce
 * different cache entries.
 *
 * Design:
 * - Only routes with `cache: 'public-short'` are cached.
 * - Default TTL is 60 seconds.
 * - Cache entries are automatically evicted after TTL expiration.
 * - `invalidateCache()` clears all or specific route caches.
 * - Responses include `X-Cache: HIT` or `X-Cache: MISS` header.
 */

export interface CacheOptions {
  /** TTL in milliseconds. Default: 60000 (60 seconds). */
  ttlMs?: number
}

export interface CacheEntry {
  response: Response
  expiresAt: number
}

export interface CacheStats {
  size: number
  hits: number
  misses: number
}

/**
 * In-memory cache store. Key is `METHOD PATH?query`.
 */
const cacheStore = new Map<string, CacheEntry>()
let cacheHits = 0
let cacheMisses = 0

/**
 * Generate a cache key from the request.
 */
export function cacheKey(method: string, path: string, query: string): string {
  return `${method.toUpperCase()} ${path}${query}`
}

/**
 * Get a cached response if it exists and is not expired.
 */
export function getCachedResponse(key: string): Response | undefined {
  const entry = cacheStore.get(key)
  if (!entry) {
    cacheMisses++
    return undefined
  }
  if (Date.now() > entry.expiresAt) {
    cacheStore.delete(key)
    cacheMisses++
    return undefined
  }
  cacheHits++
  return entry.response
}

/**
 * Store a response in the cache.
 */
export function setCachedResponse(key: string, response: Response, ttlMs: number): void {
  cacheStore.set(key, {
    response,
    expiresAt: Date.now() + ttlMs,
  })
}

/**
 * Invalidate cache entries. If `pattern` is provided, only entries whose key
 * contains the pattern are removed. Otherwise, all entries are cleared.
 */
export function invalidateCache(pattern?: string): number {
  if (!pattern) {
    const size = cacheStore.size
    cacheStore.clear()
    return size
  }
  let count = 0
  for (const key of cacheStore.keys()) {
    if (key.includes(pattern)) {
      cacheStore.delete(key)
      count++
    }
  }
  return count
}

/**
 * Get current cache statistics.
 */
export function cacheStats(): CacheStats {
  return {
    size: cacheStore.size,
    hits: cacheHits,
    misses: cacheMisses,
  }
}

/**
 * Reset cache statistics (for testing).
 */
export function resetCacheStats(): void {
  cacheHits = 0
  cacheMisses = 0
}

/**
 * Check if a policy allows public caching.
 */
export function isCacheable(policy: RoutePolicy): boolean {
  return policy.cache === 'public-short'
}

/**
 * The cache middleware factory.
 *
 * Must be registered AFTER the policy enforcement middleware so that
 * `POLICY_CONTEXT_KEY` is already set in the context.
 */
export function cacheMiddleware(options: CacheOptions = {}) {
  const ttlMs = options.ttlMs ?? 60_000

  return async function cache(c: Context, next: Next): Promise<void | Response> {
    const path = c.req.path
    const method = c.req.method.toUpperCase()

    // Only cache GET requests on the /gateway/v1 surface
    if (method !== 'GET' || !path.startsWith(`${PREFIX}/`)) {
      await next()
      return
    }

    // Check if the resolved policy allows caching
    const resolved = c.get('routePolicy') as { policy: RoutePolicy } | undefined
    if (!resolved || !isCacheable(resolved.policy)) {
      await next()
      return
    }

    // Build cache key from path + query string
    const url = new URL(c.req.raw.url)
    const query = url.search
    const key = cacheKey(method, path, query)

    // Try cache hit
    const cached = getCachedResponse(key)
    if (cached) {
      // Hand the client a fresh branch and keep `cached` intact: reusing
      // cached.body directly would leave the stored entry consumed after the
      // first HIT, so every later HIT would read a dead stream.
      const hit = cached.clone()
      hit.headers.set('X-Cache', 'HIT')
      // Build the response through the context so headers outer middleware
      // (CORS, in particular) prepared before next() are merged in. Returning
      // the raw Response would drop them and strip Access-Control-Allow-Origin
      // from every cached cross-origin response.
      return c.newResponse(hit.body, { status: hit.status as 200, headers: hit.headers })
    }

    cacheMisses++

    // No cache hit — call the next handler
    await next()

    // After next(), c.res contains the response set by the handler.
    const response = c.res
    if (response.status === 200) {
      // Keep an independent copy for the cache. It is the tee branch the
      // client never reads, so consuming the live response body below cannot
      // disturb the cached entry (a shared object would make every later HIT
      // read an already-consumed stream).
      const forCache = response.clone()
      setCachedResponse(key, forCache, ttlMs)
      // Mark the LIVE response so the client sees X-Cache: MISS without
      // replacing c.res (replacing it would drop headers the outer middleware
      // — CORS in particular — prepared for this request).
      response.headers.set('X-Cache', 'MISS')
    }
  }
}
