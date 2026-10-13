import { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } from '@hpke/core'

export type KeyVersion = {
  kid: string
  publicKey: string
  scope: 'login-only' | 'account-workflows'
  expiresAt?: number
}

export type KeyDiscovery = {
  protocol: 'HPKE-RFC9180'
  suite: 'DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM'
  keys: KeyVersion[]
}

const info = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')
const aadPrefix = 'txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/'
const suite = new CipherSuite({
  kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
})
function fromB64(value: string): Uint8Array {
  if (!/^[A-Za-z0-9_-]+$/.test(value) || value.length > 500) throw new Error('Invalid published HPKE key')
  const input = value.replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(input)
  return Uint8Array.from(raw, x => x.charCodeAt(0))
}
function toB64(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}
export type CryptoOperation = 'login' | 'register' | 'email-code' | 'password-reset' | 'session-revoke' | 'session-logout'

/**
 * 单步操作的 session 段占位（GW-403 D7）：ASCII 连字符，字节格式固定——
 * 段与换行不得省略，否则 AAD 字节位移与多步流程（GW-310）不兼容。
 */
export const singleShotSession = '-'

/** 从发现响应中选择用于指定操作的最佳密钥版本。 */
function selectKey(discovery: KeyDiscovery, operation: CryptoOperation): KeyVersion {
  if (discovery.protocol !== 'HPKE-RFC9180'
    || discovery.suite !== 'DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM'
    || !Array.isArray(discovery.keys) || discovery.keys.length === 0) {
    throw new Error('Unsupported Gateway encryption parameters')
  }
  const now = Date.now()
  const valid = discovery.keys.filter(k =>
    /^[a-f0-9]{24}$/.test(k.kid)
    && ['login-only', 'account-workflows'].includes(k.scope)
    && (operation === 'login' || k.scope === 'account-workflows')
    && (k.expiresAt === undefined || k.expiresAt > now),
  )
  if (valid.length === 0) throw new Error('No valid encryption key available')
  // 优先使用第一个（当前密钥）
  return valid[0]!
}

export async function encryptForOperation(
  key: KeyDiscovery,
  data: unknown,
  operation: CryptoOperation,
  session: string = singleShotSession,
) {
  const selected = selectKey(key, operation)
  const publicKey = await suite.kem.deserializePublicKey(fromB64(selected.publicKey))
  const sender = await suite.createSenderContext({ recipientPublicKey: publicKey, info })
  const ts = Date.now()
  const nonce = toB64(crypto.getRandomValues(new Uint8Array(16)))
  // 冻结的六段 AAD（GW-403 D7），与 Gateway 侧字节级一致。
  const aad = new TextEncoder().encode(
    aadPrefix + operation + '\n' + selected.kid + '\n' + ts + '\n' + nonce + '\n' + session,
  )
  const ciphertext = await sender.seal(new TextEncoder().encode(JSON.stringify(data)), aad)
  return { kid: selected.kid, ts, nonce, enc: toB64(new Uint8Array(sender.enc)), ct: toB64(new Uint8Array(ciphertext)) }
}

/** Historical public helper remains compatible for encrypted login. */
export const encryptLoginPayload = (key: KeyDiscovery, data: unknown) => encryptForOperation(key, data, 'login')
