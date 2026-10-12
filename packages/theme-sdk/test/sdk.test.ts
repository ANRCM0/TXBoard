import { describe, expect, it, vi } from 'vitest'
import { createTXBoardClient, GatewayApiError } from '../src/index.js'

function response(data: unknown, status = 200) {
  return Response.json({ ok: true, data, meta: { version: '1', requestId: 'test-id' } }, { status })
}
describe('@txboard/theme-sdk v1', () => {
  it('exposes discounted orders and explicit pages without changing the list array route', async () => {
    const result = { items: [{ trade_no: 'historical', status: 4 }], current: 2, pageSize: 100, total: 101, lastPage: 2 }
    const fetchImpl = vi.fn(async (url: unknown) => response(String(url).includes('/orders/page') ? result : result.items))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, getToken: () => 'user-session-long-enough' })
    expect(await sdk.orders.page({ status: 4, current: 2, page_size: 100 })).toEqual(result)
    expect(await sdk.orders.list({ status: 4, current: 2, page_size: 100 })).toEqual(result.items)
    expect(await sdk.orders.list()).toEqual(result.items)
    expect(fetchImpl.mock.calls.map(call => call[0])).toEqual([
      '/gateway/v1/orders/page?status=4&current=2&page_size=100',
      '/gateway/v1/orders?status=4&current=2&page_size=100', '/gateway/v1/orders',
    ])
    for (const call of fetchImpl.mock.calls as any[]) expect(call[1].headers.get('Authorization')).toBe('Bearer user-session-long-enough')
  })

  it('loads bootstrap and plans without sending bearer tokens', async () => {
    const fetchImpl = vi.fn(async (url: unknown) => {
      if (String(url).endsWith('bootstrap')) return response({
        site: { name: 'TXBoard' }, theme: { name: 'Nova', config: {} },
        security: { captcha: { enabled: false, type: null, siteKey: null } }, capabilities: [],
      })
      return response([{ id: 1, name: 'Monthly', month_price: 500 }])
    })
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, getToken: () => 'secretBearer' })
    expect((await sdk.bootstrap()).site.name).toBe('TXBoard')
    expect((await sdk.plans.list())[0]?.month_price).toBe(500)
    for (const [, options] of fetchImpl.mock.calls as any) {
      expect(options.headers.has('Authorization')).toBe(false)
      expect(options.credentials).toBe('omit')
    }
  })

  it('never auto-stores login credentials and passes CAPTCHA input unchanged', async () => {
    const fetchImpl = vi.fn(async () => response({ auth_data: 'Bearer abcdef123456' }))
    const sdk = createTXBoardClient({ baseURL: '/gateway/v1', fetchImpl: fetchImpl as typeof fetch })
    const login = await sdk.auth.login({ email: 'user@example.com', password: 'longpassword', turnstile_token: 'cf-token' })
    expect(login.auth_data).toBe('Bearer abcdef123456')
    const [, options] = fetchImpl.mock.calls[0] as any[]
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body).turnstile_token).toBe('cf-token')
    expect(options.headers.has('Authorization')).toBe(false)
  })

  it('passes user token only to protected fixed routes and normalizes errors', async () => {
    const fetchImpl = vi.fn(async () => response({ email: 'a@example.com' }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, getToken: async () => 'my-user-token' })
    expect((await sdk.user.profile()).email).toBe('a@example.com')
    expect((fetchImpl.mock.calls[0] as any)[1].headers.get('Authorization')).toBe('Bearer my-user-token')
    const orders = await sdk.orders.list({ status: 0 })
    expect(orders).toEqual({ email: 'a@example.com' })
    expect(fetchImpl.mock.calls[1]?.[0]).toBe('/gateway/v1/orders?status=0')
    const noSession = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch })
    await expect(noSession.user.profile()).rejects.toMatchObject({ code: 'UNAUTHORIZED', status: 401 })
    expect(fetchImpl).toHaveBeenCalledTimes(2)
  })

  it('surfaces a structured gateway error with request ID', async () => {
    const fetchImpl = vi.fn(async () => Response.json({
      ok: false, error: { code: 'ORIGIN_DENIED', message: 'Origin is not allowed' },
      meta: { version: '1', requestId: 'req-123' },
    }, { status: 403 }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch })
    await expect(sdk.plans.list()).rejects.toMatchObject({
      code: 'ORIGIN_DENIED', status: 403, requestId: 'req-123',
    } satisfies Partial<GatewayApiError>)
  })

  it('rejects mismatched protocol responses and network errors without leaking URLs', async () => {
    const invalid = createTXBoardClient({ fetchImpl: vi.fn(async () =>
      Response.json({ ok: true, data: {}, meta: { version: '2' } })) as typeof fetch })
    await expect(invalid.bootstrap()).rejects.toMatchObject({ code: 'VERSION_MISMATCH' })
    const offline = createTXBoardClient({ fetchImpl: vi.fn(async () => {
      throw new Error('secret internal hostname')
    }) as typeof fetch })
    await expect(offline.plans.list()).rejects.toMatchObject({ code: 'NETWORK_ERROR', message: 'Gateway is unavailable' })
  })
})

