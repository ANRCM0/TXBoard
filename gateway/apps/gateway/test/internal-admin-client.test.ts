import { EventEmitter } from 'node:events'
import type { X509Certificate } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { internalAdminRequest } from '../src/services/internal-admin-client.js'
import { loadInternalAdminConfig, type InternalAdminConfig } from '../src/config/internal-admin.js'

const mock = vi.hoisted(() => ({ request: vi.fn() }))
vi.mock('node:https', () => ({ default: { request: mock.request } }))
const config: InternalAdminConfig = { environment: 'testing', origin: new URL('https://127.0.0.1:19443'),
  servername: 'internal-core.local', ca: Buffer.from('fixture ca'), crl: Buffer.from('fixture crl'),
  cert: Buffer.from('fixture admin cert'), key: Buffer.from('fixture private key'),
  certificate: { validFrom: new Date(Date.now()-60000).toISOString(), validTo: new Date(Date.now()+3600000).toISOString() } as X509Certificate }
const auth = 'Bearer private-synthetic-admin-credential'
const path = 'private-admin-fixture-path'
const input = { admin_path: path }
const traffic = { upload: '9007199254740993', download: '13', total: '9007199254741006' }
const dashboard = { todayIncome: '9007199254740993', currentMonthIncome: '0', lastMonthIncome: '0',
  currentMonthCommissionPayout: '0', lastMonthCommissionPayout: '0', commissionPendingTotal: 0,
  currentMonthNewUsers: 1, totalUsers: 1, activeUsers: 1, onlineUsers: 0, onlineDevices: 0,
  ticketPendingTotal: 0, onlineNodes: 0, dayIncomeGrowth: -12.5, monthIncomeGrowth: 0,
  lastMonthIncomeGrowth: 0, commissionGrowth: 0, userGrowth: 0, todayTraffic: traffic, monthTraffic: traffic, totalTraffic: traffic }
const requestId = '00000000-0000-4000-8000-000000000000'
const envelope = (data: unknown, slice: string) => ({ data, request_id: requestId, meta: { current_only: true, slice } })
const identity = envelope({ id: '1', email: 'admin@example.test', is_admin: true }, 'internal-admin-identity/1')
const planItem = { id: '1', name: 'Hidden plan', content: null, group_id: '2', group: { id: '2', name: 'Current group' },
  traffic_limit_gib: '2', speed_limit_mbps: null, device_limit: 2, capacity_limit: 0, reset_traffic_method: null,
  native_prices_major: { monthly: '9007199254740993.12', yearly: '1e3', quarterly: '0', onetime: null },
  tags: ['native'], show: false, sell: false, renew: false, sort: -10, users_count: 3, active_users_count: 2 }
const sessionItem = { id: '9007199254740993', name: 'Current session', abilities: ['*'],
  last_used_at: null, created_at: '2026-10-13T00:00:00+00:00', updated_at: null, expires_at: null }
let req: EventEmitter & { destroy: ReturnType<typeof vi.fn>; end: ReturnType<typeof vi.fn> }
let reply: EventEmitter & { destroy: ReturnType<typeof vi.fn>; statusCode: number; headers: Record<string,string>; complete: boolean }
let callback: (response: unknown) => void
beforeEach(() => {
  vi.useFakeTimers(); mock.request.mockReset()
  req = Object.assign(new EventEmitter(), { destroy: vi.fn(), end: vi.fn() })
  reply = Object.assign(new EventEmitter(), { destroy: vi.fn(), statusCode: 200, headers: { 'content-type': 'application/json' }, complete: true })
  mock.request.mockImplementation((_options, cb) => { callback = cb; return req })
})
afterEach(() => vi.useRealTimers())
function respond(data: unknown = identity) { callback(reply); reply.emit('data', Buffer.from(JSON.stringify(data))); reply.emit('end') }

