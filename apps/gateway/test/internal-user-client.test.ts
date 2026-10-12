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
  it('keeps default Native and other operations unchanged without Internal network calls', async () => {
    const fetcher = vi.fn(async () => Response.json(nativeEnvelope(nativeProfile())))
    await upstreamRequest({ ...config, internalUser: undefined }, fetcher, 'userProfile', { auth: token })
    expect(fetcher).toHaveBeenCalled(); expect(mock.request).not.toHaveBeenCalled()
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
