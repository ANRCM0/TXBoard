import { GatewayFailure, upstreamRequest } from '../services/upstream.js'
import type { AccountAction, AccountLimiter } from '../services/redis-security.js'
import type { CryptoOperation, CryptoService, SealedRequest } from '../services/crypto.js'
import {
  emailCodeSchema,
  loginResultSchema,
  loginSchema,
  registerSchema,
  sealedRequestSchema,
} from '../contracts/schemas.js'
import { PREFIX } from '../middleware/request-context.js'
import { countsAsCredentialFailure, hasCaptchaField } from '../middleware/auth.js'
import {
  normalizeLoginEmail,
  type LoginAdmission,
  type LoginProtection,
} from '../middleware/login-protection.js'
import { extractChallengeToken, type CaptchaChallenge, type ChallengePurpose, challengeTtlMs } from '../services/captcha-challenge.js'
import { readJsonRequest } from '../middleware/validation.js'
import { fail, ok, validated, type RouteContext, type RouteDeps } from './shared.js'

/** Key discovery response: a list of key versions. */
export type KeyDiscoveryResponse = {
  protocol: 'HPKE-RFC9180'
  suite: string
  keys: Array<{
    kid: string
    publicKey: string
    scope: 'login-only' | 'account-workflows'
    expiresAt?: number
  }>
}

/**
 * routes/account.ts — credential and account-workflow routes.
 *
 * `login` keeps the existing plaintext path working: no HPKE upgrade is used as
 * a silent breaking change. `secureRegister` / `secureEmailCode` are gated on
 * HPKE + Redis + the feature flag together and stay off by default.
 *
 * This module creates no Laravel business permissions: password and CAPTCHA
 * verification, and account existence, remain Laravel's decision.
 */

export type AccountRouteDeps = RouteDeps & {
  crypto?: CryptoService
  limiter?: AccountLimiter
  loginProtection?: LoginProtection
  /** GW-209: one-time CAPTCHA challenge store. When absent, the 428
   *  challenge carries no challengeId and the token is forwarded to Laravel
   *  verbatim, exactly as before this feature existed. */
  captchaChallenge?: CaptchaChallenge
  accountWorkflows: boolean
}

/** Resolve the sanitized client IP set by the trusted-proxy middleware. */
function clientIpOf(c: RouteContext): string | undefined {
  return (c.req.raw as unknown as { __clientIp?: string }).__clientIp
}

/** Redis account-level throttle protects the Gateway entry point. Laravel
 *  remains the authority for password and CAPTCHA and also needs own limits. */
async function checkAccountLimiter(
  c: RouteContext, limiter: AccountLimiter,
  action: AccountAction, email: string,
): Promise<Response | null> {
  try {
    await limiter.check(action, email, clientIpOf(c))
  } catch (error) {
    if (error instanceof GatewayFailure) {
      return fail(c, error.code, error.message, error.status as 400 | 429 | 503)
    }
    throw error
  }
  return null
}

/**
 * GW-203: outcome-driven anti-credential-stuffing. The verdict is decided on
 * the SUBMITTED email and the sanitized source IP only — never on whether the
 * account exists — so existing and unknown accounts receive byte-identical
 * statuses, codes and Retry-After headers at every step.
 *
 * GW-209: when a challenge store is wired, the 428 response carries a fresh
 * one-time challengeId (5-minute login TTL). The challenge is issued AFTER
 * the verdict, never before, so a plain 428 can never be used to farm
 * challenges; and the upstream is never consulted, so a challenge demand
 * itself never advances the stuffing counters.
 */
