import { describe, expect, it, beforeAll, afterAll } from 'vitest'
import { generateKeyPairSync, createHash } from 'node:crypto'
import { mkdtemp, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
  CryptoService,
  loadCryptoService,
  parseRevokedKids,
  buildAad,
  type ReplayStore,
  type SealedRequest,
} from '../src/services/crypto.js'

function fakeReplay(): ReplayStore {
  const seen = new Set<string>()
  return {
    reserve: async (kid: string, nonce: string) => {
      const key = kid + ':' + nonce
      if (seen.has(key)) return false
      seen.add(key)
      return true
    },
  }
}

function generateJwk(): JsonWebKey {
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  return privateKey.export({ format: 'jwk' }) as JsonWebKey
}

/** kid = hex(SHA-256(压缩公钥序列化))[0..24)，与 Gateway 派生一致。 */
async function kidOfJwk(jwk: JsonWebKey): Promise<string> {
  const { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } = await import('@hpke/core')
  const suite = new CipherSuite({
    kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
  })
  const publicKey = await suite.kem.importKey(
    'jwk', { kty: 'EC', crv: 'P-256', x: jwk.x, y: jwk.y }, true,
  )
  const serialized = new Uint8Array(await suite.kem.serializePublicKey(publicKey))
  return createHash('sha256').update(serialized).digest('hex').slice(0, 24)
}

/** 固定 ephemeral 密钥对（仅测试夹具）：使 seal 输出确定性，KAT 快照可复现。 */
let fixedEkmPair: CryptoKeyPair
async function fixedEkm(): Promise<CryptoKeyPair> {
  if (fixedEkmPair) return fixedEkmPair
  const { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } = await import('@hpke/core')
  const suite = new CipherSuite({
    kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
  })
  const ephJwk = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
    .privateKey.export({ format: 'jwk' }) as JsonWebKey
  const privateKey = await suite.kem.importKey('jwk', ephJwk, false)
  const publicKey = await suite.kem.importKey(
    'jwk', { kty: 'EC', crv: 'P-256', x: ephJwk.x, y: ephJwk.y }, true,
  )
  fixedEkmPair = { privateKey, publicKey }
  return fixedEkmPair
}

/**
 * 以指定 AAD 与固定 ephemeral 密封一个信封。`mutate` 用于构造负例
 * （篡改 enc/ct 字节），返回最终提交给 open() 的信封。
 */
async function sealEnvelope(
  publicKeyB64: string,
  payload: unknown,
  kid: string,
  ts: number,
  nonce: string,
  aad: Uint8Array,
  mutate?: (sealed: SealedRequest) => SealedRequest,
): Promise<SealedRequest> {
  const { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } = await import('@hpke/core')
  const suite = new CipherSuite({
    kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
  })
  const publicKey = await suite.kem.deserializePublicKey(
    Uint8Array.from(Buffer.from(publicKeyB64, 'base64url')),
  )
  const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
  const sender = await suite.createSenderContext({ recipientPublicKey: publicKey, info, ekm: await fixedEkm() })
  const sealed: SealedRequest = {
    kid,
    ts,
    nonce,
    enc: Buffer.from(new Uint8Array(sender.enc)).toString('base64url'),
    ct: Buffer.from(new Uint8Array(await sender.seal(
      new TextEncoder().encode(JSON.stringify(payload)), aad,
    ))).toString('base64url'),
  }
  return mutate ? mutate(sealed) : sealed
}

/** 翻转 base64url 字段最后一个字节（GCM 认证必失败的篡改）。 */
function flipLastByte(sealed: SealedRequest, field: 'enc' | 'ct'): SealedRequest {
  const bytes = Buffer.from(sealed[field], 'base64url')
  bytes[bytes.length - 1] ^= 0x01
  return { ...sealed, [field]: bytes.toString('base64url') }
}

const payload = { email: 'vector@example.test', password: 'vect0r-pass' }

