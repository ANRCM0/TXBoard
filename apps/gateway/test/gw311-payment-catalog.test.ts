import { describe, expect, it, vi, beforeEach } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import {
  invalidateCache,
  resetCacheStats,
} from '../src/middleware/cache.js'

/**
 * GW-311: Public payment methods catalog tests.
 *
 * Covers:
 * 1. Route existence and capability flag (default off → 404)
 * 2. DTO shape — only allowlisted non-sensitive fields
 * 3. Cache strategy (publicRead 'public-short')
 * 4. Upstream error handling
 * 5. Envelope format
 */

// ---------- helpers ----------

function testConfig(overrides: Record<string, string> = {}) {
  return loadConfig({
    TXBOARD_UPSTREAM_URL: 'https://upstream.example.com',
    GATEWAY_ALLOWED_ORIGINS: 'https://app.example.com',
    ...overrides,
  })
}

/** Mock fetcher returning a TXBoard-shaped success envelope. */
function okFetcher(data: unknown) {
  return vi.fn(async () => Response.json({ status: 'success', data }))
}

/** Mock fetcher returning a specific status code with a fail envelope. */
function failFetcher(status: number, message = 'error') {
  return vi.fn(async () => Response.json({ status: 'fail', message, data: null }, { status }))
}

/** Mock fetcher that simulates a network failure. */
function networkErrorFetcher() {
  return vi.fn(async () => { throw new Error('connection refused') })
}

/** Mock fetcher returning invalid JSON. */
function invalidJsonFetcher() {
  return vi.fn(async () => new Response('<html>not json</html>', {
    status: 200,
    headers: { 'Content-Type': 'text/html' },
  }))
}

/** Extract JSON body from a Response. */
async function dataOf(res: Response) {
  return res.json() as Promise<any>
}

// Sample raw upstream payment methods (with extra fields that must be filtered).
const RAW_METHODS = [
  {
    id: 1,
    name: 'Alipay',
    icon: 'https://cdn.example.com/alipay.svg',
    payment: 'AlipayGateway',
    handling_fee_fixed: 0,
    handling_fee_percent: 0,
    config: { app_id: 'secret-app-id', private_key: 'secret-key' },
    secret: 'never-leak',
  },
  {
    id: 2,
    name: 'WeChat Pay',
    icon: 'https://cdn.example.com/wechat.svg',
    payment: 'WeChatGateway',
    handling_fee_fixed: 10,
    handling_fee_percent: 1.5,
    config: { mch_id: 'secret-mch', api_key: 'secret-api-key' },
  },
  {
    id: 3,
    name: 'Stripe',
    icon: 'https://cdn.example.com/stripe.svg',
    payment: 'StripeGateway',
    handling_fee_fixed: 20,
    handling_fee_percent: 2.9,
    config: { publishable_key: 'pk_test_secret', secret_key: 'sk_test_secret' },
    webhook_secret: 'whsec_secret',
  },
  {
    id: 4,
    name: 'Manual Bank Transfer',
    // no icon, no payment, no fees — all optional fields missing
  },
]

// ---------- tests ----------

