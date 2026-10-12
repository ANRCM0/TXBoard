import { EventEmitter } from 'node:events'
import { generateKeyPairSync, type X509Certificate } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig, type GatewayConfig } from '../src/config/env.js'
import { upstreamRequest } from '../src/services/upstream.js'
import { GatewayFailure } from '../src/services/failures.js'
import { CryptoService } from '../src/services/crypto.js'
import { MemoryCaptchaChallenge } from '../src/services/captcha-challenge.js'
import type { LoginProtection } from '../src/middleware/login-protection.js'
import { encryptLoginPayload } from '../../../packages/theme-sdk/src/crypto.js'
import { nativeEnvelope } from './native-fixtures.js'

const mock = vi.hoisted(() => ({ request: vi.fn(), loadTransport: vi.fn() }))
vi.mock('node:https', () => ({ default: { request: mock.request } }))
// The new exact flag is tested here; the existing PKI loader and actual mTLS
// boundary have separate integration gates, and are not simulated as proof.
vi.mock('../src/config/internal-user.js', () => ({ loadInternalUserConfig: mock.loadTransport }))

const requestId = '12345678-1234-4234-8234-123456789abc'
const token = 'Bearer fixture_native_session_0123456789'
const credentials = { email: 'login-owner@example.test', password: 'fixture-login-password' }
const envelope = (data: unknown = { auth_data: token }) => ({ data, request_id: requestId,
  meta: { current_only: true, slice: 'internal-isolated-login/1' } })
const errorEnvelope = (code: string) => ({ error: { code, message: 'private-upstream-password-and-key' }, request_id: requestId })
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
    internalUser, internalLoginEnabled: true, internalPublicReadEnabled: false }
  req = Object.assign(new EventEmitter(), { destroy: vi.fn(), end: vi.fn() })
  reply = Object.assign(new EventEmitter(), { destroy: vi.fn(), statusCode: 200,
    headers: { 'content-type': 'application/json' }, complete: true })
  mock.request.mockImplementation((_options, cb) => { callback = cb; return req })
})

afterEach(() => { vi.clearAllTimers(); vi.useRealTimers() })
function respond(value: unknown) { callback(reply); reply.emit('data', Buffer.from(JSON.stringify(value))); reply.emit('end') }
function respondRaw(value: Buffer | string) { callback(reply); reply.emit('data', Buffer.isBuffer(value) ? value : Buffer.from(value)); reply.emit('end') }
function autoRespond(value: unknown, status = 200, events?: string[]) {
  mock.request.mockImplementation((_options, cb) => {
    callback = cb; reply.statusCode = status; events?.push('tls')
    queueMicrotask(() => respond(value)); return req
  })
}
function protection(): LoginProtection & { admit: ReturnType<typeof vi.fn>; recordFailure: ReturnType<typeof vi.fn>; recordSuccess: ReturnType<typeof vi.fn> } {
  return { admit: vi.fn(async () => ({ verdict: 'allow' as const, retryAfterMs: 0 })),
    recordFailure: vi.fn(async () => ({ verdict: 'allow' as const, retryAfterMs: 0 })), recordSuccess: vi.fn(async () => {}) }
}