describe('GW-304 order create SDK surface', () => {
  it('sends the Idempotency-Key header and never retries a write', async () => {
    const calls: Array<{ url: unknown; init: any }> = []
    const fetchImpl = vi.fn(async (url: unknown, init: any) => {
      calls.push({ url, init })
      return response({ trade_no: 'T2026' })
    })
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, getToken: () => 'test-user-session' })
    const created = await sdk.orders.create(
      { plan_id: 3, period: 'month_price' },
      { idempotencyKey: 'draft-2026-10-11-0001' },
    )
    expect(created.trade_no).toBe('T2026')
    expect(calls[0]!.url).toBe('/gateway/v1/orders')
    expect(calls[0]!.init.method).toBe('POST')
    expect(calls[0]!.init.headers.get('Idempotency-Key')).toBe('draft-2026-10-11-0001')
    expect(calls[0]!.init.headers.get('Authorization')).toBe('Bearer test-user-session')
    expect(JSON.parse(calls[0]!.init.body)).toEqual({ plan_id: 3, period: 'month_price' })
    // A write is never auto-retried, so a transient failure cannot duplicate.
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('classifies IDEMPOTENCY_IN_PROGRESS as a known, non-unknown-state error', async () => {
    const fetchImpl = vi.fn(async () => Response.json({
      ok: false,
      error: { code: 'IDEMPOTENCY_IN_PROGRESS', message: 'An identical order is already being processed' },
      meta: { version: '1', requestId: 'req-9' },
    }, { status: 409, headers: { 'Retry-After': '2' } }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, getToken: () => 'test-user-session' })
    const error = await sdk.orders.create(
      { plan_id: 3, period: 'month_price' }, { idempotencyKey: 'k' },
    ).catch(e => e as GatewayApiError)
    expect(error).toBeInstanceOf(GatewayApiError)
    expect(error.code).toBe('IDEMPOTENCY_IN_PROGRESS')
    expect(error.status).toBe(409)
    // A known verdict is a definite outcome: the caller must NOT treat it as
    // an unknown remote state and must not re-post with a NEW key.
    expect(error.needsStateConfirmation).toBe(false)
    expect(error.retryable).toBe(false)
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })
})

describe('extended application SDK', () => {
  it('routes only authenticated app read operations', async () => {
    const calls: string[] = []
    const fetchImpl = vi.fn(async (url: unknown, opts: any) => {
      calls.push(String(url))
      expect(opts.headers.get('Authorization')).toBe('Bearer test-user-session')
      return response([])
    })
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, getToken: () => 'test-user-session' })
    await sdk.user.subscription()
    await sdk.orders.detail('O-123')
    await sdk.payments.list()
    await sdk.notices.list({ current: 2, pageSize: 5 })
    expect(calls).toEqual([
      '/gateway/v1/user/subscription/summary',
      '/gateway/v1/orders/O-123',
      '/gateway/v1/payments',
      '/gateway/v1/notices?current=2&pageSize=5',
    ])
  })
  it('never downgrades encrypted login when key discovery fails', async () => {
    const fetchImpl = vi.fn(async () => Response.json({
      ok: false, error: { code: 'NOT_FOUND', message: 'Disabled' },
      meta: { version: '1', requestId: 'test' },
    }, { status: 404 }))
    const sdk = createTXBoardClient({ encryptedLogin: true, fetchImpl: fetchImpl as typeof fetch })
    await expect(sdk.auth.login({ email: 'user@example.test', password: 'password1234' })).rejects.toMatchObject({ status: 404 })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
    expect(fetchImpl.mock.calls[0]?.[0]).toBe('/gateway/v1/crypto/key')
  })
})

describe('encrypted account workflow SDK', () => {
  it('requires HPKE discovery for registration and verification, with no plaintext fallback', async () => {
    const fetchImpl = vi.fn(async () => Response.json({
      ok: false, error: { code: 'NOT_FOUND', message: 'Disabled' },
      meta: { version: '1', requestId: 'test' },
    }, { status: 404 }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch })
    await expect(sdk.auth.register({ email: 'u@example.test', password: 'password123' }))
      .rejects.toMatchObject({ status: 404 })
    await expect(sdk.auth.sendEmailCode({ email: 'u@example.test' }))
      .rejects.toMatchObject({ status: 404 })
    expect(fetchImpl.mock.calls.map(([url]) => url)).toEqual(['/gateway/v1/crypto/key', '/gateway/v1/crypto/key'])
  })
  it('exposes dashboard and payment status as protected read operations', async () => {
    const fetchImpl = vi.fn(async () => response({ status: 0 }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, getToken: () => 'user-token-123' })
    await sdk.dashboard.stats()
    await sdk.orders.status('O-123')
    expect(fetchImpl.mock.calls.map(([url]) => url)).toEqual([
      '/gateway/v1/dashboard/stats', '/gateway/v1/orders/O-123/status',
    ])
  })
})
