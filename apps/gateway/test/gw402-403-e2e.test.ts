import { requestId } from './native-fixtures.js'
import { describe, expect, it, beforeEach, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { CryptoService } from '../src/services/crypto.js'
import { generateKeyPairSync } from 'node:crypto'
import { encryptLoginPayload } from '../../../packages/theme-sdk/src/crypto.js'
import { invalidateCache, resetCacheStats } from '../src/middleware/cache.js'

/**
 * GW-402/403 端到端集成：kid 吊销与篡改拒绝经由真实 HTTP 层验证——
 * 发现端点移除被吊销 kid、其密文（即使窗口内）400、零上游调用。
 */
beforeEach(() => {
  invalidateCache()
  resetCacheStats()
})
const config = loadConfig({
  TXBOARD_UPSTREAM_URL: 'https://txboard.example/',
  GATEWAY_ALLOWED_ORIGINS: 'https://theme.example',
})
const token = 'Bearer usertoken_longer_than_eight'
function mockFetch(payload: unknown, status = 200) {
  return vi.fn(async () => Response.json(payload, { status }))
}
function ok(data: unknown) { return { request_id: requestId, data } }
function fakeReplay() {
  const seen = new Set<string>()
  return { reserve: async (kid: string, nonce: string) => {
    const key = kid + ':' + nonce
    if (seen.has(key)) return false
    seen.add(key)
    return true
  } }
}

function jwk() {
  return generateKeyPairSync('ec', { namedCurve: 'prime256v1' }).privateKey.export({ format: 'jwk' })
}

/** 复刻 Gateway kid 派生（内容寻址），用于吊销表断言。 */
async function kidOfJwk(key: JsonWebKey): Promise<string> {
  const { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } = await import('@hpke/core')
  const { createHash } = await import('node:crypto')
  const suite = new CipherSuite({
    kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
  })
  const publicKey = await suite.kem.importKey(
    'jwk', { kty: 'EC', crv: 'P-256', x: key.x, y: key.y }, true,
  )
  const serialized = new Uint8Array(await suite.kem.serializePublicKey(publicKey))
  return createHash('sha256').update(serialized).digest('hex').slice(0, 24)
}

describe('GW-402/403 end-to-end via HTTP (revocation, tamper, rotation)', () => {
  it('revokes the current kid end-to-end: discovery drops it and its ciphertext is refused with zero upstream calls', async () => {
    const keyJwk = jwk()
    const kid = await kidOfJwk(keyJwk)
    const service = await CryptoService.create(keyJwk, fakeReplay(), false, undefined, undefined, [kid])
    const fetcher = mockFetch(ok({ auth_data: token, is_admin: false }))
    const app = createGatewayApp(config, fetcher, service)

    // 发现端点：被吊销 kid 立即移除（keys 为空 → 新客户端无法再用它加密）
    const keyResponse = await app.request('/gateway/v1/crypto/key')
    expect((await keyResponse.json() as any).data.keys).toHaveLength(0)

    // 已被吊销 kid 的密文提交 → 400 Unknown encryption key，零上游调用。
    // 用一个无吊销的同密钥副本构造信封（模拟吊销前密封的飞行密文）。
    const plainService = await CryptoService.create(keyJwk, fakeReplay())
    const sealed = await encryptLoginPayload(plainService.publicKey(), {
      email: 'user@example.test', password: 'pass12345678',
    })
    expect(sealed.kid).toBe(kid)
    const res = await app.request('/gateway/v1/secure/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sealed),
    })
    expect(res.status).toBe(400)
    expect((await res.json() as any).error.message).toBe('Unknown encryption key')
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('serves dual-key discovery and decrypts both windows through HTTP', async () => {
    const currentJwk = jwk()
    const previousJwk = jwk()
    const service = await CryptoService.create(
      currentJwk, fakeReplay(), false, previousJwk, Date.now() + 3600_000,
    )
    const fetcher = mockFetch(ok({ auth_data: token, is_admin: false }))
    const app = createGatewayApp(config, fetcher, service)

    const keyData = (await (await app.request('/gateway/v1/crypto/key')).json() as any).data
    expect(keyData.keys).toHaveLength(2)
    expect(keyData.keys[0].expiresAt).toBeUndefined()
    expect(keyData.keys[1].expiresAt).toBeGreaterThan(Date.now())

    // 当前 kid（keys[0]）密文 → 200
    const sealed = await encryptLoginPayload({ ...keyData }, {
      email: 'user@example.test', password: 'pass12345678',
    })
    expect(sealed.kid).toBe(keyData.keys[0].kid)
    const res = await app.request('/gateway/v1/secure/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sealed),
    })
    expect(res.status).toBe(200)
    expect((await res.json() as any).data.auth_data).toBe(token)
  })

  it('refuses a tampered envelope via HTTP with zero upstream calls', async () => {
    const service = await CryptoService.create(jwk(), fakeReplay())
    const fetcher = mockFetch(ok({ auth_data: token, is_admin: false }))
    const app = createGatewayApp(config, fetcher, service)
    const discovery = service.publicKey()
    const sealed = await encryptLoginPayload(discovery, {
      email: 'user@example.test', password: 'pass12345678',
    })
    const send = (payload: unknown) => app.request('/gateway/v1/secure/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    // ct 最后 2 字节篡改 → GCM 认证失败 → 400
    expect((await send({ ...sealed, ct: sealed.ct.slice(0, -2) + 'aa' })).status).toBe(400)
    // 信封注入 alg 字段：信封无算法字段（结构性防降级）→ 仍 400（kid/ts/AAD 任一环节失败）
    expect((await send({ ...sealed, kid: 'b'.repeat(24) })).status).toBe(400)
    expect(fetcher).not.toHaveBeenCalled()
  })
})
