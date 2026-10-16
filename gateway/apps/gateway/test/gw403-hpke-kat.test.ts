import { describe, expect, it } from 'vitest'
import { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } from '@hpke/core'
import katVectors from '../../../packages/theme-sdk/test/vectors/hpke-kat.json' with { type: 'json' }
import { buildAad, type SealedRequest } from '../src/services/crypto.js'

/**
 * GW-403 KAT 回归（ADR D9 / AE-10）：固定密钥 + 固定 ts/nonce 的向量快照，
 * Node 与浏览器运行时对同一向量产出字节级一致的 enc/ct，并跨运行时解封
 * 对方产出。任何实现/依赖升级导致字节漂移必须显式评审。
 */
const suite = new CipherSuite({
  kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
})

const { recipientJwk, ephemeralJwk, payload, vector } = katVectors as {
  recipientJwk: JsonWebKey
  ephemeralJwk: JsonWebKey
  payload: unknown
  vector: {
    operation: 'login' | 'register' | 'email-code'
    session: string
    kid: string
    publicKey: string
    ts: number
    nonce: string
    aad: string
    enc: string
    ct: string
  }
}

async function fixedEkm(): Promise<CryptoKeyPair> {
  const privateKey = await suite.kem.importKey('jwk', ephemeralJwk, false)
  const publicKey = await suite.kem.importKey(
    'jwk', { kty: 'EC', crv: 'P-256', x: ephemeralJwk.x, y: ephemeralJwk.y }, true,
  )
  return { privateKey, publicKey }
}

describe('GW-403 cross-runtime KAT vectors (ADR D9 / AE-10)', () => {
  it('seals with the fixed ephemeral to byte-identical enc/ct (KAT-1, AE-10)', async () => {
    const recipientPublicKey = await suite.kem.importKey(
      'jwk', { kty: 'EC', crv: 'P-256', x: recipientJwk.x, y: recipientJwk.y }, true,
    )
    const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
    const aad = buildAad(vector.operation, vector.kid, vector.ts, vector.nonce, vector.session)
    // AAD 快照与冻结格式逐字节一致（漂移即回归失败）
    expect(new TextDecoder().decode(aad)).toBe(vector.aad)

    const sender = await suite.createSenderContext({ recipientPublicKey, info, ekm: await fixedEkm() })
    const ct = new Uint8Array(await sender.seal(
      new TextEncoder().encode(JSON.stringify(payload)), aad,
    ))
    const enc = new Uint8Array(sender.enc)
    expect(Buffer.from(enc).toString('base64url')).toBe(vector.enc)
    expect(Buffer.from(ct).toString('base64url')).toBe(vector.ct)
  })

  it('opens the snapshot envelope back to the original payload (KAT-2)', async () => {
    const recipientKey = await suite.kem.importKey('jwk', recipientJwk, false)
    const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
    const aad = buildAad(vector.operation, vector.kid, vector.ts, vector.nonce, vector.session)
    const recipient = await suite.createRecipientContext({
      recipientKey,
      enc: Uint8Array.from(Buffer.from(vector.enc, 'base64url')),
      info,
    })
    const plaintext = await recipient.open(
      Uint8Array.from(Buffer.from(vector.ct, 'base64url')), aad,
    )
    expect(JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(plaintext))).toEqual(payload)
  })

  it('rejects the snapshot envelope under a wrong session AAD (AE-6)', async () => {
    const recipientKey = await suite.kem.importKey('jwk', recipientJwk, false)
    const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
    const wrongAad = buildAad(vector.operation, vector.kid, vector.ts, vector.nonce, 'c2Vzc2lvbi1hYmMxMjM0')
    const recipient = await suite.createRecipientContext({
      recipientKey,
      enc: Uint8Array.from(Buffer.from(vector.enc, 'base64url')),
      info,
    })
    await expect(recipient.open(
      Uint8Array.from(Buffer.from(vector.ct, 'base64url')), wrongAad,
    )).rejects.toThrow()
  })

  it('rejects the snapshot envelope under a wrong operation AAD (AE-4)', async () => {
    const recipientKey = await suite.kem.importKey('jwk', recipientJwk, false)
    const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
    const wrongAad = buildAad('register', vector.kid, vector.ts, vector.nonce, vector.session)
    const recipient = await suite.createRecipientContext({
      recipientKey,
      enc: Uint8Array.from(Buffer.from(vector.enc, 'base64url')),
      info,
    })
    await expect(recipient.open(
      Uint8Array.from(Buffer.from(vector.ct, 'base64url')), wrongAad,
    )).rejects.toThrow()
  })

  it('rejects a one-byte tampered snapshot ct (AE-1)', async () => {
    const recipientKey = await suite.kem.importKey('jwk', recipientJwk, false)
    const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
    const aad = buildAad(vector.operation, vector.kid, vector.ts, vector.nonce, vector.session)
    const tampered = Buffer.from(vector.ct, 'base64url')
    tampered[tampered.length - 1] ^= 0x01
    const recipient = await suite.createRecipientContext({
      recipientKey,
      enc: Uint8Array.from(Buffer.from(vector.enc, 'base64url')),
      info,
    })
    await expect(recipient.open(tampered, aad)).rejects.toThrow()
  })
})
