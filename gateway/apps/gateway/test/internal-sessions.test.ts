import { EventEmitter } from 'node:events'
import { generateKeyPairSync, type X509Certificate } from 'node:crypto'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig, type GatewayConfig } from '../src/config/env.js'
import { activeRouteDefinitions, routeDefinitions, validatePolicies } from '../src/config/policies.js'
import { internalSessionListSuccess } from '../src/adapters/internal-user-v2.js'
import { CryptoService } from '../src/services/crypto.js'
import { GatewayFailure } from '../src/services/upstream.js'
import { internalUserRequest } from '../src/services/internal-user-client.js'
import { invalidateCache } from '../src/middleware/cache.js'
import { createTXBoardClient } from '../../../packages/theme-sdk/src/index.js'
import { encryptForOperation } from '../../../packages/theme-sdk/src/crypto.js'

const mock = vi.hoisted(() => ({ request: vi.fn(), transport: vi.fn() }))
vi.mock('node:https', () => ({ default: { request: mock.request } }))
vi.mock('../src/config/internal-user.js', () => ({ loadInternalUserConfig: mock.transport }))
const bearer = 'Bearer FixtureCaseSensitive|abc123'
const meta = { current_only: true, slice: 'internal-isolated-sessions/1' }
const item = { id: '9007199254740993', name: 'synthetic', abilities: ['*'],
  last_used_at: null, created_at: '2026-10-13T00:00:00+00:00', updated_at: null, expires_at: null }
const success = (data: unknown) => ({ data, request_id: '12345678-1234-4234-8234-123456789abc', meta })
let config: GatewayConfig, reply: string, status: number
let sent: Buffer[]
beforeEach(() => {
  invalidateCache()
  mock.request.mockReset(); mock.transport.mockReset()
  config = { ...loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test' }), internalSessionsEnabled: true,
    internalUser: { origin: new URL('https://127.0.0.1:19443'), servername: 'internal-core.local',
      ca: Buffer.from('ca'), crl: Buffer.from('crl'), cert: Buffer.from('fixture-cert'), key: Buffer.from('fixture-key'),
      certificate: { validFrom: new Date(Date.now() - 60000).toISOString(),
        validTo: new Date(Date.now() + 3600000).toISOString() } as X509Certificate } }
  sent = []; status = 200; reply = JSON.stringify(success({ items: [item] }))
  mock.request.mockImplementation((_options, callback) => Object.assign(new EventEmitter(), { destroy: vi.fn(), end: (body: Buffer) => {
    sent.push(body)
    queueMicrotask(() => {
      const response = Object.assign(new EventEmitter(), { statusCode: status,
        headers: { 'content-type': 'application/json' }, complete: true, destroy: vi.fn() })
      callback(response); response.emit('data', Buffer.from(reply)); response.emit('end')
    })
  } }))
})
async function realCrypto() {
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' }); const seen = new Set<string>()
  return CryptoService.create(privateKey.export({ format: 'jwk' }), { reserve: async (kid, nonce) => {
    const key = kid + ':' + nonce; if (seen.has(key)) return false; seen.add(key); return true
  } }, true)
}
const limiter = () => ({ check: vi.fn(async () => {}) })

