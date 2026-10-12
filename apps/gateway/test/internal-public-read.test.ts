import { EventEmitter } from 'node:events'
import type { X509Certificate } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { loadConfig, type GatewayConfig } from '../src/config/env.js'
import { adaptInternalPublic } from '../src/adapters/internal-user-v2.js'
import { adaptNativeData } from '../src/adapters/txapi-native.js'
import { toPublicCaptcha, toPublicSite, toPublicTheme } from '../src/adapters/txboard-v1.js'
import { upstreamRequest } from '../src/services/upstream.js'
import { nativeEnvelope, nativePlan } from './native-fixtures.js'

const mock = vi.hoisted(() => ({ request: vi.fn(), loadTransport: vi.fn() }))
vi.mock('node:https', () => ({ default: { request: mock.request } }))
// Only the new public flag is under test here. Existing PKI config tests and
// real TLS integration cover the actual trusted transport loader separately.
vi.mock('../src/config/internal-user.js', () => ({ loadInternalUserConfig: mock.loadTransport }))

const requestId = '12345678-1234-4234-8234-123456789abc'
const site = { app_name: 'Public Fixture', app_description: null, app_url: 'https://site.example.test', logo: null,
  frontend_theme: 'Fixture Theme', theme_config: { accent: 'blue' }, is_captcha: '1', captcha_type: 'turnstile',
  turnstile_site_key: 'public-site-key', recaptcha_site_key: null, recaptcha_v3_site_key: null }
const plan = (overrides: Record<string, unknown> = {}) => ({ id: '1', name: 'Native Quote', content: '<p>Display</p>',
  tags: ['fixture'], traffic_limit_bytes: '3221225472', speed_limit_mbps: '0', device_limit: '2',
  capacity_limit: null, reset_traffic_method: '4', renewable: false,
  prices: [{ period: 'monthly', amount_minor: '1235' }, { period: 'onetime', amount_minor: '725' }], ...overrides })
const envelope = (data: unknown) => ({ data, request_id: requestId,
  meta: { current_only: true, slice: 'internal-public-readonly/1' } })
let config: GatewayConfig
let req: EventEmitter & { destroy: ReturnType<typeof vi.fn>; end: ReturnType<typeof vi.fn> }
let reply: EventEmitter & { destroy: ReturnType<typeof vi.fn>; statusCode: number; headers: Record<string, string>; complete: boolean }
let callback: (value: unknown) => void

beforeEach(() => {
  vi.useFakeTimers()
  mock.request.mockReset(); mock.loadTransport.mockReset()
  const internalUser = { origin: new URL('https://127.0.0.1:19443'), servername: 'internal-core.local' as const,
    ca: Buffer.from('ca'), crl: Buffer.from('crl'), cert: Buffer.from('user cert'), key: Buffer.from('private fixture key'),
    certificate: { validFrom: new Date(Date.now() - 60000).toISOString(), validTo: new Date(Date.now() + 3600000).toISOString() } as X509Certificate }
  config = { ...loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test' }), timeoutMs: 5000,
    internalUser, internalPublicReadEnabled: true }
  req = Object.assign(new EventEmitter(), { destroy: vi.fn(), end: vi.fn() })
  reply = Object.assign(new EventEmitter(), { destroy: vi.fn(), statusCode: 200,
    headers: { 'content-type': 'application/json' }, complete: true })
  mock.request.mockImplementation((_options, cb) => { callback = cb; return req })
})
afterEach(() => { vi.clearAllTimers(); vi.useRealTimers() })
function respond(value: unknown) { callback(reply); reply.emit('data', Buffer.from(JSON.stringify(value))); reply.emit('end') }
function respondRaw(value: Buffer | string) { callback(reply); reply.emit('data', Buffer.isBuffer(value) ? value : Buffer.from(value)); reply.emit('end') }