describe('GW-311: GET /gateway/v1/catalog/payment-methods route', () => {
  beforeEach(() => {
    resetCacheStats()
    invalidateCache()
  })

  describe('capability flag (default off)', () => {
    it('returns 404 when paymentCatalogEnabled is false (default)', async () => {
      const fetcher = okFetcher(RAW_METHODS)
      const app = createGatewayApp(testConfig(), fetcher)

      const res = await app.request('/gateway/v1/catalog/payment-methods')

      expect(res.status).toBe(404)
      const body = await dataOf(res)
      expect(body.ok).toBe(false)
      expect(body.error.code).toBe('NOT_FOUND')
      expect(body.error.message).toBe('Gateway route does not exist')
      expect(body.meta.version).toBe('1')
      expect(body.meta.requestId).toBeTruthy()
    })

    it('makes zero upstream calls when the route is disabled', async () => {
      const fetcher = okFetcher(RAW_METHODS)
      const app = createGatewayApp(testConfig(), fetcher)

      await app.request('/gateway/v1/catalog/payment-methods')
      expect(fetcher).not.toHaveBeenCalled()
    })

    it('returns 404 with Origin header present (CORS does not bypass the flag)', async () => {
      const fetcher = okFetcher(RAW_METHODS)
      const app = createGatewayApp(testConfig(), fetcher)

      const res = await app.request('/gateway/v1/catalog/payment-methods', {
        headers: { Origin: 'https://app.example.com' },
      })

      expect(res.status).toBe(404)
      expect(fetcher).not.toHaveBeenCalled()
    })

    it('does not include catalog.payment-methods in bootstrap capabilities when disabled', async () => {
      const fetcher = okFetcher({ app_name: 'Test' })
      const app = createGatewayApp(testConfig(), fetcher)

      const res = await app.request('/gateway/v1/bootstrap')
      const body = await dataOf(res)

      expect(body.data.capabilities).not.toContain('catalog.payment-methods')
    })
  })

  describe('capability flag enabled', () => {
    it('returns 200 with payment methods when GATEWAY_PAYMENT_CATALOG_ENABLED=true', async () => {
      const fetcher = okFetcher(RAW_METHODS)
      const app = createGatewayApp(
        testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
        fetcher,
      )

      const res = await app.request('/gateway/v1/catalog/payment-methods')

      expect(res.status).toBe(200)
      const body = await dataOf(res)
      expect(body.ok).toBe(true)
      expect(body.meta.version).toBe('1')
      expect(body.meta.requestId).toBeTruthy()
    })

    it('includes catalog.payment-methods in bootstrap capabilities when enabled', async () => {
      const fetcher = okFetcher({ app_name: 'Test' })
      const app = createGatewayApp(
        testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
        fetcher,
      )

      const res = await app.request('/gateway/v1/bootstrap')
      const body = await dataOf(res)

      expect(body.data.capabilities).toContain('catalog.payment-methods')
    })

    it('calls the correct upstream endpoint', async () => {
      const fetcher = okFetcher(RAW_METHODS)
      const app = createGatewayApp(
        testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
        fetcher,
      )

      await app.request('/gateway/v1/catalog/payment-methods')

      expect(fetcher).toHaveBeenCalledTimes(1)
      const [url] = fetcher.mock.calls[0] as any[]
      expect(url).toBe('https://upstream.example.com/api/v1/guest/paymentMethods')
    })
  })
})

describe('GW-311: DTO format', () => {
  beforeEach(() => {
    resetCacheStats()
    invalidateCache()
  })

  it('returns only allowlisted non-sensitive fields', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)
    const methods = body.data

    expect(methods).toHaveLength(4)

    // Each method must have exactly these 6 keys — nothing more.
    const allowedKeys = ['id', 'name', 'icon', 'payment', 'handlingFeeFixed', 'handlingFeePercent']
    for (const method of methods) {
      const keys = Object.keys(method).sort()
      expect(keys).toEqual([...allowedKeys].sort())
    }

    // Verify the actual field values for the first method.
    expect(methods[0]).toEqual({
      id: 1,
      name: 'Alipay',
      icon: 'https://cdn.example.com/alipay.svg',
      payment: 'AlipayGateway',
      handlingFeeFixed: 0,
      handlingFeePercent: 0,
    })
  })

  it('never leaks sensitive upstream fields (config, secret, keys)', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)
    const json = JSON.stringify(body)

    // These strings must NEVER appear in the response.
    expect(json).not.toContain('secret-app-id')
    expect(json).not.toContain('secret-key')
    expect(json).not.toContain('secret-mch')
    expect(json).not.toContain('secret-api-key')
    expect(json).not.toContain('pk_test_secret')
    expect(json).not.toContain('sk_test_secret')
    expect(json).not.toContain('whsec_secret')
    expect(json).not.toContain('never-leak')
    expect(json).not.toContain('config')
    expect(json).not.toContain('webhook_secret')
  })

  it('handles optional fields correctly (missing icon/payment/fees default to null/0)', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)
    const methods = body.data

    // The 4th method (Manual Bank Transfer) has no icon, payment, or fees.
    const manual = methods[3]
    expect(manual).toEqual({
      id: 4,
      name: 'Manual Bank Transfer',
      icon: null,
      payment: null,
      handlingFeeFixed: 0,
      handlingFeePercent: 0,
    })
  })

  it('handles non-string icon/payment as null', async () => {
    const rawWithBadTypes = [
      { id: 1, name: 'Test', icon: 12345, payment: true, handling_fee_fixed: 'not-a-number', handling_fee_percent: null },
    ]
    const fetcher = okFetcher(rawWithBadTypes)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(body.data[0]).toEqual({
      id: 1,
      name: 'Test',
      icon: null,
      payment: null,
      handlingFeeFixed: 0,
      handlingFeePercent: 0,
    })
  })

  it('returns an empty array when upstream returns no methods', async () => {
    const fetcher = okFetcher([])
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(200)
    expect(body.data).toEqual([])
  })
})