describe('single isolated Internal login consumer', () => {
  it('submits only checked credential fields to auth.login v1 with no visitor identity or IP headers', async () => {
    const native = vi.fn()
    const input = { ...credentials, password: '  fixture-password-spaces  ', turnstile_token: 'captcha-secret',
      recaptcha_v3_token: '', recaptcha_data: 'captcha-data', email_code: '123456' }
    const pending = upstreamRequest(config, native, 'login', { body: input, headers: {
      Authorization: 'Bearer visitor-admin', 'X-Forwarded-For': '203.0.113.9', Cookie: 'browser-session=secret', 'X-Actor-Id': '1' } })
    const options = mock.request.mock.calls[0]![0]
    expect(options).toMatchObject({ hostname: '127.0.0.1', port: '19443', servername: 'internal-core.local', method: 'POST',
      path: '/txapi/internal/v1/operations/auth.login', minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', rejectUnauthorized: true, agent: false })
    expect(Object.keys(options.headers).sort()).toEqual(['accept', 'content-length', 'content-type'])
    const raw = req.end.mock.calls[0]![0] as Buffer
    expect(JSON.parse(raw.toString())).toMatchObject({ contract_version: '1', input, deadline_at: Math.floor(Date.now() / 1000) + 5 })
    expect(Object.keys(JSON.parse(raw.toString())).sort()).toEqual(['contract_version', 'deadline_at', 'input', 'request_id'])
    expect(options.headers['content-length']).toBe(raw.length)
    respond(envelope()); await expect(pending).resolves.toEqual({ auth_data: token })
    expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1)
    expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['', 'Bearer real-user', 'Bearer real-admin', 'Bearer node-token'])('refuses an explicit login actor %j before I/O', async auth => {
    const native = vi.fn()
    await expect(upstreamRequest(config, native, 'login', { auth, body: credentials })).rejects.toMatchObject({ status: 400 })
    expect(native).not.toHaveBeenCalled(); expect(mock.request).not.toHaveBeenCalled()
  })

  it.each([undefined, false])('keeps Native login when its independent flag is %s, including public-read-only opt-in', async enabled => {
    const native = vi.fn(async (_url: unknown, _options?: RequestInit) => Response.json(nativeEnvelope({ auth_data: token })))
    await expect(upstreamRequest({ ...config, internalLoginEnabled: enabled, internalPublicReadEnabled: true }, native,
      'login', { body: credentials, headers: { Authorization: 'Bearer visitor', 'X-Forwarded-For': '203.0.113.9' } }))
      .resolves.toEqual({ auth_data: token })
    expect(native).toHaveBeenCalledTimes(1); expect(mock.request).not.toHaveBeenCalled()
    expect(String(native.mock.calls[0]![0])).toBe('https://core.test/txapi/auth/login')
    const options = native.mock.calls[0]![1] as RequestInit
    expect(options.method).toBe('POST'); expect(JSON.parse(options.body as string)).toEqual(credentials)
    expect(new Headers(options.headers).has('authorization')).toBe(false)
    expect(new Headers(options.headers).has('x-forwarded-for')).toBe(false)
  })

  it('requires exact local login opt-in and separately configured trusted transport', () => {
    const base = { TXBOARD_UPSTREAM_URL: 'https://core.test', APP_ENV: 'testing' }
    for (const disabled of [undefined, '', 'false']) {
      expect(loadConfig({ ...base, GATEWAY_INTERNAL_LOGIN_ENABLED: disabled }).internalLoginEnabled).toBe(false)
    }
    expect(() => loadConfig({ ...base, GATEWAY_INTERNAL_LOGIN_ENABLED: 'true' })).toThrow('login configuration')
    mock.loadTransport.mockReturnValue(config.internalUser)
    for (const invalid of ['1', 'TRUE', 'true ', ' false', 'yes']) {
      expect(() => loadConfig({ ...base, GATEWAY_INTERNAL_LOGIN_ENABLED: invalid })).toThrow('login configuration')
    }
    expect(() => loadConfig({ ...base, APP_ENV: 'production', GATEWAY_INTERNAL_LOGIN_ENABLED: 'true' })).toThrow('login configuration')
    const enabled = loadConfig({ ...base, GATEWAY_INTERNAL_LOGIN_ENABLED: 'true' })
    expect(enabled.internalLoginEnabled).toBe(true); expect(enabled.internalPublicReadEnabled).toBe(false)
    const publicOnly = loadConfig({ ...base, GATEWAY_INTERNAL_PUBLIC_READ_ENABLED: 'true' })
    expect(publicOnly.internalLoginEnabled).toBe(false)
    expect(() => createGatewayApp(config, vi.fn(), undefined, { accountWorkflows: true })).toThrow('cannot enable account workflows')
  })

  it.each([
    {}, { ...credentials, email: 'invalid' }, { ...credentials, password: 'short' }, { ...credentials, password: null },
    { ...credentials, password: 'x'.repeat(1025) }, { ...credentials, email: 'x'.repeat(255) + '@example.test' },
    { ...credentials, turnstile_token: 'x'.repeat(4097) }, { ...credentials, recaptcha_data: null },
    { ...credentials, email_code: 'x'.repeat(129) }, { ...credentials, actor_id: '1' },
    { ...credentials, remote_ip: '203.0.113.9' }, { ...credentials, idempotency_key: 'not-a-login-receipt' },
  ])('rejects invalid or widened login input before transport %j', async body => {
    const native = vi.fn()
    await expect(upstreamRequest(config, native, 'login', { body })).rejects.toMatchObject({ code: 'VALIDATION_ERROR', status: 400 })
    expect(native).not.toHaveBeenCalled(); expect(mock.request).not.toHaveBeenCalled()
  })

  it('bounds UTF-8 request bytes independently of permitted field lengths', async () => {
    const native = vi.fn()
    const body = { ...credentials, turnstile_token: '中'.repeat(4096), recaptcha_v3_token: 'x'.repeat(4096), recaptcha_data: 'x'.repeat(4096) }
    await expect(upstreamRequest(config, native, 'login', { body })).rejects.toMatchObject({ status: 400, message: 'Internal request too large' })
    expect(native).not.toHaveBeenCalled(); expect(mock.request).not.toHaveBeenCalled()
  })
})

describe('credential response is a strict secret delivery', () => {
  it.each([
    '', 'Bearer ', 'Bearer short', 'bearer fixture_native_token', 'Bearer one two', 'Bearer token\n',
    'Bearer token\r', 'Bearer café_token', 'Bearer <html-token>', 'Bearer ' + 'x'.repeat(4090), null, 42,
  ])('refuses malformed native token %j without accepting a Native fallback', async auth_data => {
    const native = vi.fn(); const pending = upstreamRequest(config, native, 'login', { body: credentials })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    respond(envelope({ auth_data })); await assertion
    expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1); expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['extra-token', 'admin-path', 'admin-role', 'actor', 'missing-meta', 'public-meta', 'protected-meta', 'false-current',
    'extra-meta', 'status-201', 'duplicate-envelope', 'duplicate-secret', 'invalid-utf8', 'redirect', 'oversize', 'header-oversize'])(
    'refuses %s in the selected login response contract', async kind => {
      const native = vi.fn(); const pending = upstreamRequest(config, native, 'login', { body: credentials })
      const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
      if (kind === 'extra-token') respond(envelope({ auth_data: token, token: 'private-subscription-secret' }))
      if (kind === 'admin-path') respond(envelope({ auth_data: token, secure_path: 'private-admin-path' }))
      if (kind === 'admin-role') respond(envelope({ auth_data: token, is_admin: true }))
      if (kind === 'actor') respond(envelope({ auth_data: token, actor_id: '1' }))
      if (kind === 'missing-meta') respond({ data: { auth_data: token }, request_id: requestId })
      if (kind === 'public-meta') respond({ ...envelope(), meta: { current_only: true, slice: 'internal-public-readonly/1' } })
      if (kind === 'protected-meta') respond({ ...envelope(), meta: { current_only: true, slice: 'internal-readonly/2' } })
      if (kind === 'false-current') respond({ ...envelope(), meta: { current_only: false, slice: 'internal-isolated-login/1' } })
      if (kind === 'extra-meta') respond({ ...envelope(), meta: { ...envelope().meta, actor_id: '1' } })
      if (kind === 'status-201') { reply.statusCode = 201; respond(envelope()) }
      if (kind === 'duplicate-envelope') respondRaw(JSON.stringify(envelope()).replace('"data":', '"\\u0064ata":{},"data":'))
      if (kind === 'duplicate-secret') respondRaw(JSON.stringify(envelope()).replace('"auth_data":', '"\\u0061uth_data":"Bearer fake_secret_token","auth_data":'))
      if (kind === 'invalid-utf8') respondRaw(Buffer.from([0xff]))
      if (kind === 'redirect') { reply.statusCode = 302; callback(reply) }
      if (kind === 'oversize') { callback(reply); reply.emit('data', Buffer.alloc(65537)) }
      if (kind === 'header-oversize') { reply.headers['content-length'] = '65537'; callback(reply) }
      await assertion; expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1)
      expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
    })

  it.each([[401, 'INVALID_CREDENTIALS'], [429, 'RATE_LIMITED'], [400, 'CAPTCHA_INVALID'], [422, 'VALIDATION_FAILED'],
    [403, 'FORBIDDEN'], [503, 'DEPENDENCY_UNAVAILABLE'], [504, 'DEADLINE_EXCEEDED']] as const)(
    'keeps native rejection HTTP%s %s while hiding upstream secret messages', async (status, code) => {
      const native = vi.fn(); const pending = upstreamRequest(config, native, 'login', { body: credentials })
      const assertion = expect(pending).rejects.toMatchObject({ status, code: 'UPSTREAM_ERROR',
        message: status === 401 ? 'Authentication failed' : status === 403 ? 'Access denied' : 'Internal request rejected' })
      reply.statusCode = status; respond(errorEnvelope(code)); await assertion
      expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1)
    })

  it.each([[401, 'SERVICE_UNAUTHORIZED'], [401, 'ACTOR_UNAUTHORIZED'], [429, 'SERVICE_RATE_LIMITED'], [429, 'UNKNOWN_BUDGET']] as const)(
    'maps service refusal HTTP%s %s to unavailable rather than credential failure', async (status, code) => {
      const native = vi.fn(); const pending = upstreamRequest(config, native, 'login', { body: credentials })
      const assertion = expect(pending).rejects.toMatchObject({ status: 503, code: 'UPSTREAM_UNAVAILABLE', message: 'Internal login unavailable' })
      reply.statusCode = status; respond(errorEnvelope(code)); await assertion
      expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1)
    })
})