describe('isolated current User sessions consumer', () => {
  it('requires exact local flag and valid Internal configuration', () => {
    const base = { TXBOARD_UPSTREAM_URL: 'https://core.test', APP_ENV: 'testing' }
    for (const disabled of [undefined, '', 'false']) expect(loadConfig({ ...base, GATEWAY_INTERNAL_SESSIONS_ENABLED: disabled }).internalSessionsEnabled).toBe(false)
    expect(() => loadConfig({ ...base, GATEWAY_INTERNAL_SESSIONS_ENABLED: 'true' })).toThrow('sessions configuration')
    mock.transport.mockReturnValue(config.internalUser)
    for (const bad of ['1', 'TRUE', 'true ', 'yes']) expect(() => loadConfig({ ...base, GATEWAY_INTERNAL_SESSIONS_ENABLED: bad })).toThrow('sessions configuration')
    expect(() => loadConfig({ ...base, APP_ENV: 'production', GATEWAY_INTERNAL_SESSIONS_ENABLED: 'true' })).toThrow('sessions configuration')
    expect(loadConfig({ ...base, GATEWAY_INTERNAL_SESSIONS_ENABLED: 'true' })).toMatchObject({ internalSessionsEnabled: true, internalRegisterEnabled: false })
  })
  it('requires HPKE, limiter and Internal at boot with legacy workflows off', async () => {
    const crypto = await realCrypto(), limit = limiter()
    for (const args of [[undefined, {}], [crypto, {}], [undefined, { limiter: limit }], [crypto, { limiter: limit, accountWorkflows: true }]] as const) {
      expect(() => createGatewayApp(config, vi.fn(), args[0], args[1])).toThrow('Isolated sessions')
    }
    expect(() => createGatewayApp({ ...config, internalUser: undefined }, vi.fn(), crypto, { limiter: limit })).toThrow('Isolated sessions')
    expect(() => createGatewayApp(config, vi.fn(), crypto, { limiter: limit })).not.toThrow()
  })
  it('keeps all three routes absent for every partial configuration', () => {
    const environment = { isolatedSessions: true, hpke: true, replayStore: true, redis: true }
    const session = (name: string) => ['isolatedSessionRead', 'secureSessionMutation'].includes(name)
    expect(activeRouteDefinitions(environment).filter(r => session(r.policy.name))).toHaveLength(3)
    expect(() => validatePolicies(activeRouteDefinitions(environment), environment)).not.toThrow()
    for (const field of ['hpke', 'redis', 'replayStore', 'isolatedSessions'] as const) {
      expect(activeRouteDefinitions({ ...environment, [field]: false }).some(r => session(r.policy.name))).toBe(false)
    }
    expect(activeRouteDefinitions({ ...environment, accountWorkflows: true }).some(r => session(r.policy.name))).toBe(false)
    expect(() => validatePolicies([routeDefinitions.find(r => r.policy.name === 'secureSessionMutation')!], {})).toThrow()
  })
  it('SDK list forwards actual bearer through a fixed v1 mTLS operation and never Native', async () => {
    const crypto = await realCrypto(), limit = limiter(), native = vi.fn(), app = createGatewayApp(config, native, crypto, { limiter: limit })
    const sdk = createTXBoardClient({ getToken: () => bearer, fetchImpl: (url, init) => app.request(String(url), init), maxRetries: 0 })
    expect(await sdk.auth.sessions.list()).toEqual({ items: [item] })
    expect(mock.request.mock.calls[0]![0]).toMatchObject({ method: 'POST', path: '/txapi/internal/v1/operations/user.session.list',
      minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', headers: { authorization: bearer } })
    expect(JSON.parse(sent[0]!.toString())).toMatchObject({ contract_version: '1', input: {} })
    expect(limit.check).toHaveBeenCalledWith('sessions', bearer, '0.0.0.0'); expect(native).not.toHaveBeenCalled()
  })
  it.each(['session-revoke', 'session-logout'] as const)('SDK %s sends protected encrypted request once with no token state changes', async operation => {
    reply = JSON.stringify(success({ ok: true })); const crypto = await realCrypto(), native = vi.fn(), limit = limiter()
    const app = createGatewayApp(config, native, crypto, { limiter: limit }); const token = vi.fn(() => bearer); const http: Request[] = []
    const sdk = createTXBoardClient({ getToken: token, fetchImpl: (url, init) => { http.push(new Request('https://gateway.test' + url, init)); return app.request(String(url), init) } })
    expect(await (operation === 'session-revoke' ? sdk.auth.sessions.revoke(item.id) : sdk.auth.logout())).toEqual({ ok: true })
    expect(token).toHaveBeenCalledTimes(1); expect(http).toHaveLength(2)
    expect(http[0]!.headers.has('authorization')).toBe(false); expect(http[1]!.headers.get('authorization')).toBe(bearer)
    const body = await http[1]!.text(); expect(body).not.toContain(item.id); expect(body).not.toContain(bearer)
    expect(JSON.parse(sent[0]!.toString()).input).toEqual(operation === 'session-revoke' ? { session_id: item.id } : {})
    expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled()
  })
  it('missing bearer, wrong method, plaintext write and unknown query cause zero upstream calls', async () => {
    const crypto = await realCrypto(), limit = limiter(), app = createGatewayApp(config, vi.fn(), crypto, { limiter: limit })
    expect((await app.request('/gateway/v1/user/sessions')).status).toBe(401)
    expect((await app.request('/gateway/v1/user/sessions?owner=99', { headers: { authorization: bearer } })).status).toBe(400)
    expect((await app.request('/gateway/v1/user/sessions', { method: 'POST', headers: { authorization: bearer } })).status).toBe(404)
    expect((await app.request('/gateway/v1/secure/auth/session-logout', { method: 'POST', headers: { authorization: bearer, 'content-type': 'application/json' }, body: '{}' })).status).toBe(400)
    expect((await app.request('/gateway/v1/auth/session-logout', { method: 'POST' })).status).toBe(404)
    expect(mock.request).not.toHaveBeenCalled(); expect(limit.check).not.toHaveBeenCalled()
  })
  it('actual encryption rejects replay, other operation AAD and body owner before upstream', async () => {
    reply = JSON.stringify(success({ ok: true })); const crypto = await realCrypto(), limit = limiter(), app = createGatewayApp(config, vi.fn(), crypto, { limiter: limit })
    const sealed = await encryptForOperation(crypto.publicKey(), {}, 'session-logout')
    const post = (path: string, body: unknown) => app.request('/gateway/v1/secure/auth/' + path, { method: 'POST',
      headers: { authorization: bearer, 'content-type': 'application/json' }, body: JSON.stringify(body) })
    expect((await post('session-logout', sealed)).status).toBe(200); expect((await post('session-logout', sealed)).status).toBe(409)
    expect((await post('session-revoke', await encryptForOperation(crypto.publicKey(), {}, 'session-logout'))).status).toBe(400)
    expect((await post('session-logout', await encryptForOperation(crypto.publicKey(), { owner: '99' }, 'session-logout'))).status).toBe(400)
    expect(limit.check).toHaveBeenCalledTimes(1); expect(mock.request).toHaveBeenCalledTimes(1)
  })
  it.each([undefined, 'Bearer bad value', '', 'visitor'])('direct Internal client refuses invalid bearer %s', async auth => {
    await expect(internalUserRequest(config, 'userSessionList', { auth })).rejects.toMatchObject({ status: 401 }); expect(mock.request).not.toHaveBeenCalled()
  })
  it('direct Internal client refuses missing transport, disabled flag and invalid ID rather than fallback', async () => {
    await expect(internalUserRequest({ ...config, internalUser: undefined }, 'userSessionList', { auth: bearer })).rejects.toMatchObject({ status: 502 })
    await expect(internalUserRequest({ ...config, internalSessionsEnabled: false }, 'userSessionList', { auth: bearer })).rejects.toMatchObject({ status: 404 })
    for (const id of ['0', '01', '-1', '1\n', '1e2', '1000000000000000000', 1]) {
      await expect(internalUserRequest(config, 'userSessionRevoke', { auth: bearer, body: { session_id: id } })).rejects.toMatchObject({ status: 400 })
    }
    expect(mock.request).not.toHaveBeenCalled()
  })
  it.each([null, [], { owner_id: '1' }])('direct Internal client rejects non-empty or non-object logout %#', async body => {
    await expect(internalUserRequest(config, 'userSessionLogout', { auth: bearer, body })).rejects.toMatchObject({ status: 400 })
    expect(mock.request).not.toHaveBeenCalled()
  })
  it.each([401, 403, 404, 409, 422, 429, 500])('preserves Core %s without repeating transport or exposing its message', async code => {
    status = code; reply = JSON.stringify({ error: { code: 'CORE_ERROR', message: 'private provider secret' }, request_id: success({}).request_id })
    await expect(internalUserRequest(config, 'userSessionRevoke', { auth: bearer, body: { session_id: '9' } })).rejects.toMatchObject({ status: code })
    try { await internalUserRequest(config, 'userSessionList', { auth: bearer }) } catch (e) { expect(String(e)).not.toContain('private provider secret') }
  })
  it.each([
    { items: [{ ...item, token: 'private' }] }, { items: [{ ...item, abilities: '*' }] },
    { items: [{ ...item, id: '01' }] }, { items: [{ ...item, id: 1 }] },
    { items: [{ ...item, created_at: 'not-a-date' }] }, { items: [item, item] },
    { items: [{ ...item, id: '1' }, item] }, { items: Array.from({ length: 101 }, (_, i) => ({ ...item, id: String(1000 - i) })) },
  ])('rejects closed owner DTO mismatch %#', async data => {
    expect(internalSessionListSuccess.safeParse(success(data)).success).toBe(false)
    reply = JSON.stringify(success(data)); await expect(internalUserRequest(config, 'userSessionList', { auth: bearer })).rejects.toMatchObject({ status: 502 })
  })
  it('rejects unknown slice, extra receipt field, duplicate JSON key and false mutation result', async () => {
    for (const invalid of [{ ...success({ items: [] }), meta: { ...meta, slice: 'internal-readonly/2' } },
      { ...success({ items: [] }), token: 'secret' }]) {
      reply = JSON.stringify(invalid); await expect(internalUserRequest(config, 'userSessionList', { auth: bearer })).rejects.toMatchObject({ status: 502 })
    }
    reply = JSON.stringify(success({ items: [] })).replace('"items":[]', '"items":[],"items":[]')
    await expect(internalUserRequest(config, 'userSessionList', { auth: bearer })).rejects.toMatchObject({ status: 502 })
    reply = JSON.stringify(success({ ok: false })); await expect(internalUserRequest(config, 'userSessionLogout', { auth: bearer })).rejects.toMatchObject({ status: 502 })
  })
  it('fails closed before Core when Redis limiter is unavailable', async () => {
    const crypto = await realCrypto(), limit = { check: vi.fn(async () => { throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Safety store unavailable') }) }
    const app = createGatewayApp(config, vi.fn(), crypto, { limiter: limit })
    expect((await app.request('/gateway/v1/user/sessions', { headers: { authorization: bearer } })).status).toBe(503)
    expect(mock.request).not.toHaveBeenCalled()
  })
})
