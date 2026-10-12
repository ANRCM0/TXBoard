import { EventEmitter } from 'node:events'
import { generateKeyPairSync, type X509Certificate } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig, type GatewayConfig } from '../src/config/env.js'
import { activeRouteDefinitions, routeDefinitions, validatePolicies } from '../src/config/policies.js'
import { CryptoService } from '../src/services/crypto.js'
import { GatewayFailure, upstreamRequest } from '../src/services/upstream.js'
import { internalUserRequest } from '../src/services/internal-user-client.js'
import { createTXBoardClient } from '../../../packages/theme-sdk/src/index.js'
import { encryptForOperation } from '../../../packages/theme-sdk/src/crypto.js'
import { nativeEnvelope } from './native-fixtures.js'

const mock = vi.hoisted(() => ({ request: vi.fn(), transport: vi.fn() }))
vi.mock('node:https', () => ({ default: { request: mock.request } }))
vi.mock('../src/config/internal-user.js', () => ({ loadInternalUserConfig: mock.transport }))

const requestId = '12345678-1234-4234-8234-123456789abc'
const input = { email: 'recovery-fixture@example.test', password: 'fixture-new-password', email_code: '123456' }
const success = () => ({ data: { ok: true }, request_id: requestId,
  meta: { current_only: true, slice: 'internal-isolated-password-reset/1' } })
let config: GatewayConfig
let sent: Buffer[]
let status: number
let rawReply: string
let request: EventEmitter & { destroy: ReturnType<typeof vi.fn> }
beforeEach(() => {
  mock.request.mockReset(); mock.transport.mockReset()
  config = { ...loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test' }), internalPasswordResetEnabled: true,
    internalUser: { origin: new URL('https://127.0.0.1:19443'), servername: 'internal-core.local',
      ca: Buffer.from('ca'), crl: Buffer.from('crl'), cert: Buffer.from('fixture-cert'), key: Buffer.from('fixture-key'),
      certificate: { validFrom: new Date(Date.now() - 60000).toISOString(),
        validTo: new Date(Date.now() + 3600000).toISOString() } as X509Certificate } }
  sent = []; status = 200; rawReply = JSON.stringify(success())
  mock.request.mockImplementation((_options, callback) => {
    request = Object.assign(new EventEmitter(), { destroy: vi.fn(), end: (bytes: Buffer) => {
      sent.push(bytes)
      queueMicrotask(() => {
        const response = Object.assign(new EventEmitter(), { statusCode: status,
          headers: { 'content-type': 'application/json' }, complete: true, destroy: vi.fn() })
        callback(response); response.emit('data', Buffer.from(rawReply)); response.emit('end')
      })
    } })
    return request
  })
})
afterEach(() => { vi.useRealTimers() })

async function realCrypto() {
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  const seen = new Set<string>()
  return CryptoService.create(privateKey.export({ format: 'jwk' }), {
    reserve: async (kid, nonce) => { const key = kid + ':' + nonce; if (seen.has(key)) return false; seen.add(key); return true },
  }, true)
}

