import { readFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } from '@hpke/core'
import { GatewayFailure } from '../services/upstream.js'

const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
/**
 * 冻结的 AAD 前缀（GW-403 D7）：协议版本 ‖ HTTP 方法 ‖ 操作路径前缀。
 * Gateway 与 SDK 两侧字节级一致；任何漂移 → 全部请求 400。
 */
export const aadPrefix = 'txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/'
/**
 * 单步操作（login/register/email-code）的 session 段占位。多步流程
 * （如 GW-310 快速购买）在此段绑定会话标识；占位符段与换行**不得省略**，
 * 否则 AAD 字节位移与多步流程不兼容。
 */
export const singleShotSession = '-'
const replayWindowMs = 60_000
export type CryptoOperation = 'login' | 'register' | 'email-code'
export interface ReplayStore {
  reserve(kid: string, nonce: string, ttlMs: number): Promise<boolean>
}

/** 受信 kid 的格式（内容寻址：序列化公钥 SHA-256 前 24 位十六进制）。 */
const kidPattern = /^[a-f0-9]{24}$/

/**
 * 构建冻结的六段 AAD（GW-403 D7）：
 * `txboard-gateway:v1\n{method}\n{path-prefix}{operation}\n{kid}\n{ts}\n{nonce}\n{session}`
 * Gateway 与 SDK 共用同一实现，保证两侧字节级一致。
 */
export function buildAad(
  operation: CryptoOperation,
  kid: string,
  ts: number,
  nonce: string,
  session: string = singleShotSession,
): Uint8Array {
  return new TextEncoder().encode(aadPrefix + operation + '\n' + kid + '\n' + ts + '\n' + nonce + '\n' + session)
}

const suite = new CipherSuite({
  kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
})

export type SealedRequest = {
  kid: string
  ts: number
  nonce: string
  enc: string
  ct: string
}

/** 单个密钥版本的公钥信息。 */
export type KeyVersion = {
  kid: string
  publicKey: string
  scope: 'login-only' | 'account-workflows'
  /** 旧密钥的过期时间（毫秒时间戳）。当前密钥无此字段。 */
  expiresAt?: number
}

/** 公钥发现端点的响应。 */
export type KeyDiscoveryResponse = {
  protocol: 'HPKE-RFC9180'
  suite: 'DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM'
  keys: KeyVersion[]
}

function fromB64(value: string, max: number): Uint8Array {
  if (!/^[A-Za-z0-9_-]+$/.test(value) || value.length > max) throw new Error('Invalid base64url')
  const bytes = Buffer.from(value, 'base64url')
  if (bytes.toString('base64url') !== value) throw new Error('Invalid base64url encoding')
  return bytes
}

function aad(request: SealedRequest, operation: CryptoOperation, session?: string) {
  return buildAad(operation, request.kid, request.ts, request.nonce, session)
}

/** 内部密钥材料。 */
interface KeyMaterial {
  privateKey: CryptoKey
  version: KeyVersion
}

function isExpired(version: KeyVersion): boolean {
  return version.expiresAt !== undefined && Date.now() > version.expiresAt
}

export class CryptoService {
  private constructor(
    private readonly current: KeyMaterial,
    private readonly replay: ReplayStore,
    private readonly previous?: KeyMaterial,
    private readonly revoked: ReadonlySet<string> = new Set(),
  ) {}