async function applyLoginProtection(
  c: RouteContext,
  protection: LoginProtection | undefined,
  email: string,
  data: Record<string, unknown>,
  captchaChallenge?: CaptchaChallenge,
): Promise<Response | null> {
  if (!protection) return null
  const clientIp = clientIpOf(c)
  let verdict: LoginAdmission
  try {
    verdict = await protection.admit(email, clientIp)
  } catch {
    // Fail closed: without the safety store, no login attempt may reach
    // Laravel. This mirrors the replay/rate-limit failure posture.
    return fail(c, 'UPSTREAM_UNAVAILABLE', 'Login protection unavailable', 503)
  }
  if (verdict.verdict === 'locked') {
    c.header('Retry-After', String(Math.ceil(verdict.retryAfterMs / 1000)))
    return fail(c, 'RATE_LIMITED', 'Too many requests', 429)
  }
  if (verdict.verdict === 'captcha' && !hasCaptchaField(data)) {
    if (!captchaChallenge) {
      return fail(c, 'CAPTCHA_REQUIRED', 'Verification required to continue', 428)
    }
    let challengeId: string
    try {
      challengeId = await captchaChallenge.issueChallenge('login')
    } catch (error) {
      // Fail closed: a challenge that cannot be issued must not be answered
      // as if the plain 428 flow were still in force, because the token
      // could then never be satisfied by the client either.
      if (error instanceof GatewayFailure) {
        return fail(c, error.code, error.message, error.status as 400 | 428 | 503)
      }
      throw error
    }
    c.header('X-Captcha-Challenge', challengeId)
    // The envelope stays contract-frozen: the challengeId is delivered
    // exclusively through the X-Captcha-Challenge response header, so no
    // theme SDK sees a new body shape and the frozen envelope never breaks.
    return fail(c, 'CAPTCHA_REQUIRED', 'Verification required to continue', 428)
  }
  return null
}

/**
 * GW-209: atomically consume a submitted challenge before the upstream call.
 * Returns the response to send, or null to continue. A challenge that is
 * unknown, already consumed, expired or mismatched is REJECTED before any
 * Laravel traffic — the consumption is the verification. When the store is
 * unavailable this fails closed (503) rather than letting the attempt pass.
 *
 * The challengeId is echoed back by the client in the X-Captcha-Challenge
 * request header (the same name the 428 response used), so no frozen request
 * schema has to change and no body field can smuggle it in. The token is the
 * standard captcha field, forwarded verbatim to Laravel when the challenge
 * passes.
 */
async function consumeChallenge(
  c: RouteContext,
  captchaChallenge: CaptchaChallenge | undefined,
  data: Record<string, unknown>,
): Promise<Response | null> {
  if (!captchaChallenge) return null
  const challengeId = (c.req.header('X-Captcha-Challenge') || '').trim()
  if (!challengeId) return null
  const token = extractChallengeToken(data)
  if (!token) return fail(c, 'CAPTCHA_REQUIRED', 'Verification required to continue', 428)
  let verdict
  try {
    verdict = await captchaChallenge.verifyChallenge(challengeId, token)
  } catch (error) {
    if (error instanceof GatewayFailure) {
      return fail(c, error.code, error.message, error.status as 400 | 428 | 503)
    }
    throw error
  }
  if (verdict !== 'valid') {
    // One shot, cross-replica: a replay, a stale or a wrong token is refused
    // here and never reaches Laravel. The 409 distinguishes a consumed
    // challenge from a missing one without revealing anything about accounts.
    return fail(c, 'CAPTCHA_INVALID', 'Challenge verification failed', 409)
  }
  return null
}

export function createLoginHandler(deps: AccountRouteDeps) {
  const { config, fetcher, limiter, loginProtection, captchaChallenge } = deps
  return async function runLogin(c: RouteContext, raw: unknown): Promise<Response> {
    const checked = loginSchema.safeParse(raw)
    if (!checked.success) return fail(c, 'VALIDATION_ERROR', 'Invalid login data', 400)

    const clientIp = clientIpOf(c)
    if (limiter) {
      const rejected = await checkAccountLimiter(c, limiter, 'login', checked.data.email)
      if (rejected) return rejected
    }

    const email = normalizeLoginEmail(checked.data.email)
    const challenged = await applyLoginProtection(c, loginProtection, email, checked.data, captchaChallenge)
    if (challenged) return challenged

    // GW-209: a presented challenge is consumed atomically BEFORE the
    // upstream call, so a replayed or wrong challenge can never reach
    // Laravel even once. Without a store this is a no-op and the token is
    // forwarded verbatim, exactly as before this feature existed.
    const consumed = await consumeChallenge(c, captchaChallenge, checked.data)
    if (consumed) return consumed

    let result: unknown
    try {
      result = await upstreamRequest(config, fetcher, 'login', { body: checked.data })
    } catch (error) {
      // A genuine upstream rejection advances the stuffing counters.
      if (loginProtection && countsAsCredentialFailure(error)) {
        try { await loginProtection.recordFailure(email, clientIp) } catch { /* logged elsewhere */ }
      }
      throw error
    }
    // A real success clears the account dimension. The IP dimension is
    // intentionally kept: one legitimate login must not launder the stuffing
    // counters of a shared or rotating source address.
    if (loginProtection) {
      try { await loginProtection.recordSuccess(email) } catch { /* non-fatal */ }
    }
    return ok(c, validated(loginResultSchema, result))
  }
}