describe('independent isolated recovery consumer', () => {
  it('requires its exact flag, local environment and already configured Internal transport', () => {
    const base = { TXBOARD_UPSTREAM_URL: 'https://core.test', APP_ENV: 'testing' }
    for (const disabled of [undefined, '', 'false']) {
      expect(loadConfig({ ...base, GATEWAY_INTERNAL_PASSWORD_RESET_ENABLED: disabled }).internalPasswordResetEnabled).toBe(false)
    }
    expect(() => loadConfig({ ...base, GATEWAY_INTERNAL_PASSWORD_RESET_ENABLED: 'true' })).toThrow('password-reset configuration')
    mock.transport.mockReturnValue(config.internalUser)
    for (const invalid of ['1', 'TRUE', 'true ', ' false', 'yes']) {
      expect(() => loadConfig({ ...base, GATEWAY_INTERNAL_PASSWORD_RESET_ENABLED: invalid })).toThrow('password-reset configuration')
    }
    expect(() => loadConfig({ ...base, APP_ENV: 'production', GATEWAY_INTERNAL_PASSWORD_RESET_ENABLED: 'true' })).toThrow('password-reset configuration')
    const enabled = loadConfig({ ...base, GATEWAY_INTERNAL_PASSWORD_RESET_ENABLED: 'true' })
    expect(enabled.internalPasswordResetEnabled).toBe(true)
    expect(enabled.internalLoginEnabled).toBe(false); expect(enabled.internalPublicReadEnabled).toBe(false)
  })

  it('requires HPKE and account limiter at boot and refuses simultaneous legacy registration mode', async () => {
    const crypto = await realCrypto(), limiter = { check: vi.fn(async () => {}) }
    expect(() => createGatewayApp(config, vi.fn())).toThrow('password recovery')
    expect(() => createGatewayApp(config, vi.fn(), crypto)).toThrow('password recovery')
    expect(() => createGatewayApp(config, vi.fn(), undefined, { limiter })).toThrow('password recovery')
    expect(() => createGatewayApp(config, vi.fn(), crypto, { limiter, accountWorkflows: true })).toThrow('password recovery')
    expect(() => createGatewayApp(config, vi.fn(), crypto, { limiter })).not.toThrow()
  })

  it('has only email policy in this mode and rejects a hand-built registration policy', () => {
    const environment = { hpke: true, replayStore: true, redis: true, isolatedPasswordReset: true }
    const active = activeRouteDefinitions(environment)
    expect(active.filter(r => ['securePasswordReset', 'secureEmailCode', 'secureRegister'].includes(r.policy.name)).map(r => r.policy.name)).toEqual(['securePasswordReset'])
    expect(() => validatePolicies(active, environment)).not.toThrow()
    const register = routeDefinitions.find(r => r.policy.name === 'secureRegister')!
    expect(() => validatePolicies([register], environment)).toThrow()
    for (const missing of ['hpke', 'replayStore', 'redis'] as const) {
      expect(activeRouteDefinitions({ ...environment, [missing]: false }).some(r => r.policy.name === 'securePasswordReset')).toBe(false)
    }
  })

  it('sends a closed v1 envelope to one fixed operation without visitor actor, IP or idempotency headers', async () => {
    const native = vi.fn(), body = { ...input, turnstile_token: 'synthetic-captcha', recaptcha_data: '' }
    await expect(upstreamRequest(config, native, 'resetPassword', { body,
      headers: { Authorization: 'Bearer visitor', 'X-Forwarded-For': '203.0.113.9', 'Idempotency-Key': 'not-a-receipt' } })).resolves.toBe(true)
    const options = mock.request.mock.calls[0]![0]
    expect(options).toMatchObject({ method: 'POST', path: '/txapi/internal/v1/operations/auth.password.reset',
      minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', rejectUnauthorized: true, agent: false })
    expect(Object.keys(options.headers).sort()).toEqual(['accept', 'content-length', 'content-type'])
    const envelope = JSON.parse(sent[0]!.toString())
    expect(Object.keys(envelope).sort()).toEqual(['contract_version', 'deadline_at', 'input', 'request_id'])
    expect(envelope).toMatchObject({ contract_version: '1', input: body })
    expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1); expect(request.destroy).toHaveBeenCalled()
  })

  it.each(['', 'Bearer user', 'Bearer admin', 'Bearer node'])('rejects explicit actor %j before transport', async auth => {
    await expect(upstreamRequest(config, vi.fn(), 'resetPassword', { auth, body: input })).rejects.toMatchObject({ status: 400 })
    expect(mock.request).not.toHaveBeenCalled()
  })

  it.each([undefined, false])('keeps Native adapter when the independent email flag is %s', async enabled => {
    const native = vi.fn(async () => Response.json(nativeEnvelope({ ok: true })))
    const current = { ...config, internalPasswordResetEnabled: enabled, internalLoginEnabled: true, internalPublicReadEnabled: true, internalEmailCodeEnabled: true, internalRegisterEnabled: true }
    await expect(upstreamRequest(current, native, 'resetPassword', { body: input })).resolves.toBe(true)
    expect(native).toHaveBeenCalledTimes(1); expect(mock.request).not.toHaveBeenCalled()
    expect(native.mock.calls[0]![0]).toBe('https://core.test/txapi/auth/password/forgot')
    await expect(internalUserRequest(current, 'resetPassword', { body: input })).rejects.toMatchObject({ status: 400 })
  })

  it.each([{}, { email: 'bad' }, { email: null }, { ...input, purpose: 'checkout' }, { ...input, code: '123456' },
    { ...input, remote_ip: '203.0.113.9' }, { ...input, template: 'custom' }, { ...input, recaptcha_data: null },
    { ...input, turnstile_token: 'x'.repeat(4097) }])('rejects widened or malformed email input before I/O %j', async body => {
    await expect(upstreamRequest(config, vi.fn(), 'resetPassword', { body })).rejects.toMatchObject({ code: 'VALIDATION_ERROR', status: 400 })
    expect(mock.request).not.toHaveBeenCalled()
  })

  it('enforces the existing total raw UTF-8 cap even with valid character lengths', async () => {
    await expect(upstreamRequest(config, vi.fn(), 'resetPassword', { body: { ...input,
      recaptcha_data: '中'.repeat(4096), turnstile_token: 'x'.repeat(4096) } })).rejects.toMatchObject({ status: 400 })
    expect(mock.request).not.toHaveBeenCalled()
  })
})

describe('closed response and unknown execution', () => {
  it('refuses missing selected Internal transport before Native I/O', async () => {
    const native = vi.fn()
    await expect(upstreamRequest({ ...config, internalUser: undefined }, native, 'resetPassword', { body: input }))
      .rejects.toMatchObject({ status: 502 })
    expect(native).not.toHaveBeenCalled(); expect(mock.request).not.toHaveBeenCalled()
  })
  it.each(['extra-code', 'extra-email', 'false-ok', 'login-meta', 'extra-meta', 'false-current', 'native-envelope', 'duplicate-ok', 'status-201'])(
    'rejects %s without a second send or Native fallback', async kind => {
      const native = vi.fn(), value: Record<string, any> = success()
      if (kind === 'extra-code') value.data.code = '123456'
      if (kind === 'extra-email') value.data.email = input.email
      if (kind === 'false-ok') value.data.ok = false
      if (kind === 'login-meta') value.meta.slice = 'internal-isolated-login/1'
      if (kind === 'extra-meta') value.meta.actor_id = '1'
      if (kind === 'false-current') value.meta.current_only = false
      if (kind === 'status-201') status = 201
      rawReply = kind === 'native-envelope' ? JSON.stringify(nativeEnvelope({ ok: true })) : JSON.stringify(value)
      if (kind === 'duplicate-ok') rawReply = rawReply.replace('"ok":true', '"\\u006fk":false,"ok":true')
      await expect(upstreamRequest(config, native, 'resetPassword', { body: input })).rejects.toMatchObject({ status: 502 })
      expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled()
    })

  it.each([[400, 'CAPTCHA_INVALID', 400], [400, 'RECIPIENT_NOT_ALLOWED', 400], [422, 'VALIDATION_FAILED', 422],
    [429, 'RATE_LIMITED', 429], [401, 'SERVICE_UNAUTHORIZED', 503], [401, 'INVALID_CREDENTIALS', 503], [429, 'SERVICE_RATE_LIMITED', 503],
    [503, 'DEPENDENCY_UNAVAILABLE', 503], [504, 'DEADLINE_EXCEEDED', 504]] as const)(
    'hides private mail diagnostics and preserves service/native refusal distinction %s/%s', async (upstreamStatus, code, exposedStatus) => {
      status = upstreamStatus; rawReply = JSON.stringify({ request_id: requestId,
        error: { code, message: 'private-email-code-smtp-password' } })
      const native = vi.fn()
      await expect(upstreamRequest(config, native, 'resetPassword', { body: input })).rejects.toMatchObject({ status: exposedStatus })
      expect(native).not.toHaveBeenCalled(); expect(mock.request).toHaveBeenCalledTimes(1)
    })

  it('times out an unknown issuance once without retry or Native fallback', async () => {
    vi.useFakeTimers()
    const native = vi.fn()
    mock.request.mockImplementation(() => Object.assign(new EventEmitter(), { end: vi.fn(), destroy: vi.fn() }))
    const pending = upstreamRequest(config, native, 'resetPassword', { body: input })
    const assertion = expect(pending).rejects.toMatchObject({ status: 504 })
    await vi.advanceTimersByTimeAsync(5000); await assertion
    expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0)
  })
})

