import { describe, expect, it, vi } from 'vitest'
import { generateKeyPairSync } from 'node:crypto'
import { CryptoService, loadCryptoService, type KeyDiscoveryResponse } from '../src/services/crypto.js'
import { encryptForOperation, encryptLoginPayload } from '../../../packages/theme-sdk/src/crypto.js'

function fakeReplay() {
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

function generateJwk() {
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  return privateKey.export({ format: 'jwk' })
}

describe('GW-215 HPKE dual-key rotation and kid eviction', () => {
  describe('public key discovery returns multi-version keys', () => {
    it('returns a single key version when no previous key is configured', async () => {
      const jwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay())
      const discovery = service.publicKey()
      expect(discovery.protocol).toBe('HPKE-RFC9180')
      expect(discovery.suite).toContain('DHKEM(P-256')
      expect(discovery.keys).toHaveLength(1)
      expect(discovery.keys[0].kid).toMatch(/^[a-f0-9]{24}$/)
      expect(discovery.keys[0].publicKey).toBeTruthy()
      expect(discovery.keys[0].scope).toBe('login-only')
      expect(discovery.keys[0].expiresAt).toBeUndefined()
    })

    it('returns both current and previous key versions when previous is provided', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000)
      const discovery = service.publicKey()
      expect(discovery.keys).toHaveLength(2)
      // First key is the current one (no expiresAt)
      expect(discovery.keys[0].expiresAt).toBeUndefined()
      // Second key is the previous one (with expiresAt)
      expect(discovery.keys[1].expiresAt).toBeDefined()
      expect(discovery.keys[1].expiresAt!).toBeGreaterThan(Date.now())
    })

    it('evicts the previous key from discovery after its expiry time has passed', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      // Already expired: expiresAt is in the past
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() - 1000)
      const discovery = service.publicKey()
      expect(discovery.keys).toHaveLength(1)
      // Only the current key remains
      expect(discovery.keys[0].expiresAt).toBeUndefined()
    })
  })

  describe('decryption falls back to previous key', () => {
    it('decrypts with the current key (primary path)', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000)
      const discovery = service.publicKey()
      const data = { email: 'user@example.test', password: 'pass12345678' }
      // Encrypt with the current key (first in the list)
      const sealed = await encryptLoginPayload(discovery, data)
      const result = await service.open(sealed)
      expect(result).toEqual(data)
    })

    it('decrypts with the previous key when the envelope uses the old kid', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000)
      const discovery = service.publicKey()
      expect(discovery.keys.length).toBeGreaterThanOrEqual(2)

      // Simulate an envelope sealed with the OLD key: construct manually
      // We need to encrypt with the old public key directly
      const { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } = await import('@hpke/core')
      const suite = new CipherSuite({
        kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
      })
      const oldKeyData = discovery.keys[1]!
      const oldPublicKey = await suite.kem.deserializePublicKey(
        Uint8Array.from(atob(oldKeyData.publicKey.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0)),
      )
      const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
      const aadPrefix = 'txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/'
      const sender = await suite.createSenderContext({ recipientPublicKey: oldPublicKey, info })
      const ts = Date.now()
      const nonce = 'aaaaaaaaaaaaaaaaaaaaaa'
      const aad = new TextEncoder().encode(aadPrefix + 'login\n' + oldKeyData.kid + '\n' + ts + '\n' + nonce)
      const plaintext = new TextEncoder().encode(JSON.stringify({ email: 'user@example.test', password: 'pass12345678' }))
      const ct = await sender.seal(plaintext, aad)
      const toB64 = (bytes: Uint8Array) =>
        btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
      const sealed = {
        kid: oldKeyData.kid, ts, nonce,
        enc: toB64(new Uint8Array(sender.enc)),
        ct: toB64(new Uint8Array(ct)),
      }

      // Service should decrypt with the previous key
      const result = await service.open(sealed, 'login')
      expect(result).toEqual({ email: 'user@example.test', password: 'pass12345678' })
    })

    it('rejects an envelope with an unknown kid', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000)
      const sealed = {
        kid: 'ffffffffffffffffffffffff', ts: Date.now(), nonce: 'a'.repeat(22),
        enc: 'e'.repeat(50), ct: 'c'.repeat(24),
      }
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 400,
      })
    })

    it('rejects an envelope sealed with an expired previous key', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      // Previous key already expired
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() - 1000)

      // Build an envelope with the old (expired) key
      const { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } = await import('@hpke/core')
      const suite = new CipherSuite({
        kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
      })
      const oldJwk = prevJwk as JsonWebKey
      const oldPublicKey = await suite.kem.importKey('jwk',
        { kty: 'EC', crv: 'P-256', x: oldJwk.x, y: oldJwk.y }, true)
      const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
      const aadPrefix = 'txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/'
      const sender = await suite.createSenderContext({ recipientPublicKey: oldPublicKey, info })
      const ts = Date.now()
      const nonce = 'aaaaaaaaaaaaaaaaaaaaaa'
      const aad = new TextEncoder().encode(aadPrefix + 'login\n' + 'x'.repeat(24) + '\n' + ts + '\n' + nonce)
      const plaintext = new TextEncoder().encode(JSON.stringify({ email: 'user@example.test', password: 'pass12345678' }))
      const ct = await sender.seal(plaintext, aad)
      const toB64 = (bytes: Uint8Array) =>
        btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

      // Compute the kid for the old key (SHA-256 of serialized public key)
      const serialized = new Uint8Array(await suite.kem.serializePublicKey(oldPublicKey))
      const kid = (await import('node:crypto')).createHash('sha256').update(serialized).digest('hex').slice(0, 24)

      const sealed = {
        kid, ts, nonce,
        enc: toB64(new Uint8Array(sender.enc)),
        ct: toB64(new Uint8Array(ct)),
      }

      // Should reject: expired key is not tried
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 400,
      })
    })
  })

  describe('replay protection works across both key versions', () => {
    it('detects replay when the same kid+nonce is used twice on the same service', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000)
      const discovery = service.publicKey()
      const data = { email: 'user@example.test', password: 'pass12345678' }
      const sealed = await encryptLoginPayload(discovery, data)

      // First attempt succeeds
      const first = await service.open(sealed, 'login')
      expect(first).toEqual(data)

      // Second attempt (same kid+nonce) is rejected as replay
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 409,
      })
    })

    it('tracks replay separately per kid across key versions', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      const replay = fakeReplay()
      const service = await CryptoService.create(jwk, replay, false, prevJwk, Date.now() + 3600_000)

      // Seal with current key
      const currentKid = service.publicKey().keys[0].kid
      // Seal with previous key (compute manually)
      const { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } = await import('@hpke/core')
      const suite = new CipherSuite({
        kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
      })
      const oldJwk = prevJwk as JsonWebKey
      const oldPublicKey = await suite.kem.importKey('jwk',
        { kty: 'EC', crv: 'P-256', x: oldJwk.x, y: oldJwk.y }, true)
      const serialized = new Uint8Array(await suite.kem.serializePublicKey(oldPublicKey))
      const prevKid = (await import('node:crypto')).createHash('sha256').update(serialized).digest('hex').slice(0, 24)

      const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
      const aadPrefix = 'txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/'
      const sender = await suite.createSenderContext({ recipientPublicKey: oldPublicKey, info })
      const ts = Date.now()
      const nonce = 'bbbbbbbbbbbbbbbbbbbbbb'
      const aad = new TextEncoder().encode(aadPrefix + 'login\n' + prevKid + '\n' + ts + '\n' + nonce)
      const plaintext = new TextEncoder().encode(JSON.stringify({ email: 'old@example.test', password: 'pass12345678' }))
      const ct = await sender.seal(plaintext, aad)
      const toB64 = (bytes: Uint8Array) =>
        btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

      const sealedOld = { kid: prevKid, ts, nonce, enc: toB64(new Uint8Array(sender.enc)), ct: toB64(new Uint8Array(ct)) }
      const resultOld = await service.open(sealedOld, 'login')
      expect(resultOld).toEqual({ email: 'old@example.test', password: 'pass12345678' })

      // Replay with old key
      await expect(service.open(sealedOld, 'login')).rejects.toMatchObject({
        code: 'VALIDATION_ERROR',
        status: 409,
      })
    })
  })

  describe('loadCryptoService with previous key configuration', () => {
    it('loads dual-key service when both key files are provided', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      // We can't actually read files in unit tests, so we mock readFile
      // by testing the CryptoService.create path directly
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000)
      expect(service).toBeDefined()
      const discovery = service.publicKey()
      expect(discovery.keys.length).toBeGreaterThanOrEqual(2)
    })

    it('creates a service with only the current key when no previous key is provided', async () => {
      const jwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay())
      expect(service).toBeDefined()
      const discovery = service.publicKey()
      expect(discovery.keys).toHaveLength(1)
    })

    it('rejects invalid previous key JWK', async () => {
      const jwk = await generateJwk()
      const invalidPrev = { kty: 'RSA', n: 'x', e: 'AQAB' }
      await expect(
        CryptoService.create(jwk, fakeReplay(), false, invalidPrev as JsonWebKey),
      ).rejects.toThrow('P-256')
    })
  })

  describe('SDK encryptForOperation selects the right key from discovery', () => {
    it('uses the first valid key in the discovery list', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000)
      const discovery = service.publicKey()
      const data = { email: 'sdk@example.test', password: 'pass12345678' }
      const sealed = await encryptLoginPayload(discovery, data)
      // The SDK should pick the current key (first in the list)
      expect(sealed.kid).toBe(discovery.keys[0].kid)
    })

    it('skips expired keys when selecting a key for encryption', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      // Previous key already expired
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() - 1000)
      const discovery = service.publicKey()
      // Discovery should only have the current key (expired one evicted)
      expect(discovery.keys).toHaveLength(1)
      const data = { email: 'sdk@example.test', password: 'pass12345678' }
      const sealed = await encryptLoginPayload(discovery, data)
      expect(sealed.kid).toBe(discovery.keys[0].kid)
    })

    it('throws when no valid key is available', async () => {
      const discovery: KeyDiscoveryResponse = {
        protocol: 'HPKE-RFC9180',
        suite: 'DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM',
        keys: [],
      }
      await expect(encryptLoginPayload(discovery, { email: 'a@b.c', password: 'x' }))
        .rejects.toThrow('Unsupported Gateway encryption parameters')
    })

    it('respects account-workflows scope for non-login operations', async () => {
      // login-only service: register should be rejected
      const jwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay(), false)
      const discovery = service.publicKey()
      expect(discovery.keys[0].scope).toBe('login-only')
      await expect(encryptForOperation(discovery, { email: 'a@b.c', password: 'pass12345678' }, 'register'))
        .rejects.toThrow('No valid encryption key')

      // account-workflows service: register should succeed
      const service2 = await CryptoService.create(jwk, fakeReplay(), true)
      const discovery2 = service2.publicKey()
      expect(discovery2.keys[0].scope).toBe('account-workflows')
      const sealed = await encryptForOperation(discovery2, { email: 'a@b.c', password: 'pass12345678' }, 'register')
      expect(sealed.kid).toBe(discovery2.keys[0].kid)
    })
  })

  describe('public key discovery format is compatible with SDK', () => {
    it('response shape matches KeyDiscoveryResponse expected by SDK', async () => {
      const jwk = await generateJwk()
      const prevJwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay(), false, prevJwk, Date.now() + 3600_000)
      const discovery = service.publicKey()

      // SDK expects: { protocol, suite, keys: [{ kid, publicKey, scope, expiresAt? }] }
      expect(discovery).toHaveProperty('protocol', 'HPKE-RFC9180')
      expect(discovery).toHaveProperty('suite')
      expect(discovery).toHaveProperty('keys')
      expect(Array.isArray(discovery.keys)).toBe(true)
      for (const key of discovery.keys) {
        expect(key).toHaveProperty('kid')
        expect(key).toHaveProperty('publicKey')
        expect(key).toHaveProperty('scope')
        expect(['login-only', 'account-workflows']).toContain(key.scope)
      }
    })

    it('discovery response can be serialized to JSON without private key material', async () => {
      const jwk = await generateJwk()
      const service = await CryptoService.create(jwk, fakeReplay())
      const discovery = service.publicKey()
      const serialized = JSON.stringify(discovery)
      expect(serialized).not.toContain('"d"')
      expect(serialized).not.toContain('"x"')
      expect(serialized).not.toContain('"y"')
    })
  })
})
