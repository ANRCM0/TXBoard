import { readFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } from '@hpke/core'
import { GatewayFailure } from '../services/upstream.js'

const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
const aadPrefix = 'txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/'
const replayWindowMs = 60_000
export type CryptoOperation = 'login' | 'register' | 'email-code'
export interface ReplayStore {
  reserve(kid: string, nonce: string, ttlMs: number): Promise<boolean>
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

function aad(request: SealedRequest, operation: CryptoOperation) {
  return new TextEncoder().encode(aadPrefix + operation + '\n' +
    request.kid + '\n' + request.ts + '\n' + request.nonce)
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
  ) {}

  static async create(
    jwk: JsonWebKey,
    replay: ReplayStore,
    accountWorkflows = false,
    previousJwk?: JsonWebKey,
    previousExpiresAt?: number,
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

    return new CryptoService(current, replay, previous)
  }

  /** 公钥发现：返回当前密钥 + 未过期的旧密钥。 */
  publicKey(): KeyDiscoveryResponse {
    const keys: KeyVersion[] = [this.current.version]
    if (this.previous && !isExpired(this.previous.version)) {
      keys.push(this.previous.version)
    }
    return {
      protocol: 'HPKE-RFC9180',
      suite: 'DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM',
      keys,
    }
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

export async function loadCryptoService(
  env: NodeJS.ProcessEnv, replay?: ReplayStore,
): Promise<CryptoService | undefined> {
  const mode = env.GATEWAY_HPKE_MODE || 'disabled'
  if (!['disabled', 'optional'].includes(mode)) throw new Error('Unsupported GATEWAY_HPKE_MODE')
  if (mode === 'disabled') return undefined
  if (!replay) throw new Error('Redis replay protection is required for HPKE mode')
  const accountEnabled = env.GATEWAY_ACCOUNT_WORKFLOWS_ENABLED === 'true'
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

  return CryptoService.create(jwk, replay, accountEnabled, previousJwk, previousExpiresAt)
}
