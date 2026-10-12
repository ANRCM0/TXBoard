import { describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { nativeEnvelope, nativeOrder } from './native-fixtures.js'

const config = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.example' })
const headers = { Authorization: 'Bearer user-session-long-enough' }

describe('Native order history and explicit pagination', () => {
  it('preserves discounted status 4 in list, detail and status', async () => {
    const row = nativeOrder({ status: 4 })
    const fetcher = vi.fn(async (url: unknown) => Response.json(String(url).includes('?')
      ? nativeEnvelope([row], { page: 1, per_page: 100, total: 1, last_page: 1 })
      : nativeEnvelope(row)))
    const app = createGatewayApp(config, fetcher as typeof fetch)
    for (const path of ['/orders?status=4', '/orders/T-1', '/orders/T-1/status']) {
      const response = await app.request('/gateway/v1' + path, { headers })
      expect(response.status).toBe(200)
      const body = await response.json() as any
      expect(Array.isArray(body.data) ? body.data[0].status : body.data.status).toBe(4)
    }
    expect(String(fetcher.mock.calls[0]?.[0])).toBe('https://core.example/txapi/orders?status=4&page=1&per_page=100')
  })

  it('keeps the array route and exposes accurate total/lastPage in the additive page route', async () => {
    const fetcher = vi.fn(async () => Response.json(nativeEnvelope([nativeOrder({ status: 4 })],
      { page: 2, per_page: 100, total: 101, last_page: 2 })))
    const app = createGatewayApp(config, fetcher as typeof fetch)
    const query = '?status=4&current=2&page_size=100'
    const legacy = await app.request('/gateway/v1/orders' + query, { headers })
    const page = await app.request('/gateway/v1/orders/page' + query, { headers })
    expect(legacy.status).toBe(200)
    expect(page.status).toBe(200)
    const legacyBody = await legacy.json() as any
    const pageBody = await page.json() as any
    expect(pageBody.data).toEqual({ items: legacyBody.data, current: 2, pageSize: 100, total: 101, lastPage: 2 })
    expect(Object.keys(pageBody.meta).sort()).toEqual(['requestId', 'version'])
    for (const call of fetcher.mock.calls as any[]) {
      expect(call[0]).toBe('https://core.example/txapi/orders?status=4&page=2&per_page=100')
      expect(call[1].headers.get('Authorization')).toBe(headers.Authorization)
    }
  })

  it.each(['status=5', 'current=0', 'current=10000', 'page_size=101', 'page_size=0',
    'current=1&current=2', 'page_size=1&page_size=2', 'page=2', 'admin_id=1'])
  ('rejects invalid or unallowlisted query %s before fetch', async query => {
    const fetcher = vi.fn()
    const app = createGatewayApp(config, fetcher)
    for (const path of ['/orders', '/orders/page']) {
      expect((await app.request('/gateway/v1' + path + '?' + query, { headers })).status).toBe(400)
    }
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('requires a user bearer on the page route and does not add another write path', async () => {
    const fetcher = vi.fn()
    const app = createGatewayApp(config, fetcher)
    expect((await app.request('/gateway/v1/orders/page')).status).toBe(401)
    expect((await app.request('/gateway/v1/orders/page', { method: 'POST', headers })).status).toBe(405)
    expect(fetcher).not.toHaveBeenCalled()
  })
})