export function registerAccountRoutes(
  app: {
    get(path: string, handler: (c: RouteContext) => Promise<Response> | Response): unknown
    post(path: string, handler: (c: RouteContext) => Promise<Response> | Response): unknown
  },
  deps: AccountRouteDeps,
): void {
  const { config, fetcher, crypto, limiter, captchaChallenge, accountWorkflows } = deps
  const runLogin = createLoginHandler(deps)

  app.post(`${PREFIX}/auth/login`, async c =>
    runLogin(c, await readJsonRequest(c.req.raw, config.maxRequestBytes)))

  // GW-209: challenge issue. Anonymous, limiter-guarded (public policy) and
  // never cached. Purpose selects the TTL tier: login 5min, register 10min,
  // checkout 15min. When no challenge store is wired the route is absent —
  // a 404 rather than a route that promises a challenge it cannot honour.
  if (captchaChallenge) {
    app.post(`${PREFIX}/auth/captcha/challenge`, async c => {
      let purpose: ChallengePurpose = 'login'
      try {
        const raw = await readJsonRequest(c.req.raw, config.maxRequestBytes)
        const requested = (raw as Record<string, unknown>)?.purpose
        if (requested === 'register' || requested === 'checkout') purpose = requested
      } catch {
        // An unparsable body is not an error here: the default login tier
        // applies. The strict schemas never accept a purpose field anyway.
      }
      let challengeId: string
      try {
        challengeId = await captchaChallenge.issueChallenge(purpose)
      } catch (error) {
        if (error instanceof GatewayFailure) {
          return fail(c, error.code, error.message, error.status as 400 | 503)
        }
        throw error
      }
      c.header('X-Captcha-Challenge', challengeId)
      return ok(c, { challengeId, purpose, ttlMs: challengeTtlMs(purpose) })
    })
  }

  if (crypto) {
    app.get(`${PREFIX}/crypto/key`, c => ok(c, crypto.publicKey() as KeyDiscoveryResponse))

    /** Decrypt-then-dispatch wrapper for every sealed operation. */
    const secure = (operation: CryptoOperation, handle: (c: RouteContext, data: unknown) => Promise<Response>) =>
      async (c: RouteContext) => {
        const raw = await readJsonRequest(c.req.raw, config.maxRequestBytes)
        const checked = sealedRequestSchema.safeParse(raw)
        if (!checked.success) return fail(c, 'VALIDATION_ERROR', 'Invalid encrypted request', 400)
        // Operation-specific AEAD AAD: ciphertext cannot be replayed to
        // registration or email endpoints, even with the same key and nonce.
        const data = await crypto.open(checked.data as SealedRequest, operation)
        return handle(c, data)
      }

    app.post(`${PREFIX}/secure/auth/login`, secure('login', runLogin))

    if (accountWorkflows && limiter) {
      app.post(`${PREFIX}/secure/auth/register`, secure('register', async (c, data) => {
        const parsed = registerSchema.safeParse(data)
        if (!parsed.success) return fail(c, 'VALIDATION_ERROR', 'Invalid registration data', 400)
        const rejected = await checkAccountLimiter(c, limiter, 'register', parsed.data.email)
        if (rejected) return rejected
        return ok(c, validated(loginResultSchema,
          await upstreamRequest(config, fetcher, 'register', { body: parsed.data })))
      }))
    }

    if (accountWorkflows && limiter) {
      app.post(`${PREFIX}/secure/auth/email-code`, secure('email-code', async (c, data) => {
        const parsed = emailCodeSchema.safeParse(data)
        if (!parsed.success) return fail(c, 'VALIDATION_ERROR', 'Invalid verification request', 400)
        const rejected = await checkAccountLimiter(c, limiter, 'email-code', parsed.data.email)
        if (rejected) return rejected
        const result = await upstreamRequest(config, fetcher, 'sendEmailCode', { body: parsed.data })
        if (result !== true) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected upstream response shape')
        return ok(c, { sent: true })
      }))
    }
  }
}