describe('unknown login outcome never triggers another issuance attempt', () => {
  it('total five-second deadline is not extended by streamed chunks or a late successful token', async () => {
    const native = vi.fn(); const pending = upstreamRequest(config, native, 'login', { body: credentials })
    const assertion = expect(pending).rejects.toMatchObject({ status: 504, message: 'Internal request timed out' })
    callback(reply)
    for (let i = 0; i < 4; i++) { reply.emit('data', Buffer.from(' ')); await vi.advanceTimersByTimeAsync(1000) }
    await vi.advanceTimersByTimeAsync(1000); await assertion
    reply.emit('data', Buffer.from(JSON.stringify(envelope()))); reply.emit('end')
    expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled()
    expect(req.destroy).toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['tls-error', 'caller-abort', 'body-abort'])('cleans up %s without retry or Native fallback', async kind => {
    const native = vi.fn(); const controller = new AbortController()
    const pending = upstreamRequest(config, native, 'login', { body: credentials, signal: controller.signal })
    const assertion = expect(pending).rejects.toMatchObject({ status: 502 })
    if (kind === 'tls-error') req.emit('error', new Error('private certificate/key material'))
    if (kind === 'caller-abort') controller.abort()
    if (kind === 'body-abort') { callback(reply); reply.complete = false; reply.emit('close') }
    await assertion
    expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled(); expect(req.destroy).toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('refuses expired service identity before sending credentials', async () => {
    const native = vi.fn()
    const expired = { ...config, internalUser: { ...config.internalUser!, certificate: {
      validFrom: new Date(0).toISOString(), validTo: new Date(1).toISOString() } as X509Certificate } }
    await expect(upstreamRequest(expired, native, 'login', { body: credentials })).rejects.toMatchObject({ status: 502 })
    expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled()
  })
})

describe('existing actual Gateway login workflows consume the selected Internal operation', () => {
  it('preserves limiter, admission, one-shot challenge and success ordering with a real route consumer', async () => {
    const events: string[] = [], native = vi.fn(), guard = protection()
    const limiter = { check: vi.fn(async () => { events.push('limiter') }) }
    guard.admit.mockImplementation(async () => { events.push('admit'); return { verdict: 'allow', retryAfterMs: 0 } })
    guard.recordSuccess.mockImplementation(async () => { events.push('success') })
    const challenge = new MemoryCaptchaChallenge()
    const id = await challenge.issueChallenge('login')
    const original = challenge.verifyChallenge.bind(challenge)
    const verify = vi.spyOn(challenge, 'verifyChallenge').mockImplementation(async (id, value) => {
      events.push('challenge'); return original(id, value)
    })
    autoRespond(envelope(), 200, events)
    const app = createGatewayApp(config, native, undefined, { limiter, loginProtection: guard, captchaChallenge: challenge })
    const send = () => app.request('/gateway/v1/auth/login', { method: 'POST', headers: {
      'Content-Type': 'application/json', 'X-Captcha-Challenge': id }, body: JSON.stringify({ ...credentials, turnstile_token: id }) })
    const response = await send()
    expect(response.status).toBe(200); expect((await response.json()).data).toEqual({ auth_data: token })
    expect(response.headers.get('cache-control')).toContain('no-store')
    expect(events).toEqual(['limiter', 'admit', 'challenge', 'tls', 'success'])
    expect(guard.recordFailure).not.toHaveBeenCalled(); expect(verify).toHaveBeenCalledTimes(1)
    const replay = await send(); expect(replay.status).toBe(409)
    expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled(); expect(verify).toHaveBeenCalledTimes(2)
  })

  it.each([[401, 'INVALID_CREDENTIALS', true], [429, 'RATE_LIMITED', true], [401, 'SERVICE_UNAUTHORIZED', false],
    [429, 'SERVICE_RATE_LIMITED', false], [400, 'CAPTCHA_INVALID', false], [503, 'DEPENDENCY_UNAVAILABLE', false]] as const)(
    'only native credential refusal advances the actual account budget: %s/%s', async (status, code, count) => {
      const native = vi.fn(), guard = protection()
      autoRespond(errorEnvelope(code), status)
      const app = createGatewayApp(config, native, undefined, { loginProtection: guard })
      const response = await app.request('/gateway/v1/auth/login', { method: 'POST',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(credentials) })
      expect(response.status).toBe(count ? status : status === 401 || status === 429 ? 503 : status)
      expect(guard.recordFailure).toHaveBeenCalledTimes(count ? 1 : 0); expect(guard.recordSuccess).not.toHaveBeenCalled()
      expect(JSON.stringify(await response.json())).not.toContain('private-upstream-password-and-key')
      expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled()
    })

  it.each(['limiter', 'protection'])('refuses unavailable %s before credentials leave the Gateway', async dependency => {
    const native = vi.fn(), guard = protection()
    const limiter = { check: vi.fn(async () => { if (dependency === 'limiter') throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Safety store unavailable') }) }
    if (dependency === 'protection') guard.admit.mockRejectedValue(new Error('private Redis DSN'))
    const app = createGatewayApp(config, native, undefined, { limiter, loginProtection: guard })
    const response = await app.request('/gateway/v1/auth/login', { method: 'POST',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(credentials) })
    expect(response.status).toBe(503); expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled()
    expect(guard.recordFailure).not.toHaveBeenCalled(); expect(guard.recordSuccess).not.toHaveBeenCalled()
  })

  it('real HPKE secure login uses the same admission and Internal operation and rejects replay before I/O', async () => {
    vi.useRealTimers()
    const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
    const seen = new Set<string>()
    const replay = { reserve: async (kid: string, nonce: string) => {
      const id = kid + ':' + nonce; if (seen.has(id)) return false; seen.add(id); return true
    } }
    const crypto = await CryptoService.create(privateKey.export({ format: 'jwk' }), replay)
    const native = vi.fn(), guard = protection()
    autoRespond(envelope())
    const app = createGatewayApp(config, native, crypto, { loginProtection: guard })
    const sealed = await encryptLoginPayload(crypto.publicKey(), credentials)
    const send = () => app.request('/gateway/v1/secure/auth/login', { method: 'POST',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sealed) })
    const response = await send()
    expect(response.status).toBe(200); expect((await response.json()).data).toEqual({ auth_data: token })
    expect(JSON.parse(req.end.mock.calls[0]![0].toString()).input).toEqual(credentials)
    expect(guard.admit).toHaveBeenCalledTimes(1); expect(guard.recordSuccess).toHaveBeenCalledTimes(1)
    expect((await send()).status).toBe(409)
    expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled()
  })
})
