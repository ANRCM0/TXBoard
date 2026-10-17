import { requestId, nativeError, nativeOffer } from './native-fixtures.js'
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import {
  createGatewayApp,
} from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { CryptoService } from '../src/services/crypto.js'
import { generateKeyPairSync } from 'node:crypto'
import {
  cacheMiddleware,
  cacheKey,
  getCachedResponse,
  setCachedResponse,
  invalidateCache,
  cacheStats,
  resetCacheStats,
  isCacheable,
} from '../src/middleware/cache.js'
import { POLICY_DEFINITIONS } from '../src/config/policies.js'

/**
 * GW-218: Public content cache tests.
 *
 * Verifies that:
 * - publicRead routes are cached (bootstrap, theme/config, plans)
 * - Cache key includes query string
 * - Cache HIT/MISS headers are set
 * - Invalidation clears entries
 * - Non-public routes are NOT cached
 * - TTL expiration works
 */

// Helper: create a minimal config for testing
function testConfig(overrides: Partial<ReturnType<typeof loadConfig>> = {}) {
  return loadConfig({
    TXBOARD_UPSTREAM_URL: 'https://upstream.example.com',
    GATEWAY_ALLOWED_ORIGINS: 'https://app.example.com',
    ...overrides as Record<string, string>,
  })
}

// Helper: create a mock fetcher that counts calls.
// Returns a TXBoard-shaped envelope: { request_id: requestId, data: ... }.
function createMockFetcher(responseData: unknown) {
  const fn = vi.fn(async () => {
    return Response.json({ request_id: requestId, data: responseData })
  })
  return fn
}

describe('GW-218: cache utilities', () => {
  beforeEach(() => {
    resetCacheStats()
    invalidateCache()
    // Also clear any cached entries from previous tests
    invalidateCache()
  })

  it('generates correct cache keys', () => {
    expect(cacheKey('GET', '/gateway/v1/bootstrap', '')).toBe('GET /gateway/v1/bootstrap')
    expect(cacheKey('GET', '/gateway/v1/plans', '?foo=bar')).toBe('GET /gateway/v1/plans?foo=bar')
    expect(cacheKey('get', '/gateway/v1/theme/config', '')).toBe('GET /gateway/v1/theme/config')
  })

  it('stores and retrieves cached responses', () => {
    const response = Response.json({ ok: true })
    setCachedResponse('GET /test', response, 60000)

    const cached = getCachedResponse('GET /test')
    expect(cached).toBeDefined()
    expect(cached?.status).toBe(200)
  })

  it('expires cached entries after TTL', async () => {
    const response = Response.json({ ok: true })
    setCachedResponse('GET /test', response, 10) // 10ms TTL

    // Should exist immediately
    expect(getCachedResponse('GET /test')).toBeDefined()

    // Wait for expiration
    await new Promise(resolve => setTimeout(resolve, 20))

    // Should be gone
    expect(getCachedResponse('GET /test')).toBeUndefined()
  })

  it('invalidates all entries when no pattern given', () => {
    setCachedResponse('GET /a', Response.json({}), 60000)
    setCachedResponse('GET /b', Response.json({}), 60000)
    setCachedResponse('GET /c', Response.json({}), 60000)

    const removed = invalidateCache()
    expect(removed).toBe(3)
    expect(cacheStats().size).toBe(0)
  })

  it('invalidates specific entries when pattern given', () => {
    setCachedResponse('GET /gateway/v1/bootstrap', Response.json({}), 60000)
    setCachedResponse('GET /gateway/v1/plans', Response.json({}), 60000)
    setCachedResponse('GET /gateway/v1/user/profile', Response.json({}), 60000)

    const removed = invalidateCache('/gateway/v1/plans')
    expect(removed).toBe(1)
    expect(cacheStats().size).toBe(2)
  })

  it('tracks cache hits and misses', () => {
    setCachedResponse('GET /test', Response.json({}), 60000)

    getCachedResponse('GET /test') // hit
    getCachedResponse('GET /test') // hit
    getCachedResponse('GET /nonexistent') // miss

    const stats = cacheStats()
    expect(stats.hits).toBe(2)
    expect(stats.misses).toBe(1)
  })

  it('identifies cacheable policies', () => {
    expect(isCacheable(POLICY_DEFINITIONS.publicRead)).toBe(true)
    expect(isCacheable(POLICY_DEFINITIONS.userRead)).toBe(false)
    expect(isCacheable(POLICY_DEFINITIONS.login)).toBe(false)
    expect(isCacheable(POLICY_DEFINITIONS.disabledWrite)).toBe(false)
  })
})