describe('fixed anonymous Internal public read consumer', () => {
  it.each([
    ['guestConfig', 'public.site-config.get', site],
    ['guestPlans', 'public.plan.list', [plan()]],
  ] as const)('posts %s as v1 with empty input and no visitor authorization', async (operation, id, data) => {
    const native = vi.fn()
    const pending = upstreamRequest(config, native, operation, {
      body: { actor_id: 'admin', password: 'never-forward-this' }, headers: { Authorization: 'Bearer visitor', 'X-Actor-Id': 'admin' },
    })
    const options = mock.request.mock.calls[0]![0]
    expect(options).toMatchObject({ hostname: '127.0.0.1', port: '19443', servername: 'internal-core.local',
      rejectUnauthorized: true, minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', agent: false, method: 'POST',
      path: '/txapi/internal/v1/operations/' + id })
    expect(Object.keys(options.headers).sort()).toEqual(['accept', 'content-length', 'content-type'])
    const body = JSON.parse(req.end.mock.calls[0]![0].toString())
    expect(Object.keys(body).sort()).toEqual(['contract_version', 'deadline_at', 'input', 'request_id'])
    expect(body).toMatchObject({ contract_version: '1', input: {} })
    expect(body.request_id).toMatch(/^[0-9a-f-]{36}$/)
    expect(body.deadline_at).toBe(Math.floor(Date.now() / 1000) + 5)
    expect(options.headers['content-length']).toBe(req.end.mock.calls[0]![0].length)
    respond(envelope(data)); await expect(pending).resolves.toEqual(adaptInternalPublic(operation, data))
    expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1)
    expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['', 'Bearer real-user', 'Bearer real-admin', 'Bearer node-credential'])(
    'refuses explicitly supplied public actor %j before opening either transport', async auth => {
      const native = vi.fn()
      await expect(upstreamRequest(config, native, 'guestConfig', { auth })).rejects.toMatchObject({ status: 400 })
      await expect(upstreamRequest(config, native, 'guestPlans', { auth })).rejects.toMatchObject({ status: 400 })
      expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled()
    })

  it('does not make protected reads or order writes anonymous', async () => {
    const native = vi.fn()
    await expect(upstreamRequest(config, native, 'userProfile')).rejects.toMatchObject({ status: 401 })
    await expect(upstreamRequest(config, native, 'userOrderDetail', { tradeNo: 'T-1' })).rejects.toMatchObject({ status: 401 })
    await expect(upstreamRequest(config, native, 'userOrderCreate', { auth: 'Bearer actual-user' })).rejects.toMatchObject({ status: 405 })
    expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled()
  })

  it.each([false, undefined])('preserves Native public reads when the extra flag is %s', async enabled => {
    const disabled = { ...config, internalPublicReadEnabled: enabled }
    const native = vi.fn(async (url: unknown, _options?: RequestInit) => new Response(JSON.stringify(nativeEnvelope(
      String(url).endsWith('/txapi/plans') ? [nativePlan()] : { ...site, extraHookSecret: 'not-public' },
    )), { headers: { 'content-type': 'application/json' } }))
    await expect(upstreamRequest(disabled, native, 'guestPlans')).resolves.toMatchObject([{ id: 1, name: 'Plan' }])
    await expect(upstreamRequest(disabled, native, 'guestConfig')).resolves.toEqual(site)
    expect(native).toHaveBeenCalledTimes(2); expect(mock.request).not.toHaveBeenCalled()
    for (const [, options] of native.mock.calls) expect(new Headers((options as RequestInit | undefined)?.headers).has('authorization')).toBe(false)
  })

  it('keeps the unavailable anonymous payment catalog at 404 with zero I/O', async () => {
    const native = vi.fn()
    await expect(upstreamRequest({ ...config, paymentCatalogEnabled: true }, native, 'guestPaymentMethods'))
      .rejects.toMatchObject({ status: 404 })
    expect(native).not.toHaveBeenCalled(); expect(mock.request).not.toHaveBeenCalled()
  })

  it('public opt-in cannot select the existing login operation', async () => {
    const native = vi.fn(async (_url: unknown, _options?: RequestInit) => new Response(JSON.stringify(nativeEnvelope({ auth_data: 'Bearer native-issued-session' })),
      { headers: { 'content-type': 'application/json' } }))
    await expect(upstreamRequest(config, native, 'login', { body: { email: 'user@example.test', password: 'fixture-password' } }))
      .resolves.toEqual({ auth_data: 'Bearer native-issued-session' })
    expect(native).toHaveBeenCalledTimes(1); expect(mock.request).not.toHaveBeenCalled()
    expect(String(native.mock.calls[0]![0])).toBe('https://core.test/txapi/auth/login')
  })

  it('requires exact local opt-in and a separately loaded trusted Internal transport', () => {
    const base = { TXBOARD_UPSTREAM_URL: 'https://core.test', APP_ENV: 'testing' }
    for (const disabled of [undefined, '', 'false']) {
      expect(loadConfig({ ...base, GATEWAY_INTERNAL_PUBLIC_READ_ENABLED: disabled }).internalPublicReadEnabled).toBe(false)
    }
    expect(() => loadConfig({ ...base, GATEWAY_INTERNAL_PUBLIC_READ_ENABLED: 'true' })).toThrow('public-read configuration')
    mock.loadTransport.mockReturnValue(config.internalUser)
    for (const invalid of ['1', 'TRUE', 'true ', ' false', 'yes']) {
      expect(() => loadConfig({ ...base, GATEWAY_INTERNAL_PUBLIC_READ_ENABLED: invalid })).toThrow('public-read configuration')
    }
    expect(() => loadConfig({ ...base, APP_ENV: 'production', GATEWAY_INTERNAL_PUBLIC_READ_ENABLED: 'true' })).toThrow('public-read configuration')
    expect(loadConfig({ ...base, GATEWAY_INTERNAL_PUBLIC_READ_ENABLED: 'true' }).internalPublicReadEnabled).toBe(true)
  })
})