describe('GW-311: cache strategy', () => {
  beforeEach(() => {
    resetCacheStats()
    invalidateCache()
  })

  it('caches the response (X-Cache: MISS then HIT)', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    // First request — MISS
    const res1 = await app.request('/gateway/v1/catalog/payment-methods')
    expect(res1.status).toBe(200)
    expect(res1.headers.get('X-Cache')).toBe('MISS')
    expect(fetcher).toHaveBeenCalledTimes(1)

    // Second request — HIT
    const res2 = await app.request('/gateway/v1/catalog/payment-methods')
    expect(res2.status).toBe(200)
    expect(res2.headers.get('X-Cache')).toBe('HIT')
    // No additional upstream call
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('does not serve stale data after cache invalidation', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    // Populate cache
    await app.request('/gateway/v1/catalog/payment-methods')
    expect(fetcher).toHaveBeenCalledTimes(1)

    // Invalidate cache
    invalidateCache('/gateway/v1/catalog/payment-methods')

    // Next request should be a MISS
    const res = await app.request('/gateway/v1/catalog/payment-methods')
    expect(res.headers.get('X-Cache')).toBe('MISS')
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('does not cache non-200 responses (upstream errors)', async () => {
    const fetcher = failFetcher(500)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res1 = await app.request('/gateway/v1/catalog/payment-methods')
    expect(res1.status).toBe(502)
    expect(res1.headers.get('X-Cache')).toBeNull()

    const res2 = await app.request('/gateway/v1/catalog/payment-methods')
    expect(res2.status).toBe(502)
    expect(res2.headers.get('X-Cache')).toBeNull()
    // Both requests hit upstream (no caching of errors)
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('does not cache when the route is disabled (404 responses)', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(testConfig(), fetcher)

    const res1 = await app.request('/gateway/v1/catalog/payment-methods')
    expect(res1.status).toBe(404)
    expect(res1.headers.get('X-Cache')).toBeNull()

    const res2 = await app.request('/gateway/v1/catalog/payment-methods')
    expect(res2.status).toBe(404)
    expect(res2.headers.get('X-Cache')).toBeNull()
    expect(fetcher).not.toHaveBeenCalled()
  })
})

describe('GW-311: upstream error handling', () => {
  beforeEach(() => {
    resetCacheStats()
    invalidateCache()
  })

  it('returns 502 UPSTREAM_UNAVAILABLE when upstream connection fails', async () => {
    const fetcher = networkErrorFetcher()
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(502)
    expect(body.ok).toBe(false)
    expect(body.error).toEqual({
      code: 'UPSTREAM_UNAVAILABLE',
      message: 'Upstream connection failed',
    })
  })

  it('returns 502 when upstream returns invalid JSON', async () => {
    const fetcher = invalidJsonFetcher()
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(502)
    expect(body.ok).toBe(false)
    expect(body.error.code).toBe('UPSTREAM_ERROR')
  })

  it('returns 400 when upstream returns a fail envelope (app-level error)', async () => {
    const fetcher = failFetcher(400, 'Invalid parameters')
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(400)
    expect(body.ok).toBe(false)
    expect(body.error.code).toBe('UPSTREAM_ERROR')
    expect(body.error.message).toBe('Upstream request rejected')
  })

  it('returns 429 when upstream rate-limits the request', async () => {
    const fetcher = failFetcher(429, 'Too many requests')
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(429)
    expect(body.ok).toBe(false)
    expect(body.error.code).toBe('UPSTREAM_ERROR')
    expect(body.error.message).toBe('Too many requests')
  })

  it('returns 422 when upstream rejects with validation error', async () => {
    const fetcher = failFetcher(422, 'Invalid input')
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(422)
    expect(body.ok).toBe(false)
    expect(body.error.code).toBe('UPSTREAM_ERROR')
    expect(body.error.message).toBe('Invalid request data')
  })

  it('returns 502 when upstream returns malformed success (missing data)', async () => {
    const fetcher = vi.fn(async () => Response.json({ status: 'success' }))
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(502)
    expect(body.ok).toBe(false)
    expect(body.error.code).toBe('UPSTREAM_ERROR')
  })

  it('returns 502 when upstream returns unexpected status (not success/fail)', async () => {
    const fetcher = vi.fn(async () => Response.json({ data: [{ id: 1, name: 'Test' }] }))
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(502)
    expect(body.ok).toBe(false)
    expect(body.error.code).toBe('UPSTREAM_ERROR')
  })

  it('returns 502 when upstream payment methods fail schema validation', async () => {
    // Missing required `name` field — schema validation should catch it.
    const fetcher = okFetcher([
      { id: 1, name: 'Valid' },
      { id: 'not-a-number', name: 'Invalid ID' },
    ])
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(502)
    expect(body.ok).toBe(false)
    expect(body.error.code).toBe('UPSTREAM_ERROR')
    expect(body.error.message).toBe('Unexpected upstream response shape')
  })

  it('returns 502 when upstream returns a non-array payload', async () => {
    const fetcher = okFetcher({ id: 1, name: 'Not an array' })
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(res.status).toBe(502)
    expect(body.ok).toBe(false)
    expect(body.error.code).toBe('UPSTREAM_ERROR')
  })

  it('never echoes upstream error messages that may contain credentials', async () => {
    const fetcher = failFetcher(422, 'password=secret admin_key=private token=abc123')
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)
    const json = JSON.stringify(body)

    expect(json).not.toContain('password=secret')
    expect(json).not.toContain('admin_key=private')
    expect(json).not.toContain('token=abc123')
    expect(body.error.message).toBe('Invalid request data')
  })
})

describe('GW-311: envelope and response format', () => {
  beforeEach(() => {
    resetCacheStats()
    invalidateCache()
  })

  it('returns a contract-frozen success envelope', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(body).toHaveProperty('ok', true)
    expect(body).toHaveProperty('data')
    expect(body).toHaveProperty('meta')
    expect(body.meta).toHaveProperty('version', '1')
    expect(body.meta).toHaveProperty('requestId')
    expect(typeof body.meta.requestId).toBe('string')
    expect(body.meta.requestId.length).toBeGreaterThan(0)
  })

  it('returns a contract-frozen failure envelope', async () => {
    const fetcher = networkErrorFetcher()
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')
    const body = await dataOf(res)

    expect(body).toHaveProperty('ok', false)
    expect(body).toHaveProperty('error')
    expect(body.error).toHaveProperty('code')
    expect(body.error).toHaveProperty('message')
    expect(body).toHaveProperty('meta')
    expect(body.meta).toHaveProperty('version', '1')
    expect(body.meta).toHaveProperty('requestId')
  })

  it('sets security headers on the response', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    const res = await app.request('/gateway/v1/catalog/payment-methods')

    expect(res.headers.get('X-Content-Type-Options')).toBe('nosniff')
    expect(res.headers.get('X-Frame-Options')).toBe('DENY')
    expect(res.headers.get('Cache-Control')).toBe('no-store')
    expect(res.headers.get('X-Request-Id')).toBeTruthy()
  })

  it('sets security headers even on 404 (route disabled)', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(testConfig(), fetcher)

    const res = await app.request('/gateway/v1/catalog/payment-methods')

    expect(res.headers.get('X-Content-Type-Options')).toBe('nosniff')
    expect(res.headers.get('X-Frame-Options')).toBe('DENY')
    expect(res.headers.get('Cache-Control')).toBe('no-store')
  })

  it('does not send Authorization header upstream', async () => {
    const fetcher = okFetcher(RAW_METHODS)
    const app = createGatewayApp(
      testConfig({ GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' }),
      fetcher,
    )

    // Even if a client mistakenly sends an Authorization header, the public
    // route must not forward it upstream.
    await app.request('/gateway/v1/catalog/payment-methods', {
      headers: { Authorization: 'Bearer should-not-forward' },
    })

    const [, init] = fetcher.mock.calls[0] as any[]
    expect(init.headers.has('Authorization')).toBe(false)
  })
})
