import { describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { CryptoService } from '../src/services/crypto.js'
import {
  MemoryCaptchaChallenge,
  RedisCaptchaChallenge,
  challengeTtlMs,
  extractChallengeToken,
} from '../src/services/captcha-challenge.js'
import { MemoryLoginProtection, type LoginPolicy } from '../src/middleware/login-protection.js'
import { generateKeyPairSync } from 'node:crypto'
import { GatewayFailure } from '../src/services/upstream.js'
import { encryptLoginPayload } from '../../../packages/theme-sdk/src/crypto.js'

/**
 * GW-209: pluggable one-time CAPTCHA challenge.
 *
 * Contract under test:
 *  - issueChallenge() stores a random challenge with a purpose-derived TTL
 *    (login 5min / register 10min / checkout 15min) and returns its id.
 *  - verifyChallenge() is atomic: the Lua GET+DEL consumes the challenge
 *    exactly once ACROSS REPLICAS. A replay, an unknown id, an expired
 *    challenge or a wrong token is refused before any Laravel traffic.
 *  - A 428 login challenge response carries the challengeId in
 *    X-Captcha-Challenge; the token is the challengeId echoed back by the
 *    client in the same header, and it is forwarded verbatim to Laravel.
 *  - Redis unavailability fails CLOSED (503), never a silent pass.
 *  - Nothing lowers the existing email/account/IP posture: GW-203 locks and
 *    GW-202 rate limits still apply in front of the challenge.
 */

const config = loadConfig({
  TXBOARD_UPSTREAM_URL: 'https://txboard.example/',
  GATEWAY_ALLOWED_ORIGINS: 'https://theme.example',
})
const token = 'Bearer usertoken_longer_than_eight'
function ok(data: unknown) { return { status: 'success', message: null, data } }
function loginOk() { return vi.fn(async () => Response.json(ok({ auth_data: token, is_admin: false }))) }
function loginRejected() { return vi.fn(async () => Response.json({ status: 'fail', message: 'Bad credentials' }, { status: 401 })) }
async function dataOf(response: Response) { return await response.json() as any }

/** Two failures arm the GW-203 challenge; a third attempt without a token is 428. */
const tightPolicy: LoginPolicy = {
  accountCaptchaAfter: 2, ipCaptchaAfter: 10, failureWindowMs: 900_000, lockTiers: [[4, 300_000]],
}

function fakeReplay() {
  const seen = new Set<string>()
  return { reserve: async (kid: string, nonce: string) => {
    const key = kid + ':' + nonce
    if (seen.has(key)) return false
    seen.add(key)
    return true
  } }
}

function loginRequest(app: ReturnType<typeof createGatewayApp>, body: Record<string, unknown>, headers: Record<string, string> = {}) {
  return app.request('/gateway/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: JSON.stringify({ email: 'victim@example.test', password: 'pass12345678', ...body }),
  })
}

/** In-memory Lua interpreter for the two GW-209 scripts. Mirrors Redis
 *  semantics: SET PX issue, GET+DEL atomic consume, TTL expiry. */
function fakeRedis(now = () => Date.now()) {
  const store = new Map<string, { value: string; expiresAt: number }>()
  const client = {
    isReady: true,
    destroy: () => {},
    eval: vi.fn(async (script: string, opts: { keys: string[]; arguments: string[] }) => {
      const key = opts.keys[0]!
      const entry = store.get(key)
      const expired = entry && entry.expiresAt <= now()
      if (script.includes("redis.call('SET'")) {
        store.set(key, { value: opts.arguments[0]!, expiresAt: now() + Number(opts.arguments[1]) })
        return 1
      }
      if (script.includes("redis.call('GET'")) {
        if (!entry || expired) return 1 // CHALLENGE_UNKNOWN / expired
        store.delete(key) // atomic consume BEFORE the comparison
        return entry.value === opts.arguments[0] ? 0 : 4 // OK / CONSUMED
      }
      throw new Error('Unexpected Lua script')
    }),
  }
  return { client, store }
}