describe('public DTO compatibility and fail-closed schema', () => {
  it('retains exact Native price, byte and display projection', () => {
    const expected = adaptNativeData('guestPlans', [nativePlan({ name: 'Native Quote', content: '<p>Display</p>', tags: ['fixture'],
      traffic_limit_bytes: 3221225472, speed_limit_mbps: 0, device_limit: 2, reset_traffic_method: 4, renewable: false,
      prices: [{ period: 'monthly', amount_minor: 1235 }, { period: 'onetime', amount_minor: 725 }] })], undefined)
    const actual = adaptInternalPublic('guestPlans', [plan()])
    expect(actual).toEqual(expected)
    expect(actual).toMatchObject([{ transfer_enable: 3, month_price: 1235, onetime_price: 725, speed_limit: 0,
      device_limit: 2, capacity_limit: null, reset_traffic_method: 4, renew: false, quarter_price: null, reset_price: null }])
    expect(adaptInternalPublic('guestPlans', [])).toEqual([])
    const publicSite = adaptInternalPublic('guestConfig', site) as Record<string, unknown>
    expect(toPublicSite(publicSite)).toEqual({ name: 'Public Fixture', description: '', url: 'https://site.example.test', logo: '' })
    expect(toPublicTheme(publicSite)).toEqual({ name: 'Fixture Theme', config: { accent: 'blue' } })
    expect(toPublicCaptcha(publicSite)).toEqual({ enabled: true, type: 'turnstile', siteKey: 'public-site-key' })
  })

  it('preserves optional site defaults and only the existing empty-array config variant', () => {
    const empty = adaptInternalPublic('guestConfig', {}) as Record<string, unknown>
    expect(toPublicSite(empty)).toEqual({ name: 'TXBoard', description: '', url: '', logo: '' })
    expect(toPublicTheme(empty)).toEqual({ name: 'TXBoard', config: {} })
    expect(toPublicCaptcha(empty)).toEqual({ enabled: false, type: null, siteKey: null })
    expect(adaptInternalPublic('guestConfig', { theme_config: [] })).toEqual({ theme_config: {} })
  })

  it.each([true, 1, '1', false, 0, '0'])('preserves the existing captcha flag %j', flag => {
    const value = adaptInternalPublic('guestConfig', { ...site, is_captcha: flag }) as Record<string, unknown>
    expect(toPublicCaptcha(value)).toEqual(flag === true || flag === 1 || flag === '1'
      ? { enabled: true, type: 'turnstile', siteKey: 'public-site-key' } : { enabled: false, type: null, siteKey: null })
  })

  it.each([
    ['numeric-id', [plan({ id: 1 })]], ['zero-id', [plan({ id: '0' })]], ['leading-zero', [plan({ id: '01' })]],
    ['unsafe-id', [plan({ id: '9007199254740992' })]], ['unsafe-bytes', [plan({ traffic_limit_bytes: '9007199254740992' })]],
    ['negative-limit', [plan({ speed_limit_mbps: '-1' })]], ['unsafe-limit', [plan({ device_limit: '9007199254740992' })]],
    ['scientific', [plan({ traffic_limit_bytes: '1e3' })]], ['trailing-newline', [plan({ id: '1\n' })]],
    ['extra-plan-field', [plan({ token: 'secret' })]], ['bad-period', [plan({ prices: [{ period: 'month_price', amount_minor: '1' }] })]],
    ['unsafe-price', [plan({ prices: [{ period: 'monthly', amount_minor: '9007199254740992' }] })]],
    ['extra-price-field', [plan({ prices: [{ period: 'monthly', amount_minor: '1', raw_secret: 'secret' }] })]],
    ['duplicate-period', [plan({ prices: [{ period: 'monthly', amount_minor: '1' }, { period: 'monthly', amount_minor: '2' }] })]],
    ['duplicate-plan-id', [plan(), plan()]], ['null-tags', [plan({ tags: null })]],
  ])('rejects %s through the actual Internal consumer with no Native fallback', async (_name, data) => {
    const native = vi.fn()
    const pending = upstreamRequest(config, native, 'guestPlans')
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    respond(envelope(data)); await assertion
    expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1); expect(vi.getTimerCount()).toBe(0)
  })

  it('accepts maximum-safe integer display values without silently rounding them', () => {
    expect(adaptInternalPublic('guestPlans', [plan({ id: '9007199254740991', capacity_limit: '9007199254740991',
      prices: [{ period: 'monthly', amount_minor: '9007199254740991' }] })]))
      .toMatchObject([{ id: Number.MAX_SAFE_INTEGER, capacity_limit: Number.MAX_SAFE_INTEGER, month_price: Number.MAX_SAFE_INTEGER }])
  })

  it.each([
    { ...site, extraHookSecret: 'secret' }, { ...site, app_name: 1 }, { ...site, frontend_theme: null },
    { ...site, theme_config: ['not-an-object'] }, { ...site, is_captcha: 'yes' }, { ...site, turnstile_secret_key: 'secret' },
  ])('refuses malformed or widened public metadata %j', async data => {
    const native = vi.fn(); const pending = upstreamRequest(config, native, 'guestConfig')
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    respond(envelope(data)); await assertion; expect(native).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
})

describe('public transport failures cannot become Native success', () => {
  it.each([401, 403, 404, 409, 429, 503, 504])('preserves safe HTTP%s rejection without exposing upstream text', async status => {
    const native = vi.fn(); const pending = upstreamRequest(config, native, 'guestConfig')
    const assertion = expect(pending).rejects.toMatchObject({ code: 'UPSTREAM_ERROR', status,
      message: status === 401 ? 'Authentication failed' : status === 403 ? 'Access denied' : status === 404 ? 'Resource not found' : 'Internal request rejected' })
    reply.statusCode = status
    respond({ error: { code: 'OPERATION_DISABLED', message: 'private fixture key and secret provider text' }, request_id: requestId })
    await assertion; expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1); expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['protected-meta', 'write-meta', 'missing-meta', 'extra-meta', 'false-current', 'wrong-status',
    'duplicate', 'nested-duplicate', 'bad-utf8', 'redirect', 'oversize', 'header-oversize', 'upstream-abort'])(
    'refuses %s under the fixed public response contract', async kind => {
      const native = vi.fn(); const pending = upstreamRequest(config, native, 'guestPlans')
      const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
      if (kind === 'protected-meta') respond({ ...envelope([plan()]), meta: { current_only: true, slice: 'internal-readonly/2' } })
      if (kind === 'write-meta') respond({ ...envelope([plan()]), meta: { current_only: true, slice: 'internal-order-write/1' } })
      if (kind === 'missing-meta') respond({ data: [plan()], request_id: requestId })
      if (kind === 'extra-meta') respond({ ...envelope([plan()]), meta: { ...envelope([]).meta, actor_id: '1' } })
      if (kind === 'false-current') respond({ ...envelope([plan()]), meta: { current_only: false, slice: 'internal-public-readonly/1' } })
      if (kind === 'wrong-status') { reply.statusCode = 201; respond(envelope([plan()])) }
      if (kind === 'duplicate') respondRaw('{"data":[],"\\u0064ata":[],"request_id":"'+requestId+'","meta":{"current_only":true,"slice":"internal-public-readonly/1"}}')
      if (kind === 'nested-duplicate') respondRaw(JSON.stringify(envelope([plan()])).replace('"id":"1"', '"id":"1","\\u0069d":"2"'))
      if (kind === 'bad-utf8') respondRaw(Buffer.from([0xff]))
      if (kind === 'redirect') { reply.statusCode = 302; callback(reply) }
      if (kind === 'oversize') { callback(reply); reply.emit('data', Buffer.alloc(65537)) }
      if (kind === 'header-oversize') { reply.headers['content-length'] = '65537'; callback(reply) }
      if (kind === 'upstream-abort') { callback(reply); reply.complete = false; reply.emit('close') }
      await assertion; expect(native).not.toHaveBeenCalled(); expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
    })

  it('total five-second deadline is not refreshed by slow chunks', async () => {
    const native = vi.fn(); const pending = upstreamRequest(config, native, 'guestConfig')
    const assertion = expect(pending).rejects.toMatchObject({ status: 504 })
    callback(reply)
    for (let i = 0; i < 4; i++) { reply.emit('data', Buffer.from(' ')); await vi.advanceTimersByTimeAsync(1000) }
    await vi.advanceTimersByTimeAsync(1000); await assertion
    expect(req.destroy).toHaveBeenCalled(); expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('TLS transport error cleans up without retry or fallback', async () => {
    const native = vi.fn(); const pending = upstreamRequest(config, native, 'guestConfig')
    const assertion = expect(pending).rejects.toMatchObject({ status: 502, message: 'Internal connection failed' })
    req.emit('error', new Error('private certificate/key fixture')); await assertion
    expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1); expect(vi.getTimerCount()).toBe(0)
  })

  it('caller abort closes the public transport and removes its total timer', async () => {
    const native = vi.fn(); const controller = new AbortController()
    const pending = upstreamRequest(config, native, 'guestPlans', { signal: controller.signal })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502, message: 'Internal request cancelled' })
    controller.abort(); await assertion
    expect(req.destroy).toHaveBeenCalled(); expect(native).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })

  it('expired client identity is refused before any network request', async () => {
    const native = vi.fn()
    const expired = { ...config, internalUser: { ...config.internalUser!, certificate: {
      validFrom: new Date(0).toISOString(), validTo: new Date(1).toISOString(),
    } as X509Certificate } }
    await expect(upstreamRequest(expired, native, 'guestPlans')).rejects.toMatchObject({ status: 502 })
    expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled()
  })
})