  static async create(
    jwk: JsonWebKey,
    replay: ReplayStore,
    accountWorkflows = false,
    previousJwk?: JsonWebKey,
    previousExpiresAt?: number,
    revokedKids?: Iterable<string>,
  ): Promise<CryptoService> {
    if (jwk.kty !== 'EC' || jwk.crv !== 'P-256' || !jwk.d || !jwk.x || !jwk.y) {
      throw new Error('Gateway HPKE key must be a P-256 private JWK')
    }
    const secret = await suite.kem.importKey('jwk', jwk, false)
    const publicKey = await suite.kem.importKey('jwk',
      { kty: 'EC', crv: 'P-256', x: jwk.x, y: jwk.y }, true)
    const serialized = new Uint8Array(await suite.kem.serializePublicKey(publicKey))
    const kid = createHash('sha256').update(serialized).digest('hex').slice(0, 24)
    if (!replay) throw new Error('Redis replay store required for encrypted Gateway')

    const current: KeyMaterial = {
      privateKey: secret,
      version: {
        kid,
        publicKey: Buffer.from(serialized).toString('base64url'),
        scope: accountWorkflows ? 'account-workflows' : 'login-only',
      },
    }

    let previous: KeyMaterial | undefined
    if (previousJwk) {
      if (previousJwk.kty !== 'EC' || previousJwk.crv !== 'P-256' || !previousJwk.d || !previousJwk.x || !previousJwk.y) {
        throw new Error('Gateway previous HPKE key must be a P-256 private JWK')
      }
      const prevSecret = await suite.kem.importKey('jwk', previousJwk, false)
      const prevPublicKey = await suite.kem.importKey('jwk',
        { kty: 'EC', crv: 'P-256', x: previousJwk.x, y: previousJwk.y }, true)
      const prevSerialized = new Uint8Array(await suite.kem.serializePublicKey(prevPublicKey))
      const prevKid = createHash('sha256').update(prevSerialized).digest('hex').slice(0, 24)
      previous = {
        privateKey: prevSecret,
        version: {
          kid: prevKid,
          publicKey: Buffer.from(prevSerialized).toString('base64url'),
          scope: accountWorkflows ? 'account-workflows' : 'login-only',
          expiresAt: previousExpiresAt,
        },
      }
    }

    return new CryptoService(current, replay, previous, new Set(revokedKids))
  }

  /** 公钥发现：返回当前密钥 + 未过期且未被吊销的旧密钥。 */
  publicKey(): KeyDiscoveryResponse {
    const keys: KeyVersion[] = []
    const currentVisible = this.visibleVersion(this.current.version)
    if (currentVisible) keys.push(currentVisible)
    if (this.previous) {
      const visible = this.visibleVersion(this.previous.version)
      if (visible) keys.push(visible)
    }
    return {
      protocol: 'HPKE-RFC9180',
      suite: 'DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM',
      keys,
    }
  }

  /**
   * 发现端点可见性（GW-402 D4）：吊销 kid 立即从发现端点移除（即使仍在旧
   * 密钥窗口内）；旧密钥过期同样移除。当前密钥不可见 = 配置错误（当前密钥
   * 被吊销时应整端点不可用，由调用方处理）。
   */
  private visibleVersion(version: KeyVersion): KeyVersion | null {
    if (this.revoked.has(version.kid)) return null
    if (isExpired(version)) return null
    return version
  }

  /** 旧密钥是否已过期。 */
  private isPreviousExpired(): boolean {
    return this.previous ? isExpired(this.previous.version) : true
  }

  async open(request: SealedRequest, operation: CryptoOperation = 'login'): Promise<unknown> {
    if (operation !== 'login' && this.current.version.scope !== 'account-workflows') {
      throw new GatewayFailure('VALIDATION_ERROR', 404, 'Encrypted operation is disabled')
    }
    if (!Number.isSafeInteger(request.ts) || Math.abs(Date.now() - request.ts) > replayWindowMs) {
      throw new GatewayFailure('VALIDATION_ERROR', 400, 'Expired encrypted request')
    }

    // 吊销检查先于任何私钥尝试（GW-402 D4）：被吊销 kid 一律拒绝，包括
    // 尚未过期的旧密钥窗口内的 kid——吊销是强制失效，不依赖窗口。
    if (this.revoked.has(request.kid)) {
      throw new GatewayFailure('VALIDATION_ERROR', 400, 'Unknown encryption key')
    }

    // 尝试当前密钥
    if (request.kid === this.current.version.kid) {
      return this.openWith(this.current, request, operation)
    }
    // 尝试旧密钥（如果未过期）
    if (this.previous && request.kid === this.previous.version.kid && !this.isPreviousExpired()) {
      return this.openWith(this.previous, request, operation)
    }
    throw new GatewayFailure('VALIDATION_ERROR', 400, 'Unknown encryption key')
  }

  private async openWith(material: KeyMaterial, request: SealedRequest, operation: CryptoOperation): Promise<unknown> {
    let plaintext: ArrayBuffer
    try {
      const recipient = await suite.createRecipientContext({
        recipientKey: material.privateKey,
        enc: fromB64(request.enc, 500),
        info,
      })
      plaintext = await recipient.open(fromB64(request.ct, 20000), aad(request, operation))
    } catch {
      throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid encrypted request')
    }
    // The atomic Redis SET NX PX happens before any Laravel effect. If Redis
    // is down, fail closed; there is no process-local replay fallback.
    let first: boolean
    try {
      first = await this.replay.reserve(request.kid, request.nonce, replayWindowMs * 2 + 1000)
    } catch {
      throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503, 'Replay protection unavailable')
    }
    if (!first) throw new GatewayFailure('VALIDATION_ERROR', 409, 'Replayed encrypted request')
    try {
      return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(plaintext))
    } catch {
      throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid decrypted JSON')
    }
  }
}