describe('GW-209 challenge store semantics', () => {
  it('issues distinct random challenges and consumes each exactly once', async () => {
    const store = new MemoryCaptchaChallenge()
    const a = await store.issueChallenge('login')
    const b = await store.issueChallenge('login')
    expect(a).not.toBe(b)
    expect(a.length).toBeGreaterThanOrEqual(32)
    // First verification succeeds, every replay fails.
    expect(await store.verifyChallenge(a, a)).toBe('valid')
    expect(await store.verifyChallenge(a, a)).toBe('unknown')
    expect(await store.verifyChallenge(b, b)).toBe('valid')
  })

  it('rejects an unknown challengeId and a wrong token', async () => {
    const store = new MemoryCaptchaChallenge()
    expect(await store.verifyChallenge('never-issued', 'never-issued')).toBe('unknown')
    const id = await store.issueChallenge('login')
    // A wrong token also burns the challenge: the consume precedes the compare.
    expect(await store.verifyChallenge(id, 'wrong-token')).toBe('consumed')
    expect(await store.verifyChallenge(id, id)).toBe('unknown')
  })

  it('expires a challenge after its purpose TTL', async () => {
    expect(challengeTtlMs('login')).toBe(5 * 60_000)
    expect(challengeTtlMs('register')).toBe(10 * 60_000)
    expect(challengeTtlMs('checkout')).toBe(15 * 60_000)
    const base = 1_000_000
    let clock = base
    const store = new MemoryCaptchaChallenge(() => clock)
    const fresh = await store.issueChallenge('checkout')
    // Within the 15-minute tier: valid.
    expect(await store.verifyChallenge(fresh, fresh)).toBe('valid')
    // Issue at base, advance past the checkout TTL: expired, not consumed.
    const stale = await store.issueChallenge('checkout')
    clock = base + 15 * 60_001
    expect(await store.verifyChallenge(stale, stale)).toBe('expired')
    // A challenge issued AFTER the advance is verifiable: the TTL is per-issue.
    const again = await store.issueChallenge('checkout')
    expect(await store.verifyChallenge(again, again)).toBe('valid')
  })

  it('Redis implementation consumes atomically via GET+DEL (cross-replica one-shot)', async () => {
    const { client, store } = fakeRedis()
    const redis = RedisCaptchaChallenge.withClient(client as never)
    const id = await redis.issueChallenge('login')
    expect(store.size).toBe(1)
    // The Lua reply carries both the consume and the verdict.
    const verdict = await redis.verifyChallenge(id, id)
    expect(verdict).toBe('valid')
    expect(store.size).toBe(0) // consumed
    // Replay: the key is gone.
    expect(await redis.verifyChallenge(id, id)).toBe('unknown')
  })

  it('Redis implementation stores only a salted digest key and hashed token', async () => {
    const { client, store } = fakeRedis()
    const redis = RedisCaptchaChallenge.withClient(client as never)
    const id = await redis.issueChallenge('login')
    const [key] = [...store.keys()]
    expect(key).not.toContain(id)
    const stored = [...store.values()][0]!
    expect(stored.value).not.toBe(id)
    expect(stored.expiresAt - Date.now()).toBeLessThanOrEqual(5 * 60_000)
    expect(stored.expiresAt - Date.now()).toBeGreaterThan(4 * 60_000)
  })

  it('fails closed (503) when the challenge store is unavailable', async () => {
    const failing = {
      isReady: false,
      eval: vi.fn(async () => { throw new Error('ECONNREFUSED') }),
      destroy: () => {},
    }
    const redis = RedisCaptchaChallenge.withClient(failing as never)
    await expect(redis.issueChallenge('login')).rejects.toMatchObject({ code: 'UPSTREAM_UNAVAILABLE', status: 503 })
    await expect(redis.verifyChallenge('x', 'x')).rejects.toMatchObject({ code: 'UPSTREAM_UNAVAILABLE', status: 503 })
  })

  it('extracts the standard captcha field, never the Gateway adding one', async () => {
    expect(extractChallengeToken({ turnstile_token: 't' })).toBe('t')
    expect(extractChallengeToken({ recaptcha_v3_token: 'r' })).toBe('r')
    expect(extractChallengeToken({ recaptcha_data: 'd' })).toBe('d')
    expect(extractChallengeToken({ email: 'a@b.c' })).toBeUndefined()
    expect(extractChallengeToken({ turnstile_token: '   ' })).toBeUndefined()
  })
})

