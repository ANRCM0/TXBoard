import { createHash, randomBytes, timingSafeEqual } from 'node:crypto'
import { createClient } from 'redis'
import { GatewayFailure } from './upstream.js'

/**
 * GW-209: pluggable one-time CAPTCHA challenge with atomic consumption.
 *
 * Design:
 * - issueChallenge(): generates a random challenge, stores it in Redis with a
 *   purpose-derived TTL (login 5min / register 10min / checkout 15min) and
 *   returns the challengeId to the client.
 * - verifyChallenge(challengeId, token): atomically verifies AND consumes the
 *   challenge via a single Lua script (GET + DEL). The DEL is the atomic step
 *   that makes the challenge one-shot ACROSS REPLICAS: two concurrent
 *   verifications race inside one Lua evaluation, and only the winner sees a
 *   non-empty GET.
 * - Token format: the challengeId itself is the token. The client echoes it
 *   back in a standard captcha field (turnstile_token etc.) and the Gateway
 *   matches it against Redis.
 * - Redis keys are HMAC-SHA256 digests: a raw challengeId never reaches a key,
 *   and the stored value is the hash of the expected token, not the token.
 * - Redis unavailability fails CLOSED (503): without the challenge store a
 *   challenged attempt must never reach Laravel, so it can never be satisfied
 *   by simply waiting out a Redis outage.
 * - MemoryCaptchaChallenge mirrors these semantics (expiry, one-shot consume,
 *   timing-safe token compare) for unit tests and single-node deployments.
 *
 * This module REPLACES NOTHING: Laravel CaptchaService remains the final
 * authority for real Turnstile/reCAPTCHA tokens, and every existing
 * account/IP/email throttle and lock tier in login-protection.ts and
 * redis-security.ts still applies in front of it.
 */

export type ChallengePurpose = 'login' | 'register' | 'checkout'

const TTL_MS: Record<ChallengePurpose, number> = {
  login: 5 * 60_000,
  register: 10 * 60_000,
  checkout: 15 * 60_000,
}

/** Public accessor for the purpose-derived TTL (used by the issue route). */
export function challengeTtlMs(purpose: ChallengePurpose): number {
  return TTL_MS[purpose]
}

/** Verdict of a challenge verification. */
export type ChallengeVerdict = 'valid' | 'consumed' | 'expired' | 'unknown'

export interface CaptchaChallenge {
  /** Issue a one-time challenge. Returns the challengeId to send to the client. */
  issueChallenge(purpose: ChallengePurpose): Promise<string>
  /**
   * Atomically verify and consume a challenge.
   * Returns the verdict: 'valid' on the single successful consumption,
   * 'unknown' when the challengeId was never issued, and 'consumed' when it
   * was already used or has expired. Throws on store unavailability.
   */
  verifyChallenge(challengeId: string, token: string): Promise<ChallengeVerdict>
}

/** Redis failure codes. 0 unknown, 1 expired, 2 token mismatch, 3 consumed. */
const CHALLENGE_OK = 0
const CHALLENGE_UNKNOWN = 1
const CHALLENGE_EXPIRED = 2
const CHALLENGE_MISMATCH = 3
const CHALLENGE_CONSUMED = 4

/**
 * Atomic consume: GET + DEL in a single Lua script, so the GET/DEL pair can
 * never be split by another replica. A replay arrives after the DEL and sees
 * an empty GET, which is reported as a distinct verdict (never a success).
 */
const VERIFY_LUA = [
  "local raw = redis.call('GET', KEYS[1])",
  'if not raw then return 1 end',
  "redis.call('DEL', KEYS[1])",
  'if raw == ARGV[1] then return 0 end',
  'return 3',
].join('\n')

/** Issue challenge: SET with PX (overwrites any leftover value atomically). */
const ISSUE_LUA = [
  "redis.call('SET', KEYS[1], ARGV[1], 'PX', ARGV[2])",
  'return 1',
].join('\n')

/** Raw challengeIds never appear in Redis keys: the key is a salted digest. */
function keyFor(challengeId: string): string {
  const h = createHash('sha256').update('txbgw:v1:captcha:' + challengeId).digest('hex')
  return 'txbgw:v1:captcha:' + h
}

function tokenHash(token: string): string {
  return createHash('sha256').update('txbgw:v1:captcha-token:' + token).digest('hex')
}

function generateChallengeId(): string {
  return randomBytes(24).toString('base64url')
}

function safeEqualHex(a: string, b: string): boolean {
  try {
    return timingSafeEqual(Buffer.from(a, 'hex'), Buffer.from(b, 'hex'))
  } catch {
    return false
  }
}

