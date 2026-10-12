import { EventEmitter } from 'node:events'
import type { X509Certificate } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { GatewayConfig } from '../src/config/env.js'
import { loadConfig } from '../src/config/env.js'
import { upstreamRequest } from '../src/services/upstream.js'
import { nativeEnvelope, nativeProfile } from './native-fixtures.js'

const mock = vi.hoisted(() => ({ request: vi.fn() }))
vi.mock('node:https', () => ({ default: { request: mock.request } }))
const config: GatewayConfig = { ...loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test' }), timeoutMs: 5000,
  internalUser: { origin: new URL('https://127.0.0.1:19443'), servername: 'internal-core.local', ca: Buffer.from('ca'),
    crl: Buffer.from('crl'), cert: Buffer.from('user cert'), key: Buffer.from('private fixture key'),
    certificate: { validFrom: new Date(Date.now()-60000).toISOString(), validTo: new Date(Date.now()+3600000).toISOString() } as X509Certificate } }
const token = 'Bearer real-actor-credential'
const profile = { id: '1', email: 'user@example.test', plan_id: null, balance_minor: '0', commission_balance_minor: '0',
  expired_at: null, telegram_id: null, traffic: { upload_bytes: '0', download_bytes: '0', limit_bytes: '0' } }
const envelope = { data: profile, request_id: '00000000-0000-4000-8000-000000000000', meta: { current_only: true, slice: 'internal-readonly/2' } }
let req: EventEmitter & { destroy: ReturnType<typeof vi.fn>; end: ReturnType<typeof vi.fn> }
let reply: EventEmitter & { destroy: ReturnType<typeof vi.fn>; statusCode: number; headers: Record<string,string>; complete: boolean }
let callback: (reply: unknown) => void
beforeEach(() => {
  vi.useFakeTimers(); mock.request.mockReset()
  req = Object.assign(new EventEmitter(), { destroy: vi.fn(), end: vi.fn() })
  reply = Object.assign(new EventEmitter(), { destroy: vi.fn(), statusCode: 200, headers: { 'content-type': 'application/json' }, complete: true })
  mock.request.mockImplementation((_options, cb) => { callback = cb; return req })
})
afterEach(() => vi.useRealTimers())
function respond(payload: unknown = envelope) { callback(reply); reply.emit('data', Buffer.from(JSON.stringify(payload))); reply.emit('end') }
describe('Internal client transport fences', () => {
  it('posts only fixed v2 op and exact actor over verified TLS; no Native call', async () => {
    const native = vi.fn()
    const pending = upstreamRequest(config, native, 'userProfile', { auth: token, headers: { 'X-Actor-Id': 'admin' } })
    const options = mock.request.mock.calls[0]![0]
    expect(options).toMatchObject({ hostname: '127.0.0.1', port: '19443', servername: 'internal-core.local', rejectUnauthorized: true,
      minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', agent: false, method: 'POST', path: '/txapi/internal/v1/operations/user.profile.get' })
    expect(Object.keys(options.headers).sort()).toEqual(['accept','authorization','content-length','content-type'])
    expect(options.headers.authorization).toBe(token)
    const body = JSON.parse(req.end.mock.calls[0]![0].toString())
    expect(body).toMatchObject({ contract_version: '2', input: {} }); expect(body.deadline_at).toBe(Math.floor(Date.now()/1000)+5)
    respond(); await expect(pending).resolves.toMatchObject({ email: 'user@example.test' }); expect(native).not.toHaveBeenCalled()
    expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it.each([
    ['userStats', 'user.dashboard.get', { unpaid_orders: 1, open_tickets: 2, invited_users: 3 }, [1, 2, 3]],
    ['userPaymentMethods', 'user.payment-methods.list', [{ id: '2', name: 'Fixture', provider: 'Fixture', icon: null,
      fee_fixed_minor: '17', fee_percent: 2.5 }], [{ id: 2, name: 'Fixture', payment: 'Fixture', icon: null,
      handling_fee_fixed: 17, handling_fee_percent: 2.5 }]],
  ] as const)('dispatches %s with exact empty input and actual actor', async (operation, id, data, expected) => {
    const native = vi.fn()
    const pending = upstreamRequest(config, native, operation, { auth: token })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/' + id)
    expect(mock.request.mock.calls[0]![0].headers.authorization).toBe(token)
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual({})
    respond({ ...envelope, data }); await expect(pending).resolves.toEqual(expected)
    expect(native).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it.each(['userStats', 'userPaymentMethods'] as const)('preserves %s actor and transport failures without fallback', async operation => {
    const native = vi.fn()
    await expect(upstreamRequest(config, native, operation)).rejects.toMatchObject({ status: 401 })
    expect(mock.request).not.toHaveBeenCalled()
    for (const status of [401, 403, 502]) {
      const pending = upstreamRequest(config, native, operation, { auth: token })
      const assertion = expect(pending).rejects.toMatchObject({ status })
      reply.statusCode = status
      respond({ error: { code: 'FIXTURE_DENIED', message: 'Denied' }, request_id: envelope.request_id })
      await assertion
    }
    expect(native).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it.each(['userSubscription', 'userNotices'] as const)('dispatches %s and refuses absent actors without Native fallback', async operation => {
    const native = vi.fn()
    await expect(upstreamRequest(config, native, operation)).rejects.toMatchObject({ status: 401 })
    expect(mock.request).not.toHaveBeenCalled()
    const pending = upstreamRequest(config, native, operation, { auth: token, ...(operation === 'userNotices' ? { current: 2, pageSize: 5 } : {}) })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/' + (operation === 'userNotices' ? 'user.notice.list' : 'user.subscription-summary.get'))
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual(operation === 'userNotices' ? { page: 2, per_page: 5 } : {})
    expect(mock.request.mock.calls[0]![0].headers.authorization).toBe(token)
    if(operation === 'userNotices') respond({ ...envelope, data: { items: [], pagination: { page: 2, per_page: 5, total: 0, last_page: 1 } } })
    else respond({ ...envelope, data: { plan: null, reset_day: null, upload_bytes: '0', download_bytes: '0', traffic_limit_bytes: '0', device_limit: null,
      speed_limit_mbps: null, expired_at: null, next_reset_at: null } })
    await expect(pending).resolves.toMatchObject(operation === 'userNotices' ? { data: [], total: 0 } : { plan: null, u: 0, d: 0 })
    expect(native).not.toHaveBeenCalled()
    for (const status of [401,403,409,502]) {
      const rejected=upstreamRequest(config,native,operation,{ auth: token })
      const assertion=expect(rejected).rejects.toMatchObject({ status })
      reply.statusCode=status; respond({ error: { code: 'FIXTURE_DENIED', message: 'Denied' }, request_id: envelope.request_id })
      await assertion
    }
    expect(native).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it.each([{ current: 0 },{ pageSize: 101 },{ status: 0 }])('rejects invalid notice query %j before transport', async patch => {
    const native=vi.fn()
    await expect(upstreamRequest(config,native,'userNotices',{ auth: token,...patch })).rejects.toMatchObject({ status:400 })
    expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled()
  })
  it('dispatches lightweight status and rejects identity mismatch without fallback', async () => {
    const native=vi.fn()
    const pending=upstreamRequest(config,native,'userOrderStatus',{ auth:token,tradeNo:'T-1' })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/user.order-status.get')
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual({ trade_no:'T-1' })
    respond({...envelope,data:{trade_no:'T-1',status:4}});await expect(pending).resolves.toBe(4)
    for(const data of [{trade_no:'T-2',status:4},{trade_no:'T-1',status:5},{trade_no:'T-1',status:0,callback_no:'secret'}]) {
      const pending=upstreamRequest(config,native,'userOrderStatus',{ auth:token,tradeNo:'T-1' })
      const assertion=expect(pending).rejects.toMatchObject({status:502});respond({...envelope,data});await assertion
    }
    await expect(upstreamRequest(config,native,'userOrderStatus',{ auth:token,tradeNo:'T-1\n' })).rejects.toMatchObject({status:400})
    expect(native).not.toHaveBeenCalled();expect(vi.getTimerCount()).toBe(0)
  })
  it('binds write inputs and original business key and accepts only exact HTTP201 write receipt', async () => {
    const native=vi.fn(), writing={...config,orderWriteEnabled:true}
    const options={auth:token,body:{plan_id:7,period:'month_price'},headers:{'Idempotency-Key':'original-key','X-Actor-Id':'admin'}}
    const pending=upstreamRequest(writing,native,'userOrderCreate',options)
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/user.order.create')
    const request=JSON.parse(req.end.mock.calls[0]![0].toString())
    expect(request).toMatchObject({contract_version:'1',input:{plan_id:'7',period:'monthly',idempotency_key:'original-key'}})
    expect(mock.request.mock.calls[0]![0].headers['X-Actor-Id']).toBeUndefined()
    reply.statusCode=201;respond({data:{trade_no:'T-new'},request_id:envelope.request_id,meta:{current_only:true,slice:'internal-order-write/1'}})
    await expect(pending).resolves.toEqual({trade_no:'T-new'})
    for (const [status,meta,data] of [[200,{current_only:true,slice:'internal-order-write/1'},{trade_no:'T-new'}],
      [201,envelope.meta,{trade_no:'T-new'}],[201,{current_only:true,slice:'internal-order-write/1'},{trade_no:'T-new',callback_no:'private'}]] as const) {
      const pending=upstreamRequest(writing,native,'userOrderCreate',options)
      const assertion=expect(pending).rejects.toMatchObject({status:502});reply.statusCode=status
      respond({data,request_id:envelope.request_id,meta});await assertion
    }
    expect(native).not.toHaveBeenCalled();expect(vi.getTimerCount()).toBe(0)
  })
  it('keeps business conflict codes and disables invalid writes without transport or fallback', async () => {
    const native=vi.fn(),writing={...config,orderWriteEnabled:true}
    const options={auth:token,body:{plan_id:7,period:'month_price'},headers:{'Idempotency-Key':'original-key'}}
    for(const code of ['IDEMPOTENCY_CONFLICT','IDEMPOTENCY_IN_PROGRESS']) {
      const pending=upstreamRequest(writing,native,'userOrderCreate',options)
      const assertion=expect(pending).rejects.toMatchObject({status:409,code});reply.statusCode=409
      respond({error:{code,message:'private Core message'},request_id:envelope.request_id});await assertion
    }
    const calls=mock.request.mock.calls.length
    await expect(upstreamRequest(config,native,'userOrderCreate',options)).rejects.toMatchObject({status:405})
    for(const patch of [{headers:{}},{headers:{'Idempotency-Key':'key\n'}},{body:{plan_id:2147483648,period:'month_price'}},
      {body:{plan_id:7,period:'monthly'}},{body:{plan_id:7,period:'month_price',owner_id:1}}]) {
      await expect(upstreamRequest(writing,native,'userOrderCreate',{...options,...patch})).rejects.toMatchObject({status:400})
    }
    expect(mock.request.mock.calls.length).toBe(calls);expect(native).not.toHaveBeenCalled();expect(vi.getTimerCount()).toBe(0)
  })
  it('keeps default Native and other operations unchanged without Internal network calls', async () => {
    const fetcher = vi.fn(async () => Response.json(nativeEnvelope(nativeProfile())))
    await upstreamRequest({ ...config, internalUser: undefined }, fetcher, 'userProfile', { auth: token })
    expect(fetcher).toHaveBeenCalled(); expect(mock.request).not.toHaveBeenCalled()
  })
  it('posts the bounded list input and validates the paged result without Native calls', async () => {
    const native = vi.fn()
    const pending = upstreamRequest(config, native, 'userOrders', { auth: token, current: 2, pageSize: 2, status: 4, paginated: true })
    expect(mock.request.mock.calls[0]![0].path).toBe('/txapi/internal/v1/operations/user.order.list')
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual({ page: 2, per_page: 2, status: 4 })
    respond({ ...envelope, data: { items: [], pagination: { page: 2, per_page: 2, total: 0, last_page: 1 } } })
    await expect(pending).resolves.toEqual({ items: [], current: 2, pageSize: 2, total: 0, lastPage: 1 })
    expect(native).not.toHaveBeenCalled(); expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it.each([{ current: 0 }, { current: 10000 }, { current: 1.1 }, { pageSize: 101 }, { pageSize: 0 }, { status: 5 }, { status: NaN }])
    ('refuses invalid list input %j before network', async patch => {
      const native = vi.fn()
      await expect(upstreamRequest(config, native, 'userOrders', { auth: token, ...patch })).rejects.toMatchObject({ status: 400 })
      expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled()
    })
  it('fails closed for TLS error without plaintext fallback or secret reflection', async () => {
    const native = vi.fn(); const pending = upstreamRequest(config, native, 'userProfile', { auth: token })
    const assertion = expect(pending).rejects.toMatchObject({ code: 'UPSTREAM_UNAVAILABLE', status: 502, message: 'Internal connection failed' })
    req.emit('error', new Error('secret private key '+token)); await assertion
    expect(native).not.toHaveBeenCalled(); expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it('total walltime cannot be refreshed by slow drip or missing end', async () => {
    const pending = upstreamRequest(config, vi.fn(), 'userProfile', { auth: token })
    const assertion = expect(pending).rejects.toMatchObject({ status: 504 })
    callback(reply)
    for (let i=0;i<4;i++) { reply.emit('data', Buffer.from(' ')); await vi.advanceTimersByTimeAsync(1000) }
    await vi.advanceTimersByTimeAsync(1000); await assertion
    expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it.each(['redirect','oversize','duplicate','bad-meta','bad-utf8','upstream-abort'])('rejects %s with no fallback', async kind => {
    const native = vi.fn(); const pending = upstreamRequest(config, native, 'userProfile', { auth: token })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    if (kind === 'redirect') { reply.statusCode=302; callback(reply) }
    if (kind === 'oversize') { callback(reply); reply.emit('data', Buffer.alloc(65537)) }
    if (kind === 'duplicate') { callback(reply); reply.emit('data', Buffer.from('{"data":{},"data":{}}')); reply.emit('end') }
    if (kind === 'bad-meta') respond({ ...envelope, meta: { current_only: true, slice: 'internal-readonly/1' } })
    if (kind === 'bad-utf8') { callback(reply); reply.emit('data', Buffer.from([0xff])); reply.emit('end') }
    if (kind === 'upstream-abort') { callback(reply); reply.complete=false; reply.emit('close') }
    await assertion; expect(native).not.toHaveBeenCalled(); expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it('abort signal cleans wall timer and closes transport', async () => {
    const signal = new AbortController(); const pending = upstreamRequest(config, vi.fn(), 'userProfile', { auth: token, signal: signal.signal })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 }); signal.abort(); await assertion
    expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
  it('missing actor and expired client certificate never open a connection', async () => {
    await expect(upstreamRequest(config, vi.fn(), 'userProfile')).rejects.toMatchObject({ status: 401 })
    const expired = { ...config, internalUser: { ...config.internalUser!, certificate: { validFrom: new Date(0).toISOString(), validTo: new Date(1).toISOString() } as X509Certificate } }
    await expect(upstreamRequest(expired, vi.fn(), 'userProfile', { auth: token })).rejects.toMatchObject({ status: 502 })
    expect(mock.request).not.toHaveBeenCalled()
  })
  it.each(['\n', '\r\n', '\r', '\u2028', '\u2029'])('rejects trailing line break %j in Bearer and trade number before any network request', async ending => {
    const native = vi.fn()
    await expect(upstreamRequest(config, native, 'userProfile', { auth: token+ending })).rejects.toMatchObject({ status: 401 })
    await expect(upstreamRequest(config, native, 'userOrderDetail', { auth: token, tradeNo: 'T-1'+ending })).rejects.toMatchObject({ status: 400 })
    expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
})
