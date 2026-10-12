import { createPrivateKey, createPublicKey, X509Certificate } from 'node:crypto'
import { readFileSync, statSync } from 'node:fs'
import { isAbsolute } from 'node:path'
import { strictInternalJson } from '../adapters/internal-user-v2.js'

export type InternalUserConfig = {
  origin: URL
  servername: 'internal-core.local'
  ca: Buffer
  crl: Buffer
  cert: Buffer
  key: Buffer
  certificate: X509Certificate
}

function file(path: unknown, max: number): Buffer {
  if (typeof path !== 'string' || !isAbsolute(path)) throw new Error()
  const info = statSync(path)
  if (!info.isFile() || info.size > max) throw new Error()
  return readFileSync(path)
}

/** Trusted server-side fixture configuration. Never accept these paths or identity from HTTP input. */
export function loadInternalUserConfig(env: Record<string, string | undefined>): InternalUserConfig | undefined {
  const enabled = env.GATEWAY_INTERNAL_USER_ENABLED
  if (enabled === undefined || enabled === '' || enabled === 'false') return undefined
  try {
    if (enabled !== 'true') throw new Error()
    if (!['local', 'testing'].includes(env.APP_ENV ?? '')) throw new Error()
    const parsed = strictInternalJson(new TextDecoder('utf-8', { fatal: true }).decode(file(env.GATEWAY_INTERNAL_USER_CONFIG_FILE, 8192)))
    const expected = ['origin', 'servername', 'ca_path', 'crl_path', 'cert_path', 'key_path'].sort()
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed) || Object.keys(parsed).sort().join() !== expected.join()) throw new Error()
    const config = parsed as Record<string, unknown>
    if (typeof config.origin !== 'string') throw new Error()
    const origin = new URL(config.origin)
    if (origin.protocol !== 'https:' || !['127.0.0.1', 'internal-core.local'].includes(origin.hostname)
      || origin.origin !== config.origin
      || origin.username || origin.password || origin.pathname !== '/' || origin.hash || origin.search
      || config.servername !== 'internal-core.local') throw new Error()
    const ca = file(config.ca_path, 32768), crl = file(config.crl_path, 32768)
    const cert = file(config.cert_path, 8192), key = file(config.key_path, 8192)
    const certificate = new X509Certificate(cert)
    const caCertificate = new X509Certificate(ca)
    const privateKey = createPrivateKey(key)
    if (!caCertificate.ca || certificate.ca || certificate.subjectAltName !== 'DNS:user-gateway.internal'
      || privateKey.asymmetricKeyType !== 'rsa' || (privateKey.asymmetricKeyDetails?.modulusLength ?? 0) < 2048
      || !certificate.publicKey.export({ type: 'spki', format: 'der' }).equals(createPublicKey(privateKey).export({ type: 'spki', format: 'der' }))
      || Date.now() < Date.parse(certificate.validFrom) || Date.now() >= Date.parse(certificate.validTo)) throw new Error()
    return { origin, servername: 'internal-core.local', ca, crl, cert, key, certificate }
  } catch {
    // Configuration paths, certificate contents and private key errors are never logged/reflected.
    throw new Error('Invalid local/testing Internal User configuration')
  }
}