function verdictFrom(code: unknown): ChallengeVerdict {
  switch (code) {
    case CHALLENGE_OK: return 'valid'
    case CHALLENGE_EXPIRED: return 'expired'
    case CHALLENGE_CONSUMED: return 'consumed'
    default: return 'unknown'
  }
}

/** Redis-backed challenge store. Atomic GET+DEL consumption via Lua. */
export class RedisCaptchaChallenge implements CaptchaChallenge {
  private constructor(private readonly client: ReturnType<typeof createClient>) {}

  /** Test seam: wrap an existing (real or fake) client. */
  static withClient(client: ReturnType<typeof createClient>): RedisCaptchaChallenge {
    return new RedisCaptchaChallenge(client)
  }

  static async connect(raw: string): Promise<RedisCaptchaChallenge> {
    let url: URL
    try { url = new URL(raw) } catch { throw new Error('Invalid GATEWAY_REDIS_URL') }
    if (!['redis:', 'rediss:'].includes(url.protocol) || !url.hostname
      || url.search || url.hash) throw new Error('Invalid GATEWAY_REDIS_URL')
    const client = createClient({
      url: raw,
      disableOfflineQueue: true,
      socket: { connectTimeout: 2500, reconnectStrategy: false },
    })
    // Never log connection URLs or error objects: they may carry credentials.
    client.on('error', () => {})
    await client.connect()
    if (!client.isReady) throw new Error('Captcha challenge store not ready')
    return new RedisCaptchaChallenge(client)
  }

  private ready() {
    if (!this.client.isReady) {
      throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Captcha challenge store unavailable')
    }
  }

  async issueChallenge(purpose: ChallengePurpose): Promise<string> {
    this.ready()
    const challengeId = generateChallengeId()
    try {
      await this.client.eval(ISSUE_LUA, {
        keys: [keyFor(challengeId)], arguments: [tokenHash(challengeId), String(TTL_MS[purpose])],
      })
    } catch {
      throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Captcha challenge store unavailable')
    }
    return challengeId
  }

  async verifyChallenge(challengeId: string, token: string): Promise<ChallengeVerdict> {
    this.ready()
    if (typeof challengeId !== 'string' || !challengeId) return 'unknown'
    let result: unknown
    try {
      result = await this.client.eval(VERIFY_LUA, {
        keys: [keyFor(challengeId)], arguments: [tokenHash(token)],
      })
    } catch {
      throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Captcha challenge store unavailable')
    }
    if (typeof result !== 'number' || !Number.isSafeInteger(result)) {
      throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Captcha challenge store unavailable')
    }
    return verdictFrom(result)
  }

  async close(): Promise<void> {
    try { this.client.destroy() } catch { /* already gone */ }
  }
}

/**
 * In-process implementation for unit tests and single-node deployments.
 * Semantics (expiry, one-shot consume, timing-safe compare) mirror the Redis
 * path exactly: the DEL/delete happens before the token comparison, so a
 * wrong token also burns the challenge.
 */
export class MemoryCaptchaChallenge implements CaptchaChallenge {
  private readonly challenges = new Map<string, { tokenHash: string; expiresAt: number }>()

  constructor(private readonly now: () => number = Date.now) {}

  async issueChallenge(purpose: ChallengePurpose): Promise<string> {
    const challengeId = generateChallengeId()
    this.challenges.set(challengeId, {
      tokenHash: tokenHash(challengeId),
      expiresAt: this.now() + TTL_MS[purpose],
    })
    return challengeId
  }

  async verifyChallenge(challengeId: string, token: string): Promise<ChallengeVerdict> {
    if (typeof challengeId !== 'string' || !challengeId) return 'unknown'
    const entry = this.challenges.get(challengeId)
    if (!entry) return 'unknown'
    // One-shot consume happens FIRST, before the token comparison: a replayed
    // or wrong-token attempt must never be able to succeed twice.
    this.challenges.delete(challengeId)
    if (entry.expiresAt <= this.now()) return 'expired'
    return safeEqualHex(entry.tokenHash, tokenHash(token)) ? 'valid' : 'consumed'
  }
}

/**
 * Extract a challenge token from a captcha-bearing request body. The Gateway
 * never validates the CAPTCHA itself — it only checks whether the submitted
 * token matches a previously-issued challenge.
 */
export function extractChallengeToken(data: Record<string, unknown>): string | undefined {
  for (const key of ['turnstile_token', 'recaptcha_v3_token', 'recaptcha_data']) {
    const value = data[key]
    if (typeof value === 'string' && value.trim()) return value.trim()
  }
  return undefined
}