describe('isolated Admin Internal consumer', () => {
  it('reads only a closed ordered Admin session list with exact decimal IDs', async () => {
    const pending = internalAdminRequest(config, 'sessions', { auth, input })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/admin.session.list')
    const payload = envelope({ items: [sessionItem] }, 'internal-isolated-admin-sessions/1')
    respond(payload); await expect(pending).resolves.toEqual(payload)
  })
  it.each(['logout','revokeSession'] as const)('delegates %s once with current path and credential', async operation => {
    const params = operation === 'logout' ? input : { ...input, session_id: sessionItem.id }
    const pending = internalAdminRequest(config, operation, { auth, input: params })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/admin.session.'+(operation === 'logout' ? 'logout' : 'revoke'))
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual(params)
    const payload = envelope({ ok: true }, 'internal-isolated-admin-sessions/1')
    respond(payload); await expect(pending).resolves.toEqual(payload);expect(mock.request).toHaveBeenCalledOnce()
  })
  it.each([undefined, 1, '0', '01', '-1', '1.0', '1\n', '1000000000000000000'])
    ('refuses invalid session ID %s before transport', async session_id => {
      await expect(internalAdminRequest(config, 'revokeSession', { auth, input: { ...input, session_id } }))
        .rejects.toMatchObject({ status: 400 })
      expect(mock.request).not.toHaveBeenCalled()
    })
  it('rejects duplicate unordered oversized or private Admin session metadata', async () => {
    for (const items of [[sessionItem,sessionItem], [{ ...sessionItem, id: '1' },sessionItem],
      Array.from({ length: 101 }, () => sessionItem), [{ ...sessionItem, token: 'private-token' }],
      [{ ...sessionItem, expires_at: 'invalid' }]]) {
      const pending = internalAdminRequest(config, 'sessions', { auth, input })
      const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
      respond(envelope({ items }, 'internal-isolated-admin-sessions/1'));await assertion
    }
  })
  it('never retries a rejected or unknown committed Admin logout', async () => {
    const pending = internalAdminRequest(config, 'logout', { auth, input })
    const assertion = expect(pending).rejects.toMatchObject({ status: 503 })
    reply.statusCode = 503;respond({ request_id: requestId, error: { code: 'DEPENDENCY_UNAVAILABLE', message: 'private input' } })
    await assertion;expect(mock.request).toHaveBeenCalledOnce()
  })
  it('consumes native hidden Admin plans without quoting prices or converting GiB to bytes', async () => {
    const input = { admin_path: path, page: 1, per_page: 1 }
    const pending = internalAdminRequest(config, 'plans', { auth, input })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/admin.plan.list')
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual(input)
    const payload = envelope({ items: [planItem], pagination: { page: 1, per_page: 1, total: 1, last_page: 1 } }, 'internal-admin-plan-list/1')
    respond(payload); await expect(pending).resolves.toEqual(payload)
  })
  it.each([{ page: 0 }, { page: 10000 }, { per_page: 101 }, { per_page: '1' }, { status: 4 }, { show: true }])
    ('refuses plan query %j before transport', async patch => {
      await expect(internalAdminRequest(config, 'plans', { auth, input: { admin_path: path, page: 1, per_page: 1, ...patch } }))
        .rejects.toMatchObject({ status: 400 })
      expect(mock.request).not.toHaveBeenCalled()
    })
  it('rejects invalid native plan prices units and unclosed DTOs without repairing them', async () => {
    for (const patch of [{ native_prices_major: { unexpected: '12' } }, { native_prices_major: { monthly: 12 } },
      { native_prices_major: { monthly: '-12' } }, { native_prices_major: { monthly: 'NaN' } },
      { native_prices_major: { monthly: '1\n' } }, { native_prices_major: { monthly: '1'.repeat(129) } },
      { native_prices_major: [] }, { traffic_limit_gib: 2 }, { traffic_limit_gib: '-2' }, { private_token: 'private' }]) {
      const pending = internalAdminRequest(config, 'plans', { auth, input: { admin_path: path, page: 1, per_page: 1 } })
      const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
      respond(envelope({ items: [{ ...planItem, ...patch }], pagination: { page: 1, per_page: 1, total: 1, last_page: 1 } }, 'internal-admin-plan-list/1'))
      await assertion
    }
  })
  it('binds plan page count group association and native user counts to the response', async () => {
    const pagination = { page: 1, per_page: 1, total: 2, last_page: 2 }
    for (const data of [{ items: [planItem], pagination: { ...pagination, page: 2 } },
      { items: [planItem], pagination: { ...pagination, per_page: 2 } },
      { items: [planItem], pagination: { ...pagination, last_page: 1 } }, { items: [planItem, planItem], pagination },
      { items: [{ ...planItem, group: { id: '3', name: 'Wrong group' } }], pagination },
      { items: [{ ...planItem, active_users_count: 4 }], pagination }]) {
      const pending = internalAdminRequest(config, 'plans', { auth, input: { admin_path: path, page: 1, per_page: 1 } })
      const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
      respond(envelope(data, 'internal-admin-plan-list/1')); await assertion
    }
  })
  it('uses only fixed verified Admin TLS operation and actual credential', async () => {
    const pending = internalAdminRequest(config, 'identity', { auth, input })
    const options = mock.request.mock.calls[0]![0]
    expect(options).toMatchObject({ hostname: '127.0.0.1', port: '19443', servername: 'internal-core.local',
      rejectUnauthorized: true, minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', agent: false, method: 'POST',
      path: '/txapi/internal/v1/operations/admin.identity.get', cert: config.cert, key: config.key })
    expect(Object.keys(options.headers).sort()).toEqual(['accept','authorization','content-length','content-type'])
    expect(options.headers.authorization).toBe(auth)
    const body = JSON.parse(req.end.mock.calls[0]![0].toString())
    expect(body).toMatchObject({ contract_version: '1', input, deadline_at: Math.floor(Date.now()/1000)+5 })
    respond(); await expect(pending).resolves.toEqual(identity)
    expect(req.destroy).toHaveBeenCalledOnce(); expect(vi.getTimerCount()).toBe(0)
  })
  it('delivers only private native Admin login DTO and preserves password bytes', async () => {
    const credentials = { email: 'admin@example.test', password: '  private password  ' }
    const pending = internalAdminRequest(config, 'login', { input: credentials })
    const options = mock.request.mock.calls[0]![0]
    expect(options.path).toBe('/txapi/internal/v1/operations/auth.admin.login')
    expect(options.headers).not.toHaveProperty('authorization')
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual(credentials)
    const payload = envelope({ auth_data: auth, is_admin: true, secure_path: path }, 'internal-isolated-admin-login/1')
    respond(payload); await expect(pending).resolves.toEqual(payload)
  })
  it('dispatches bounded native Admin order list and preserves orphan/discounted exact money', async () => {
    const input = { admin_path: path, page: 2, per_page: 1, status: 4 }
    const pending = internalAdminRequest(config, 'orders', { auth, input })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/admin.order.list')
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual(input)
    const payload = envelope({ items: [{ id: '1', trade_no: 'T-1', user_id: '2', plan_id: '3', plan: null, user: null,
      period: 'monthly', type: 1, status: 4, amount_minor: '9007199254740993', handling_amount_minor: '17',
      balance_amount_minor: '100', discount_amount_minor: '25', paid_at: null, created_at: 1791763200, updated_at: 1791763200 }],
      pagination: { page: 2, per_page: 1, total: 2, last_page: 2 } }, 'internal-admin-order-list/1')
    respond(payload); await expect(pending).resolves.toEqual(payload)
  })
  it.each([{ page: 0 }, { page: 10000 }, { per_page: 101 }, { status: 5 }, { status: '4' }, { callback_no: 'private' }])
    ('refuses invalid order query %j before any connection', async patch => {
      await expect(internalAdminRequest(config, 'orders', { auth, input: { admin_path: path, page: 1, per_page: 20, ...patch } }))
        .rejects.toMatchObject({ status: 400 })
      expect(mock.request).not.toHaveBeenCalled()
    })
  it('refuses private order references even in authenticated Core response', async () => {
    const pending = internalAdminRequest(config, 'orders', { auth, input: { admin_path: path, page: 1, per_page: 20 } })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    respond(envelope({ items: [{ callback_no: 'private' }], pagination: { page: 1, per_page: 20, total: 1, last_page: 1 } }, 'internal-admin-order-list/1'))
    await assertion
  })
  it('binds native order pagination filter and associated IDs to the requested DTO', async () => {
    const item = { id: '1', trade_no: 'T-1', user_id: '2', plan_id: '3', plan: null, user: null,
      period: 'monthly', type: 1, status: 4, amount_minor: '1234', handling_amount_minor: '17',
      balance_amount_minor: '100', discount_amount_minor: '25', paid_at: null, created_at: 1791763200, updated_at: 1791763200 }
    const pagination = { page: 1, per_page: 1, total: 2, last_page: 2 }
    for (const data of [{ items: [item], pagination: { ...pagination, page: 2 } },
      { items: [item], pagination: { ...pagination, per_page: 2 } },
      { items: [item], pagination: { ...pagination, last_page: 1 } },
      { items: [item, item], pagination }, { items: [{ ...item, status: 0 }], pagination },
      { items: [{ ...item, plan: { id: '4', name: 'Wrong' } }], pagination },
      { items: [{ ...item, user: { id: '4', email: 'wrong@example.test' } }], pagination }]) {
      const pending = internalAdminRequest(config, 'orders', { auth, input: { admin_path: path, page: 1, per_page: 1, status: 4 } })
      const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
      respond(envelope(data, 'internal-admin-order-list/1')); await assertion
    }
  })
  it('preserves signed and greater-than-53-bit native money and traffic strings', async () => {
    const pending = internalAdminRequest(config, 'dashboard', { auth, input })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/admin.dashboard.get')
    const payload = envelope({ ...dashboard, lastMonthIncome: '-9223372036854775808' }, 'internal-admin-dashboard/1')
    respond(payload); await expect(pending).resolves.toEqual(payload)
  })
  it.each(['1', '0', 'TRUE', 'False', ' true', 'false ', 'yes'])('refuses ambiguous enable flag %s', enabled => {
    expect(() => loadInternalAdminConfig({ APP_ENV: 'testing', GATEWAY_INTERNAL_ADMIN_ENABLED: enabled })).toThrow('Invalid local/testing Internal Admin configuration')
  })
  it('defaults off and refuses production or missing trusted configuration', async () => {
    for (const enabled of [undefined, '', 'false']) expect(loadInternalAdminConfig({ GATEWAY_INTERNAL_ADMIN_ENABLED: enabled })).toBeUndefined()
    for (const APP_ENV of [undefined, 'production', 'testing']) {
      expect(() => loadInternalAdminConfig({ APP_ENV, GATEWAY_INTERNAL_ADMIN_ENABLED: 'true' })).toThrow('Invalid local/testing Internal Admin configuration')
    }
    await expect(internalAdminRequest(undefined, 'identity', { auth, input })).rejects.toMatchObject({ status: 404 })
    await expect(internalAdminRequest({ ...config, environment: 'production' } as unknown as InternalAdminConfig, 'identity', { auth, input })).rejects.toMatchObject({ status: 404 })
    await expect(internalAdminRequest(config, 'user.profile.get' as 'identity', { auth, input })).rejects.toMatchObject({ status: 404 })
    expect(mock.request).not.toHaveBeenCalled()
  })
  it.each([undefined, '', 'Bearer injected\n', 'Basic wrong', 'Bearer '+ 'x'.repeat(4090)])('refuses invalid read credential before transport', async value => {
    await expect(internalAdminRequest(config, 'identity', { auth: value, input })).rejects.toMatchObject({ status: 401 })
    expect(mock.request).not.toHaveBeenCalled()
  })
  it('refuses guest Authorization, extra role, invalid paths and nonsynthetic login before transport', async () => {
    for (const auth of ['', 'Bearer user']) await expect(internalAdminRequest(config, 'login', { auth,
      input: { email: 'admin@example.test', password: 'private-password' } })).rejects.toMatchObject({ status: 400 })
    for (const input of [{ admin_path: path, actor_id: '1' }, { admin_path: '../secret' }, { admin_path: path+'\n' }, {}]) {
      await expect(internalAdminRequest(config, 'identity', { auth, input })).rejects.toMatchObject({ status: 400 })
    }
    for (const input of [{ email: 'admin@example.com', password: 'private-password' }, { email: 'admin@example.test', password: 'private-password', role: 'admin' }]) {
      await expect(internalAdminRequest(config, 'login', { input })).rejects.toMatchObject({ status: 400 })
    }
    expect(mock.request).not.toHaveBeenCalled()
  })
  it.each(['01', '1e3', '1.0', '1\n', '9223372036854775808', '-9223372036854775809'])('rejects noncanonical/overflow dashboard value %s', async value => {
    const pending = internalAdminRequest(config, 'dashboard', { auth, input })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    respond(envelope({ ...dashboard, todayIncome: value }, 'internal-admin-dashboard/1')); await assertion
  })
  it('rejects wrong slice, private extensions, ordinary login DTO and unsafe numbers', async () => {
    for (const payload of [envelope(dashboard, 'internal-readonly/2'), envelope({ ...dashboard, uuid: 'private' }, 'internal-admin-dashboard/1'),
      envelope({ ...dashboard, totalUsers: 9007199254740992 }, 'internal-admin-dashboard/1'),
      envelope({ ...dashboard, todayTraffic: { ...traffic, upload: 1 } }, 'internal-admin-dashboard/1')]) {
      const pending = internalAdminRequest(config, 'dashboard', { auth, input })
      const assertion = expect(pending).rejects.toMatchObject({ status: 502 }); respond(payload); await assertion
    }
    const pending = internalAdminRequest(config, 'login', { input: { email: 'admin@example.test', password: 'private-password' } })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    respond(envelope({ auth_data: auth }, 'internal-isolated-login/1')); await assertion
  })
  it('rejects duplicate escaped keys and invalid UTF-8', async () => {
    for (const raw of [Buffer.from(JSON.stringify(identity).replace('"id":"1"', '"id":"1","\\u0069d":"2"')), Buffer.from([0xff])]) {
      const pending = internalAdminRequest(config, 'identity', { auth, input })
      const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
      callback(reply); reply.emit('data', raw); reply.emit('end'); await assertion
    }
  })
  it.each([401,403,404,409,422,429,500,503,504])('retains allowed Core status %s without reflecting private error or retrying', async status => {
    const pending = internalAdminRequest(config, 'identity', { auth, input })
    const assertion = expect(pending).rejects.toMatchObject({ status, message: 'Internal Admin operation rejected' })
    reply.statusCode = status; respond({ error: { code: 'FIXTURE_REJECTED', message: auth, fields: [path] }, request_id: requestId }); await assertion
    expect(mock.request).toHaveBeenCalledOnce(); expect(vi.getTimerCount()).toBe(0)
  })
  it.each(['redirect', 'html', 'declared-size', 'actual-size', 'tls', 'incomplete', 'sync'])('bounds %s failures with fixed error and no retry', async kind => {
    if (kind === 'sync') mock.request.mockImplementationOnce(() => { throw new Error(auth) })
    const pending = internalAdminRequest(config, 'identity', { auth, input })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    if (kind === 'redirect') { reply.statusCode = 302; callback(reply) }
    if (kind === 'html') { reply.headers['content-type'] = 'text/html'; callback(reply) }
    if (kind === 'declared-size') { reply.headers['content-length'] = '65537'; callback(reply) }
    if (kind === 'actual-size') { callback(reply); reply.emit('data', Buffer.alloc(65537)) }
    if (kind === 'tls') req.emit('error', new Error(auth))
    if (kind === 'incomplete') { callback(reply); reply.complete = false; reply.emit('close') }
    await assertion; expect(mock.request).toHaveBeenCalledOnce(); expect(vi.getTimerCount()).toBe(0)
  })
  it('uses wall timeout even for continuous chunks and cancels before sending', async () => {
    const pending = internalAdminRequest(config, 'identity', { auth, input })
    const assertion = expect(pending).rejects.toMatchObject({ status: 504 })
    callback(reply); reply.emit('data', Buffer.from('{'))
    await vi.advanceTimersByTimeAsync(5000); await assertion
    const abort = new AbortController(); abort.abort(); mock.request.mockClear(); req.end.mockClear()
    await expect(internalAdminRequest(config, 'identity', { auth, input, signal: abort.signal })).rejects.toMatchObject({ status: 502 })
    expect(req.end).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it('refuses expired client and UTF-8 body overflow before opening a connection', async () => {
    const expired = { ...config, certificate: { validFrom: '2000-01-01', validTo: '2001-01-01' } as X509Certificate }
    await expect(internalAdminRequest(expired, 'identity', { auth, input })).rejects.toMatchObject({ status: 502 })
    await expect(internalAdminRequest(config, 'login', { input: { email: 'admin@example.test', password: 'private-password',
      turnstile_token: '界'.repeat(4096), recaptcha_v3_token: '界'.repeat(4096) } })).rejects.toMatchObject({ status: 400 })
    expect(mock.request).not.toHaveBeenCalled()
  })
})