function intSetting(value: string | undefined, fallback: number, min: number, max: number): number {
  if (value === undefined || value.trim() === '') return fallback
  const parsed = Number(value)
  if (!Number.isSafeInteger(parsed) || parsed < min || parsed > max) {
    throw new Error(`Invalid gateway numeric configuration (expected ${min}-${max})`)
  }
  return parsed
}

/**
 * 解析 `GATEWAY_HPKE_REVOKED_KIDS`（GW-402 D4）：逗号分隔的 24 位十六进制
 * kid 列表。非法 kid 格式 fail-hard 启动（防止误拼导致"以为吊销了其实没有"）；
 * 吊销不存在的 kid 是合法 no-op（幂等）。空白项忽略。
 */
export function parseRevokedKids(value: string | undefined): ReadonlySet<string> {
  if (value === undefined || value.trim() === '') return new Set()
  const kids = new Set<string>()
  for (const raw of value.split(',')) {
    const kid = raw.trim()
    if (kid === '') continue
    if (!kidPattern.test(kid)) {
      throw new Error('GATEWAY_HPKE_REVOKED_KIDS entries must be 24-char hex key ids')
    }
    kids.add(kid)
  }
  return kids
}

export async function loadCryptoService(
  env: NodeJS.ProcessEnv, replay?: ReplayStore,
): Promise<CryptoService | undefined> {
  const mode = env.GATEWAY_HPKE_MODE || 'disabled'
  if (!['disabled', 'optional'].includes(mode)) throw new Error('Unsupported GATEWAY_HPKE_MODE')
  if (mode === 'disabled') return undefined
  if (!replay) throw new Error('Redis replay protection is required for HPKE mode')
  const emailOnly = env.GATEWAY_INTERNAL_EMAIL_CODE_ENABLED === 'true'
  const registerOnly = env.GATEWAY_INTERNAL_REGISTER_ENABLED === 'true'
  const accountEnabled = env.GATEWAY_ACCOUNT_WORKFLOWS_ENABLED === 'true'
  if ((emailOnly || registerOnly) && (accountEnabled || !['local', 'testing'].includes(env.APP_ENV ?? ''))) {
    throw new Error('Invalid isolated account HPKE scope configuration')
  }
  const keyFile = env.GATEWAY_HPKE_KEY_FILE
  if (!keyFile || !keyFile.startsWith('/')) {
    throw new Error('GATEWAY_HPKE_KEY_FILE must be an absolute Docker secret path')
  }
  // No generated/ephemeral key on each restart: fail hard without persisted key.
  const content = await readFile(keyFile, { encoding: 'utf8' })
  let jwk: JsonWebKey
  try { jwk = JSON.parse(content) } catch { throw new Error('Gateway HPKE key file is not valid JSON') }

  // 加载旧密钥（可选）
  let previousJwk: JsonWebKey | undefined
  let previousExpiresAt: number | undefined
  const previousKeyFile = env.GATEWAY_HPKE_PREVIOUS_KEY_FILE
  if (previousKeyFile) {
    if (!previousKeyFile.startsWith('/')) {
      throw new Error('GATEWAY_HPKE_PREVIOUS_KEY_FILE must be an absolute Docker secret path')
    }
    const prevContent = await readFile(previousKeyFile, { encoding: 'utf8' })
    try { previousJwk = JSON.parse(prevContent) } catch { throw new Error('Gateway previous HPKE key file is not valid JSON') }
    // 旧密钥 TTL 默认 24 小时，最大 7 天
    const ttlMs = intSetting(env.GATEWAY_HPKE_PREVIOUS_KEY_TTL_MS, 86_400_000, 60_000, 604_800_000)
    previousExpiresAt = Date.now() + ttlMs
  }

  // 吊销表：非法 kid 格式 fail-hard 启动（防误拼"以为吊销了其实没有"）。
  const revokedKids = parseRevokedKids(env.GATEWAY_HPKE_REVOKED_KIDS)

  // Existing key scope is broader than the email-only route allowlist. It does
  // not authorize registration; the policy table and Core operation remain final.
  return CryptoService.create(jwk, replay, accountEnabled || emailOnly || registerOnly, previousJwk, previousExpiresAt, revokedKids)
}