describe('GW-218: cache middleware integration', () => {
  beforeEach(() => {
    resetCacheStats()
    invalidateCache()
  })

  it('caches bootstrap route responses', async () => {
    const config = testConfig()
    const fetcher = createMockFetcher({ site: { name: 'Test' } })
    const app = createGatewayApp(config, fetcher)

    // First request — MISS
    const res1 = await app.request('/gateway/v1/bootstrap')
    expect(res1.status).toBe(200)
    expect(res1.headers.get('X-Cache')).toBe('MISS')
    expect(fetcher).toHaveBeenCalledTimes(1)

    // Second request — HIT
    const res2 = await app.request('/gateway/v1/bootstrap')
    expect(res2.status).toBe(200)
    expect(res2.headers.get('X-Cache')).toBe('HIT')
    expect(fetcher).toHaveBeenCalledTimes(1) // no additional upstream call
  })

  it('caches plans route responses', async () => {
    const config = testConfig()
    const fetcher = createMockFetcher([nativeOffer({ name: 'Basic' })])
    const app = createGatewayApp(config, fetcher)

    const res1 = await app.request('/gateway/v1/plans')
    expect(res1.status).toBe(200)
    expect(res1.headers.get('X-Cache')).toBe('MISS')

    const res2 = await app.request('/gateway/v1/plans')
    expect(res2.status).toBe(200)
    expect(res2.headers.get('X-Cache')).toBe('HIT')
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('does not cache user routes (userRead policy)', async () => {
    const config = testConfig()
    const fetcher = createMockFetcher({ name: 'User' })
    const app = createGatewayApp(config, fetcher)

    // user/profile requires bearer token, but even with a valid shape it
    // should not be cached. We test by checking the policy is 'off'.
    const res1 = await app.request('/gateway/v1/user/profile', {
      headers: { Authorization: 'Bearer test-token' },
    })
    expect(res1.headers.get('X-Cache')).toBeNull()

    const res2 = await app.request('/gateway/v1/user/profile', {
      headers: { Authorization: 'Bearer test-token' },
    })
    expect(res2.headers.get('X-Cache')).toBeNull()
  })

  it('does not cache crypto/key route', async () => {
    const config = testConfig()
    const fetcher = createMockFetcher({})
    const app = createGatewayApp(config, fetcher)

    // crypto/key is publicRead but has empty ROUTE_OPERATIONS — it's
    // served by the crypto service, not upstream. The cache middleware
    // should still cache it (it's publicRead policy).
    const res1 = await app.request('/gateway/v1/crypto/key')
    // Without HPKE wired, crypto/key should be 404 (route not active)
    // This is expected — crypto/key only exists when HPKE is configured.
    // The cache middleware should NOT cache 404 responses.
    expect(res1.headers.get('X-Cache')).toBeNull()
  })

  it('does not cache non-200 responses', async () => {
    const config = testConfig()
    const fetcher = vi.fn(async () => {
      return Response.json(nativeError(), { status: 500 })
    })
    const app = createGatewayApp(config, fetcher)

    const res = await app.request('/gateway/v1/bootstrap')
    expect(res.status).toBe(502)
    expect(res.headers.get('X-Cache')).toBeNull()
  })

  it('does not cache POST requests', async () => {
    const config = testConfig()
    const fetcher = createMockFetcher({})
    const app = createGatewayApp(config, fetcher)

    const res = await app.request('/gateway/v1/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email: 'test@test.com' }),
    })
    expect(res.headers.get('X-Cache')).toBeNull()
  })

  it('includes query string in cache key', async () => {
    const config = testConfig()
    // /plans is schema-validated (array of Native Offers), so the mock must return
    // a valid plan list or the route answers 502 and nothing is cacheable.
    const fetcher = createMockFetcher([nativeOffer({ name: 'Basic' })])
    const app = createGatewayApp(config, fetcher)

    // Same path, different query = different cache entries
    const res1 = await app.request('/gateway/v1/plans?region=us')
    expect(res1.headers.get('X-Cache')).toBe('MISS')

    const res2 = await app.request('/gateway/v1/plans?region=eu')
    expect(res2.headers.get('X-Cache')).toBe('MISS') // different query = miss

    const res3 = await app.request('/gateway/v1/plans?region=us')
    expect(res3.headers.get('X-Cache')).toBe('HIT') // same query = hit
  })
})