describe('GW-209 login challenge flow', () => {
  it('issues a challenge on the 428 and lets the one-shot token through once', async () => {
    const fetcher = loginRejected()
    const protection = new MemoryLoginProtection(tightPolicy)
    const challenge = new MemoryCaptchaChallenge()
    const app = createGatewayApp(config, fetcher, undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    // Two real failures arm the challenge.
    expect((await loginRequest(app)).status).toBe(401)
    expect((await loginRequest(app)).status).toBe(401)
    const challenged = await loginRequest(app)
    expect(challenged.status).toBe(428)
    expect((await dataOf(challenged)).error.code).toBe('CAPTCHA_REQUIRED')
    const challengeId = challenged.headers.get('X-Captcha-Challenge')!
    expect(challengeId).toBeTruthy()
    expect(challengeId.length).toBeGreaterThanOrEqual(32)
    // The upstream was never consulted for the challenge itself.
    expect(fetcher).toHaveBeenCalledTimes(2)

    // The client echoes the challengeId back as the captcha token.
    const successApp = createGatewayApp(config, loginOk(), undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    const passed = await loginRequest(successApp,
      { turnstile_token: challengeId }, { 'X-Captcha-Challenge': challengeId })
    expect(passed.status).toBe(200)
    expect((await dataOf(passed)).data.auth_data).toBe(token)

    // Replay the SAME challenge: consumed, 409, upstream untouched.
    const replayApp = createGatewayApp(config, loginOk(), undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    const replayed = await loginRequest(replayApp,
      { turnstile_token: challengeId }, { 'X-Captcha-Challenge': challengeId })
    expect(replayed.status).toBe(409)
    expect((await dataOf(replayed)).error.code).toBe('CAPTCHA_INVALID')
  })

  it('refuses an expired challenge before any Laravel traffic', async () => {
    const fetcher = loginOk()
    const protection = new MemoryLoginProtection(tightPolicy)
    // Clock starts before issue and jumps past the 5-minute login TTL.
    let clock = 1_000_000
    const challenge = new MemoryCaptchaChallenge(() => clock)
    const armApp = createGatewayApp(config, loginRejected(), undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    expect((await loginRequest(armApp)).status).toBe(401)
    expect((await loginRequest(armApp)).status).toBe(401)
    const challenged = await loginRequest(armApp)
    const challengeId = challenged.headers.get('X-Captcha-Challenge')!
    clock += 5 * 60_001 // expired
    const expiredApp = createGatewayApp(config, fetcher, undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    const expired = await loginRequest(expiredApp,
      { turnstile_token: challengeId }, { 'X-Captcha-Challenge': challengeId })
    expect(expired.status).toBe(409)
    expect((await dataOf(expired)).error.code).toBe('CAPTCHA_INVALID')
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('lets two concurrent verifications of one challenge succeed exactly once', async () => {
    const fetcher = loginOk()
    const protection = new MemoryLoginProtection(tightPolicy)
    const challenge = new MemoryCaptchaChallenge()
    const armApp = createGatewayApp(config, loginRejected(), undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    expect((await loginRequest(armApp)).status).toBe(401)
    expect((await loginRequest(armApp)).status).toBe(401)
    const challenged = await loginRequest(armApp)
    const challengeId = challenged.headers.get('X-Captcha-Challenge')!

    const app = createGatewayApp(config, fetcher, undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    const [first, second] = await Promise.all([
      loginRequest(app, { turnstile_token: challengeId }, { 'X-Captcha-Challenge': challengeId }),
      loginRequest(app, { turnstile_token: challengeId }, { 'X-Captcha-Challenge': challengeId }),
    ])
    const statuses = [first.status, second.status].sort()
    // Exactly one 200; the other is the atomic consume loser (409), never a
    // second success.
    expect(statuses).toEqual([200, 409])
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('rejects a challenge presented without a token (428) and never touches upstream', async () => {
    const fetcher = loginOk()
    const protection = new MemoryLoginProtection(tightPolicy)
    const challenge = new MemoryCaptchaChallenge()
    const armApp = createGatewayApp(config, loginRejected(), undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    expect((await loginRequest(armApp)).status).toBe(401)
    expect((await loginRequest(armApp)).status).toBe(401)
    const challenged = await loginRequest(armApp)
    const challengeId = challenged.headers.get('X-Captcha-Challenge')!

    const app = createGatewayApp(config, fetcher, undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    const noToken = await loginRequest(app, {}, { 'X-Captcha-Challenge': challengeId })
    expect(noToken.status).toBe(428)
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('the 428 challenge demand does not advance the stuffing counters', async () => {
    const fetcher = loginRejected()
    const protection = new MemoryLoginProtection(tightPolicy)
    const challenge = new MemoryCaptchaChallenge()
    const app = createGatewayApp(config, fetcher, undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    // Two real failures arm the challenge.
    expect((await loginRequest(app)).status).toBe(401)
    expect((await loginRequest(app)).status).toBe(401)
    // Many challenge demands: none of them may count as an attempt.
    for (let i = 0; i < 5; i++) {
      expect((await loginRequest(app)).status).toBe(428)
    }
    // Still at fails=2: no lock tier (4) was reached, upstream untouched.
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('does not weaken the lock: a locked account cannot spend a challenge', async () => {
    const fetcher = loginRejected()
    const protection = new MemoryLoginProtection(tightPolicy)
    const challenge = new MemoryCaptchaChallenge()
    const armApp = createGatewayApp(config, fetcher, undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    for (let i = 0; i < 4; i++) {
      await loginRequest(armApp, { turnstile_token: 'legacy-verbatim' })
    }
    const challengeId = await challenge.issueChallenge('login')
    const app = createGatewayApp(config, fetcher, undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    const locked = await loginRequest(app,
      { turnstile_token: challengeId }, { 'X-Captcha-Challenge': challengeId })
    expect(locked.status).toBe(429)
    expect(locked.headers.get('Retry-After')).toBe('300')
  })

  it('issues challenges through the dedicated route with the right TTL tier', async () => {
    const challenge = new MemoryCaptchaChallenge()
    const app = createGatewayApp(config, loginOk(), undefined, { captchaChallenge: challenge })
    for (const purpose of ['login', 'register', 'checkout'] as const) {
      const res = await app.request('/gateway/v1/auth/captcha/challenge', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ purpose }),
      })
      expect(res.status).toBe(200)
      const body = await dataOf(res)
      expect(body.data.purpose).toBe(purpose)
      expect(body.data.ttlMs).toBe(challengeTtlMs(purpose))
      expect(body.data.challengeId).toBeTruthy()
      expect(res.headers.get('X-Captcha-Challenge')).toBe(body.data.challengeId)
    }
  })

  it('the challenge route is absent when no store is wired (404, not a broken promise)', async () => {
    const app = createGatewayApp(config, loginOk())
    const res = await app.request('/gateway/v1/auth/captcha/challenge', { method: 'POST' })
    expect(res.status).toBe(404)
  })

  it('keeps the legacy verbatim-token flow untouched without a store', async () => {
    const fetcher = loginRejected()
    const protection = new MemoryLoginProtection(tightPolicy)
    const app = createGatewayApp(config, fetcher, undefined, { loginProtection: protection })
    expect((await loginRequest(app)).status).toBe(401)
    expect((await loginRequest(app)).status).toBe(401)
    const challenged = await loginRequest(app)
    expect(challenged.status).toBe(428)
    // No challenge header: the client keeps forwarding tokens to Laravel.
    expect(challenged.headers.get('X-Captcha-Challenge')).toBeNull()
  })

  it('works identically on the encrypted login endpoint (HPKE compatible)', async () => {
    const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
    const protection = new MemoryLoginProtection(tightPolicy)
    const challenge = new MemoryCaptchaChallenge()
    const crypto = await CryptoService.create(privateKey.export({ format: 'jwk' }), fakeReplay())
    const fetcher = loginRejected()
    const app = createGatewayApp(config, fetcher, crypto, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    const discovery = crypto.publicKey()
    const send = async (captcha?: string) => app.request('/gateway/v1/secure/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(await encryptLoginPayload(discovery, {
        email: 'victim@example.test', password: 'pass12345678',
        ...(captcha ? { turnstile_token: captcha } : {}),
      })),
    })
    expect(await send()).toMatchObject({ status: 401 })
    expect(await send()).toMatchObject({ status: 401 })
    const challenged = await send()
    expect(challenged.status).toBe(428)
    const challengeId = challenged.headers.get('X-Captcha-Challenge')!
    expect(challengeId).toBeTruthy()
    // The challengeId rides in the request header; the token is inside the
    // sealed payload — the HPKE envelope is unchanged by GW-209.
    const successApp = createGatewayApp(config, loginOk(), crypto, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    const passed = await successApp.request('/gateway/v1/secure/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Captcha-Challenge': challengeId },
      body: JSON.stringify(await encryptLoginPayload(discovery, {
        email: 'victim@example.test', password: 'pass12345678', turnstile_token: challengeId,
      })),
    })
    expect(passed.status).toBe(200)
  })

  it('fails closed (503) when the challenge store drops mid-flow', async () => {
    const fetcher = loginOk()
    const protection = new MemoryLoginProtection(tightPolicy)
    const challenge = new MemoryCaptchaChallenge()
    const armApp = createGatewayApp(config, loginRejected(), undefined, {
      loginProtection: protection, captchaChallenge: challenge,
    })
    expect((await loginRequest(armApp)).status).toBe(401)
    expect((await loginRequest(armApp)).status).toBe(401)
    const challenged = await loginRequest(armApp)
    const challengeId = challenged.headers.get('X-Captcha-Challenge')!
    // Simulate the store dying before the retry: issueChallenge throws.
    const dying = {
      issueChallenge: async () => { throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Captcha challenge store unavailable') },
      verifyChallenge: async () => { throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Captcha challenge store unavailable') },
    }
    const app = createGatewayApp(config, fetcher, undefined, {
      loginProtection: protection, captchaChallenge: dying as never,
    })
    const failed = await loginRequest(app,
      { turnstile_token: challengeId }, { 'X-Captcha-Challenge': challengeId })
    expect(failed.status).toBe(503)
    expect(fetcher).not.toHaveBeenCalled()
  })
})
