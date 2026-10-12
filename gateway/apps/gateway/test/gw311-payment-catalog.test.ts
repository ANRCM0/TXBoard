import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { invalidateCache } from '../src/middleware/cache.js'
import { upstreamRequest } from '../src/services/upstream.js'
import { nativeEnvelope } from './native-fixtures.js'

beforeEach(() => invalidateCache())
const config = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.example', GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' })
describe('GW-311 Native Core authorization boundary', () => {
  it.each([false, true])('keeps anonymous catalog unavailable with flag=%s', async enabled => {
    const fetcher = vi.fn(async () => Response.json(nativeEnvelope([])))
    const app = createGatewayApp({ ...config, paymentCatalogEnabled: enabled }, fetcher)
    for (const auth of [undefined, 'Bearer ordinary-user-token']) {
      const res = await app.request('/gateway/v1/catalog/payment-methods', { headers: auth ? { Authorization: auth } : {} })
      expect(res.status).toBe(404)
      expect((await res.json() as any).error.code).toBe('NOT_FOUND')
      expect(res.headers.get('X-Cache')).toBeNull()
    }
    expect(fetcher).not.toHaveBeenCalled()
  })
  it('does not advertise unsupported catalog even with its historical flag enabled', async () => {
    const fetcher = vi.fn(async () => Response.json(nativeEnvelope({ app_name: 'Core' })))
    const res = await createGatewayApp(config, fetcher).request('/gateway/v1/bootstrap')
    expect((await res.json() as any).data.capabilities).not.toContain('catalog.payment-methods')
  })
  it('blocks unsupported upstream operation before any fetch', async () => {
    const fetcher = vi.fn()
    await expect(upstreamRequest(config, fetcher, 'guestPaymentMethods', { auth: 'Bearer admin-token-value' }))
      .rejects.toMatchObject({ status: 404 })
    expect(fetcher).not.toHaveBeenCalled()
  })
  it('requires a user session and exposes only display fields on protected payments', async () => {
    const fetcher = vi.fn(async () => Response.json(nativeEnvelope([{
      id: 3, name: 'Stripe', provider: 'StripeCredit', icon: null,
      fee_fixed_minor: 20, fee_percent: 1.5, config: { secret: 'never-expose' },
    }])))
    const app = createGatewayApp(config, fetcher)
    expect((await app.request('/gateway/v1/payments')).status).toBe(401)
    expect(fetcher).not.toHaveBeenCalled()
    const res = await app.request('/gateway/v1/payments', { headers: { Authorization: 'Bearer user-token-value' } })
    expect(res.status).toBe(200)
    expect((await res.json() as any).data).toEqual([{
      id: 3, name: 'Stripe', payment: 'StripeCredit', icon: null, handlingFeeFixed: 20, handlingFeePercent: 1.5,
    }])
    const [url, init] = fetcher.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('https://core.example/txapi/billing/payment-methods')
    expect(new Headers(init.headers).get('Authorization')).toBe('Bearer user-token-value')
  })
})