describe('existing SDK and encrypted route', () => {
  it('real SDK encrypts recovery and consumes Internal success while registration stays absent', async () => {
    const crypto = await realCrypto(), native = vi.fn(), limiter = { check: vi.fn(async () => {}) }
    const app = createGatewayApp(config, native, crypto, { limiter })
    const http: Array<{ path: string; body?: string; authorization: string | null }> = []
    const fetchImpl: typeof fetch = async (url, options) => {
      const path = new URL(String(url)).pathname
      http.push({ path, body: options?.body as string | undefined, authorization: new Headers(options?.headers).get('authorization') })
      return app.request(path, options)
    }
    const token = vi.fn(() => 'Bearer never-email-token')
    const sdk = createTXBoardClient({ baseURL: 'https://gateway.test/gateway/v1', fetchImpl, getToken: token })
    await expect(sdk.auth.resetPassword(input)).resolves.toEqual({ reset: true })
    expect(http.map(r => r.path)).toEqual(['/gateway/v1/crypto/key', '/gateway/v1/secure/auth/password-reset'])
    expect(http[1]!.body).not.toContain(input.email)
    expect(http.every(r => r.authorization === null)).toBe(true); expect(token).not.toHaveBeenCalled()
    expect(crypto.publicKey().keys[0]!.scope).toBe('account-workflows')
    expect(limiter.check).toHaveBeenCalledWith('password-reset', input.email, '0.0.0.0')
    expect(JSON.parse(sent[0]!.toString()).input).toEqual(input)
    await expect(sdk.auth.register({ ...input, password: 'fixture-password' })).rejects.toMatchObject({ status: 404 })
    expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled()
    expect((await app.request('/gateway/v1/auth/password-reset', { method: 'POST' })).status).toBe(404)
  })

  it('actual encryption rejects replay and wrong operation AAD before limiter/upstream', async () => {
    const crypto = await realCrypto(), native = vi.fn(), limiter = { check: vi.fn(async () => {}) }
    const app = createGatewayApp(config, native, crypto, { limiter })
    const sealed = await encryptForOperation(crypto.publicKey(), input, 'password-reset')
    const send = () => app.request('/gateway/v1/secure/auth/password-reset', { method: 'POST',
      headers: { 'content-type': 'application/json' }, body: JSON.stringify(sealed) })
    expect((await send()).status).toBe(200); expect((await send()).status).toBe(409)
    const wrong = await encryptForOperation(crypto.publicKey(), input, 'register')
    expect((await app.request('/gateway/v1/secure/auth/password-reset', { method: 'POST',
      headers: { 'content-type': 'application/json' }, body: JSON.stringify(wrong) })).status).toBe(400)
    expect(limiter.check).toHaveBeenCalledTimes(1); expect(mock.request).toHaveBeenCalledTimes(1); expect(native).not.toHaveBeenCalled()
  })

  it('missing limiter availability refuses a decrypted request before transport', async () => {
    const crypto = await realCrypto(), native = vi.fn()
    const limiter = { check: vi.fn(async () => { throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Safety store unavailable') }) }
    const app = createGatewayApp(config, native, crypto, { limiter })
    const sealed = await encryptForOperation(crypto.publicKey(), input, 'password-reset')
    const response = await app.request('/gateway/v1/secure/auth/password-reset', { method: 'POST',
      headers: { 'content-type': 'application/json' }, body: JSON.stringify(sealed) })
    expect(response.status).toBe(503); expect(mock.request).not.toHaveBeenCalled(); expect(native).not.toHaveBeenCalled()
  })
})
