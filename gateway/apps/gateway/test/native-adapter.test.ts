import { describe, expect, it, vi } from 'vitest'
import { loadConfig } from '../src/config/env.js'
import { upstreamRequest, type UpstreamOperation } from '../src/services/upstream.js'
import { nativeEnvelope, nativeError, nativeNotice, nativeOrder, nativePlan, nativeProfile, nativeSubscription } from './native-fixtures.js'

const config = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test' })
const bearer = 'Bearer ordinary-user-session'
const pages = { page: 1, per_page: 100, total: 1, last_page: 1 }
const fixtures: Array<[UpstreamOperation, string, unknown, unknown?]> = [
  ['guestConfig', '/txapi/public/site-config', { app_name: 'Core', theme_config: {} }],
  ['guestPlans', '/txapi/plans', [nativePlan({ prices: [{ period: 'monthly', amount_minor: 1200 }] })]],
  ['login', '/txapi/auth/login', { auth_data: bearer, secure_path: 'secret', is_admin: true }],
  ['register', '/txapi/auth/register', { auth_data: bearer }],
  ['sendEmailCode', '/txapi/auth/email-code', { ok: true }],
  ['userProfile', '/txapi/me', nativeProfile()],
  ['userSubscription', '/txapi/me/subscription', nativeSubscription()],
  ['userStats', '/txapi/me/dashboard-stats', { unpaid_orders: 2, open_tickets: 1, invited_users: 3 }],
  ['userOrders', '/txapi/orders?page=1&per_page=100', [nativeOrder()], pages],
  ['userOrderDetail', '/txapi/orders/T-1/detail', nativeOrder()],
  ['userOrderStatus', '/txapi/orders/T-1', nativeOrder()],
  ['userPaymentMethods', '/txapi/billing/payment-methods', [{ id: 1, name: 'Pay', provider: 'Stripe', icon: null, fee_fixed_minor: 20, fee_percent: 1.5 }]],
  ['userNotices', '/txapi/notices?page=1&per_page=5', [nativeNotice()], { ...pages, per_page: 5 }],
  ['userOrderCreate', '/txapi/orders', { trade_no: 'T-1', private_note: 'secret' }],
]
describe('current Native Core adapter', () => {
  it.each(fixtures)('uses only the Native route and projects %s', async (operation, path, data, meta) => {
    const fetcher = vi.fn(async () => Response.json(nativeEnvelope(data, meta)))
    const result = await upstreamRequest(config, fetcher, operation, {
      auth: bearer, tradeNo: 'T-1', body: { email: 'user@example.test', password: 'test-password' },
      headers: { 'Idempotency-Key': 'k1', 'X-Idempotency-Key-Hint': 'hint', Origin: 'https://bad.test', Authorization: 'Bearer admin-token' },
    })
    const [url, init] = fetcher.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('https://core.test' + path)
    const headers = new Headers(init.headers)
    expect(headers.get('Authorization')).toBe(operation.startsWith('user') ? bearer : null)
    expect(headers.get('Idempotency-Key')).toBe(operation === 'userOrderCreate' ? 'k1' : null)
    expect(headers.has('Origin')).toBe(false)
    expect(init.redirect).toBe('manual')
    expect(JSON.stringify(result)).not.toContain('secret')
    if (operation === 'guestPlans') expect(result).toMatchObject([{ month_price: 1200, transfer_enable: 1 }])
    if (operation === 'login') expect(result).toEqual({ auth_data: bearer })
    if (operation === 'userStats') expect(result).toEqual([2, 1, 3])
    if (operation === 'userOrderStatus') expect(result).toBe(0)
    if (operation === 'sendEmailCode') expect(result).toBe(true)
  })
  it('projects only common identity fields without financial or credential extensions', async () => {
    const fetcher = vi.fn(async () => Response.json(nativeEnvelope(nativeProfile({
      balance_minor: 12345, expired_at: '2026-10-12T08:00:00+08:00',
      uuid: 'private-uuid', auth_data: 'secret', password: 'secret',
    }))))
    const result = await upstreamRequest(config, fetcher, 'userProfile', { auth: bearer })
    expect(result).toEqual({ id: 1, email: 'alice@example.test', telegram_id: null })
    expect(result).not.toHaveProperty('uuid')
    expect(result).not.toHaveProperty('password')
    expect(result).not.toHaveProperty('auth_data')
  })
  it.each([
    { status: 'success', data: [] }, { data: [], total: 0 },
    { data: [], request_id: 'not-a-uuid' }, { request_id: '12345678-1234-4234-8234-123456789abc' },
    { ...nativeEnvelope([]), error: { code: 'REJECTED', message: 'secret' } },
    { ...nativeEnvelope([]), extra: 'secret' },
  ])('rejects retired, incomplete and ambiguous envelopes', async payload => {
    await expect(upstreamRequest(config, async () => Response.json(payload), 'guestPlans'))
      .rejects.toMatchObject({ code: 'UPSTREAM_ERROR', status: 502 })
  })
  it.each([
    nativePlan({ id: '1' }), nativePlan({ traffic_limit_bytes: -1 }),
    nativePlan({ traffic_limit_bytes: Number.MAX_SAFE_INTEGER + 1 }),
    nativePlan({ prices: [{ period: 'monthly', amount_minor: 1.5 }] }),
    nativePlan({ prices: [{ period: 'month_price', amount_minor: 1200 }] }),
    nativePlan({ prices: [{ period: 'monthly', amount_minor: 1 }, { period: 'monthly', amount_minor: 2 }] }),
    { id: 1, name: 'Missing all Native fields' },
  ])('rejects invalid plan units, periods and types', async row => {
    await expect(upstreamRequest(config, async () => Response.json(nativeEnvelope([row])), 'guestPlans'))
      .rejects.toMatchObject({ status: 502 })
  })
  it('rejects mismatched identifiers and unknown status 5 without coercion', async () => {
    for (const row of [nativeOrder({ trade_no: 'someone-elses-order' }), nativeOrder({ status: 5 })]) {
      await expect(upstreamRequest(config, async () => Response.json(nativeEnvelope(row)), 'userOrderStatus', { auth: bearer, tradeNo: 'T-1' }))
        .rejects.toMatchObject({ status: 502 })
    }
  })
  it('rejects path injection before transport', async () => {
    const fetcher = vi.fn()
    for (const tradeNo of ['../admin', 'bad/value', 'bad?query', 'bad%2Fvalue', '']) {
      await expect(upstreamRequest(config, fetcher, 'userOrderDetail', { auth: bearer, tradeNo }))
        .rejects.toMatchObject({ status: 400 })
    }
    expect(fetcher).not.toHaveBeenCalled()
  })
  it.each([
    undefined, { ...pages, per_page: 20 }, { ...pages, total: 2 },
    { ...pages, total: 101, last_page: 1 }, { ...pages, total: -1 },
  ])('rejects missing and inconsistent Native pagination', async meta => {
    await expect(upstreamRequest(config, async () => Response.json(nativeEnvelope([nativeOrder()], meta)), 'userOrders', { auth: bearer }))
      .rejects.toMatchObject({ status: 502 })
  })
  it.each([401, 403, 404, 422, 429])('preserves Native HTTP %s without leaking error messages', async status => {
    await expect(upstreamRequest(config, async () => Response.json(nativeError('REJECTED', 'password=secret email=private'), { status }), 'userProfile', { auth: bearer }))
      .rejects.toMatchObject({ code: 'UPSTREAM_ERROR', status })
  })
  it('uses nested Native idempotency codes and maps Core business rejection to the v1 vocabulary', async () => {
    for (const code of ['IDEMPOTENCY_IN_PROGRESS', 'IDEMPOTENCY_CONFLICT', 'ORDER_REJECTED']) {
      await expect(upstreamRequest(config, async () => Response.json(nativeError(code), { status: 409 }), 'userOrderCreate', { auth: bearer, body: {} }))
        .rejects.toMatchObject({ code, status: code === 'ORDER_REJECTED' ? 422 : 409 })
    }
  })
  it.each([200, 401, 403, 500])('cannot turn an error with HTTP %s into an order-write business verdict', async status => {
    await expect(upstreamRequest(config, async () => Response.json(nativeError('ORDER_REJECTED'), { status }), 'userOrderCreate', { auth: bearer, body: {} }))
      .rejects.toMatchObject({ code: 'UPSTREAM_ERROR', status: status >= 400 ? status : 502 })
  })
  it('does not accept a success body with an HTTP authorization failure', async () => {
    await expect(upstreamRequest(config, async () => Response.json(nativeEnvelope({ auth_data: bearer }), { status: 401 }), 'login'))
      .rejects.toMatchObject({ status: 502 })
  })
  it('rejects malformed Native subscription timestamps instead of coercing them to null', async () => {
    await expect(upstreamRequest(config, async () => Response.json(nativeEnvelope(nativeSubscription({ expires_at: 'tomorrow' }))), 'userSubscription', { auth: bearer }))
      .rejects.toMatchObject({ status: 502 })
  })
  it('retains exact entitlement decimals and strips internal evidence and credentials', async () => {
    const row = nativeSubscription({
      quota_bytes: '9007199254740993', consumed_bytes: '12',
      remaining_bytes: '9007199254740981', subscription_version: '4',
      token: 'private', uuid: 'private', delivery_endpoint: '/private',
    })
    const result = await upstreamRequest(config, async () => Response.json(nativeEnvelope(row)), 'userSubscription', { auth: bearer })
    expect(result).toMatchObject({ schema: 'core-entitlement/v1', quota_bytes: '9007199254740993', remaining_bytes: '9007199254740981' })
    for (const key of ['token', 'uuid', 'user_id', 'subscription_version', 'delivery_endpoint']) {
      expect(result).not.toHaveProperty(key)
    }
  })
  it('projects a denied current entitlement without fabricating a subscription', async () => {
    const result = await upstreamRequest(config, async () => Response.json(nativeEnvelope({
      schema: 'core-entitlement/v1', authorized: false, reason: 'ENTITLEMENT_SLOT_MISSING',
      user_id: '1', credential_status: 'provider_managed',
    })), 'userSubscription', { auth: bearer })
    expect(result).toEqual({ schema: 'core-entitlement/v1', authorized: false, reason: 'ENTITLEMENT_SLOT_MISSING' })
  })
  it.each([
    { plan: null, upload_bytes: 0, download_bytes: 0, traffic_limit_bytes: 0 },
    nativeSubscription({ quota_bytes: 100 }), nativeSubscription({ quota_bytes: '01' }),
    nativeSubscription({ authorized: 'true' }), nativeSubscription({ reason: 'ENTITLEMENT_SLOT_MISSING' }),
    nativeSubscription({ subscription_id: undefined }), nativeSubscription({ remaining_bytes: '-1' }),
  ])('rejects retired or malformed entitlement DTOs without fallback', async row => {
    await expect(upstreamRequest(config, async () => Response.json(nativeEnvelope(row)), 'userSubscription', { auth: bearer }))
      .rejects.toMatchObject({ status: 502 })
  })
  it('normalizes only an empty PHP array as public theme config', async () => {
    const result = await upstreamRequest(config, async () => Response.json(nativeEnvelope({ frontend_theme: 'TXBoard', theme_config: [] })), 'guestConfig')
    expect(result).toMatchObject({ frontend_theme: 'TXBoard', theme_config: {} })
    await expect(upstreamRequest(config, async () => Response.json(nativeEnvelope({ frontend_theme: 'TXBoard', theme_config: ['secret'] })), 'guestConfig'))
      .rejects.toMatchObject({ status: 502 })
  })
})