describe('GW-402/403: HPKE key lifecycle and standard seal/open', () => {
  describe('kid revocation (GATEWAY_HPKE_REVOKED_KIDS, ADR D4)', () => {
    it('removes a revoked kid from discovery even inside the rotation window', async () => {
      const jwk = generateJwk()
      const prevJwk = generateJwk()
      const prevKid = await kidOfJwk(prevJwk)
      const service = await CryptoService.create(
        jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000, [prevKid],
      )
      const discovery = service.publicKey()
      expect(discovery.keys).toHaveLength(1)
      expect(discovery.keys[0]!.kid).not.toBe(prevKid)
    })

    it('rejects a revoked kid even while the old-key window is still valid', async () => {
      const jwk = generateJwk()
      const prevJwk = generateJwk()
      const prevKid = await kidOfJwk(prevJwk)
      const service = await CryptoService.create(
        jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000, [prevKid],
      )
      // 窗口内但已吊销：信封用旧 kid，open() 必须先查吊销表再尝试私钥。
      const ts = Date.now()
      const nonce = 'aaaaaaaaaaaaaaaaaaaaaa'
      const discoveryWithPrev = await CryptoService.create(
        jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000,
      )
      const oldEntry = discoveryWithPrev.publicKey().keys[1]!
      const sealed = await sealEnvelope(
        oldEntry.publicKey, payload, prevKid, ts, nonce,
        buildAad('login', prevKid, ts, nonce),
      )
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 400,
        message: 'Unknown encryption key',
      })
    })

    it('keeps unaffected kids working while another kid is revoked', async () => {
      const jwk = generateJwk()
      const prevJwk = generateJwk()
      const prevKid = await kidOfJwk(prevJwk)
      const service = await CryptoService.create(
        jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000, [prevKid],
      )
      const { encryptLoginPayload } = await import('../../../packages/theme-sdk/src/crypto.js')
      const sealed = await encryptLoginPayload(service.publicKey(), payload)
      expect(sealed.kid).toBe(service.publicKey().keys[0]!.kid)
      expect(await service.open(sealed, 'login')).toEqual(payload)
    })

    it('fails hard on malformed revoked kid entries', () => {
      expect(() => parseRevokedKids('not-a-kid')).toThrow()
      // 超长（>24 位十六进制）
      expect(() => parseRevokedKids('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6')).toThrow()
      // 大写不合法（kid 恒为小写十六进制）
      expect(() => parseRevokedKids('A1B2C3D4E5F6A1B2C3D4E5F6')).toThrow()
    })

    it('accepts well-formed kids, dedupes, and treats unknown kids as no-op', () => {
      const set = parseRevokedKids(
        'a1b2c3d4e5f6a1b2c3d4e5f6, f6e5d4c3b2a1f6e5d4c3b2a1,a1b2c3d4e5f6a1b2c3d4e5f6',
      )
      expect(set).toEqual(new Set(['a1b2c3d4e5f6a1b2c3d4e5f6', 'f6e5d4c3b2a1f6e5d4c3b2a1']))
      expect(parseRevokedKids(undefined)).toEqual(new Set())
      expect(parseRevokedKids('')).toEqual(new Set())
    })
  })

  describe('fail-hard private key loading (ADR D2 / KL-2, KL-9)', () => {
    let dir: string
    beforeAll(async () => { dir = await mkdtemp(join(tmpdir(), 'gw402-')) })
    afterAll(async () => { await rm(dir, { recursive: true, force: true }) })

    it('rejects startup when the current key file is missing', async () => {
      await expect(loadCryptoService(
        { GATEWAY_HPKE_MODE: 'optional', GATEWAY_HPKE_KEY_FILE: join(dir, 'missing.json') },
        fakeReplay(),
      )).rejects.toThrow()
    })

    it('rejects startup when the key file is not valid JSON', async () => {
      const file = join(dir, 'bad.json')
      await writeFile(file, 'not json at all')
      await expect(loadCryptoService(
        { GATEWAY_HPKE_MODE: 'optional', GATEWAY_HPKE_KEY_FILE: file }, fakeReplay(),
      )).rejects.toThrow()
    })

    it('rejects startup when the key file is not a P-256 private JWK', async () => {
      const file = join(dir, 'wrong-type.json')
      await writeFile(file, JSON.stringify({ kty: 'RSA', n: 'x', e: 'AQAB' }))
      await expect(loadCryptoService(
        { GATEWAY_HPKE_MODE: 'optional', GATEWAY_HPKE_KEY_FILE: file }, fakeReplay(),
      )).rejects.toThrow()
    })

    it('rejects startup with a relative key file path', async () => {
      await expect(loadCryptoService(
        { GATEWAY_HPKE_MODE: 'optional', GATEWAY_HPKE_KEY_FILE: 'relative/path.json' }, fakeReplay(),
      )).rejects.toThrow()
    })

    it('rejects startup with an out-of-range previous key TTL (KL-9)', async () => {
      const file = join(dir, 'valid.json')
      await writeFile(file, JSON.stringify(generateJwk()))
      const prevFile = join(dir, 'valid-prev.json')
      await writeFile(prevFile, JSON.stringify(generateJwk()))
      for (const ttl of ['30000', '9999999999999']) {
        await expect(loadCryptoService({
          GATEWAY_HPKE_MODE: 'optional',
          GATEWAY_HPKE_KEY_FILE: file,
          GATEWAY_HPKE_PREVIOUS_KEY_FILE: prevFile,
          GATEWAY_HPKE_PREVIOUS_KEY_TTL_MS: ttl,
        }, fakeReplay())).rejects.toThrow()
      }
    })

    it('rejects startup with a malformed revoked kid env var', async () => {
      const file = join(dir, 'valid.json')
      await writeFile(file, JSON.stringify(generateJwk()))
      await expect(loadCryptoService({
        GATEWAY_HPKE_MODE: 'optional',
        GATEWAY_HPKE_KEY_FILE: file,
        GATEWAY_HPKE_REVOKED_KIDS: 'BADKID',
      }, fakeReplay())).rejects.toThrow()
    })

    it('loads a service with revoked kids and both key files present', async () => {
      const file = join(dir, 'valid.json')
      await writeFile(file, JSON.stringify(generateJwk()))
      const prevFile = join(dir, 'valid-prev.json')
      const prevJwk = generateJwk()
      await writeFile(prevFile, JSON.stringify(prevJwk))
      const service = await loadCryptoService({
        GATEWAY_HPKE_MODE: 'optional',
        GATEWAY_HPKE_KEY_FILE: file,
        GATEWAY_HPKE_PREVIOUS_KEY_FILE: prevFile,
        GATEWAY_HPKE_REVOKED_KIDS: await kidOfJwk(prevJwk),
      }, fakeReplay())
      expect(service).toBeDefined()
      expect(service!.publicKey().keys).toHaveLength(1)
    })
  })

  describe('tamper / wrong-AAD rejection (ADR D8 / AE-1..AE-6)', () => {
    it('rejects a tampered ct (GCM authentication failure)', async () => {
      const service = await CryptoService.create(generateJwk(), fakeReplay())
      const { kid, publicKey } = service.publicKey().keys[0]!
      const ts = Date.now()
      const nonce = 'cccccccccccccccccccccc'
      const sealed = await sealEnvelope(
        publicKey, payload, kid, ts, nonce, buildAad('login', kid, ts, nonce),
        s => flipLastByte(s, 'ct'),
      )
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 400,
        message: 'Invalid encrypted request',
      })
    })

    it('rejects a tampered enc (HPKE decap failure)', async () => {
      const service = await CryptoService.create(generateJwk(), fakeReplay())
      const { kid, publicKey } = service.publicKey().keys[0]!
      const ts = Date.now()
      const nonce = 'dddddddddddddddddddddd'
      const sealed = await sealEnvelope(
        publicKey, payload, kid, ts, nonce, buildAad('login', kid, ts, nonce),
        s => flipLastByte(s, 'enc'),
      )
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 400,
      })
    })

    it('rejects a tampered kid (unknown key before any crypto attempt)', async () => {
      const service = await CryptoService.create(generateJwk(), fakeReplay())
      const { kid, publicKey } = service.publicKey().keys[0]!
      const ts = Date.now()
      const nonce = 'eeeeeeeeeeeeeeeeeeeeee'
      const sealed = await sealEnvelope(
        publicKey, payload, kid, ts, nonce, buildAad('login', kid, ts, nonce),
        s => ({ ...s, kid: 'f'.repeat(24) }),
      )
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 400,
        message: 'Unknown encryption key',
      })
    })

    it('rejects cross-operation transplant (AAD path segment mismatch)', async () => {
      const service = await CryptoService.create(generateJwk(), fakeReplay())
      const { kid, publicKey } = service.publicKey().keys[0]!
      const ts = Date.now()
      const nonce = 'ffffffffffffffffffffff'
      // 以 register AAD 密封，投给 login 操作：AAD path 段不匹配 → GCM 失败。
      const sealed = await sealEnvelope(
        publicKey, payload, kid, ts, nonce, buildAad('register', kid, ts, nonce),
      )
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 400,
        message: 'Invalid encrypted request',
      })
    })

    it('rejects a wrong session AAD (cross-session transplant, AE-6)', async () => {
      const service = await CryptoService.create(generateJwk(), fakeReplay())
      const { kid, publicKey } = service.publicKey().keys[0]!
      const ts = Date.now()
      const nonce = 'abcdefabcdefabcdefabcd'
      // 密封时 session = 会话标识；Gateway 单步操作期望 '-' → 段不匹配 → GCM 失败。
      const sealed = await sealEnvelope(
        publicKey, payload, kid, ts, nonce, buildAad('login', kid, ts, nonce, 'c2Vzc2lvbi1hYmMxMjM0'),
      )
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 400,
        message: 'Invalid encrypted request',
      })
    })
  })

  describe('AAD contract freeze (GW-403 D7)', () => {
    it('freezes the six-segment byte format shared by Gateway and SDK', () => {
      const aad = buildAad('login', 'a'.repeat(24), 1700000000000, 'noncevalue', '-')
      expect(new TextDecoder().decode(aad)).toBe(
        'txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/login\n'
        + 'a'.repeat(24) + '\n1700000000000\nnoncevalue\n-',
      )
    })

    it('produces byte-identical AAD to the SDK seal path', async () => {
      const { encryptForOperation } = await import('../../../packages/theme-sdk/src/crypto.js')
      // SDK seal 与 Gateway buildAad 必须一致——否则跨运行时全部 400。
      // 通过 SDK 密封、Gateway 直接 open（不经过服务层时间窗逻辑）验证：
      const service = await CryptoService.create(generateJwk(), fakeReplay())
      const discovery = service.publicKey()
      const sealed = await encryptForOperation(discovery, payload, 'login')
      expect(await service.open(sealed, 'login')).toEqual(payload)
    })

    it('defaults the session segment to the single-shot placeholder', () => {
      const withDefault = buildAad('login', 'a'.repeat(24), 1700000000000, 'noncevalue')
      const withExplicit = buildAad('login', 'a'.repeat(24), 1700000000000, 'noncevalue', '-')
      expect(Buffer.from(withDefault)).toEqual(Buffer.from(withExplicit))
    })
  })
})
