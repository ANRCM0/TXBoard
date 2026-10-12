/** Experimental metadata bytes only. This module performs no encryption or I/O. */
export const prototype = 'txboard.sc-v2-codec/1' as const
export const suiteIds = [0x0010, 0x0001, 0x0002] as const
export type Domain = 'user' | 'admin'
export type RequestMetadata = {
  version: 2
  mode: 0
  domain: Domain
  kid: string
  operation: string
  operation_version: string
  request_id: string
  issued_at: number
  expires_at: number
  nonce: string
  origin: string
  path: string
}
export type ResponseBinding = {
  request_digest: string
  kind: 'result' | 'error'
  status: number
}

export class CodecFailure extends Error {
  constructor() { super('SC_CODEC_INVALID'); this.name = 'CodecFailure' }
}
function requireValue(value: unknown): asserts value {
  if (!value) throw new CodecFailure()
}
function exact(value: unknown, keys: string[]): asserts value is Record<string, unknown> {
  requireValue(value !== null && typeof value === 'object' && !Array.isArray(value))
  const actual = Object.keys(value)
  requireValue(actual.length === keys.length && keys.every(key => Object.hasOwn(value, key)))
}
const encoder = new TextEncoder()
const metadataKeys = ['version', 'mode', 'domain', 'kid', 'operation', 'operation_version',
  'request_id', 'issued_at', 'expires_at', 'nonce', 'origin', 'path']

export function toBase64url(bytes: Uint8Array): string {
  // Chunking avoids argument-stack overflow; protocol fields remain bounded below.
  let text = ''
  for (let offset = 0; offset < bytes.length; offset += 8192) {
    text += String.fromCharCode(...bytes.subarray(offset, offset + 8192))
  }
  return btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}
export function fromBase64url(value: unknown, size: number): Uint8Array {
  requireValue(Number.isSafeInteger(size) && size >= 1 && size <= 65535)
  requireValue(typeof value === 'string' && /^[A-Za-z0-9_-]+$/.test(value)
    && value.length === Math.ceil(size * 4 / 3))
  let text: string
  try { text = atob(value.replace(/-/g, '+').replace(/_/g, '/')) }
  catch { throw new CodecFailure() }
  const result = Uint8Array.from(text, char => char.charCodeAt(0))
  requireValue(result.length === size && toBase64url(result) === value)
  return result
}
function hex(value: unknown, size: number): Uint8Array {
  requireValue(typeof value === 'string' && value.length === size * 2
    && new RegExp(`^[0-9a-f]{${size * 2}}$`).test(value))
  return Uint8Array.from(value.match(/../g)!, pair => Number.parseInt(pair, 16))
}
function uint(value: unknown, bytes: 2 | 8): Uint8Array {
  requireValue(typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
    && (bytes === 8 || value <= 65535))
  const result = new Uint8Array(bytes)
  const view = new DataView(result.buffer)
  if (bytes === 8) view.setBigUint64(0, BigInt(value), false)
  else view.setUint16(0, value, false)
  return result
}
function ascii(value: unknown, expression: RegExp, max: number): Uint8Array {
  requireValue(typeof value === 'string' && value.length <= max && !/[^\x21-\x7e]/.test(value) && expression.test(value))
  return encoder.encode(value)
}
/** Fixed order, uint16 big-endian byte length followed by bytes, including labels. */
function tuple(fields: Uint8Array[]): Uint8Array {
  let length = 0
  for (const field of fields) { requireValue(field.length <= 65535); length += 2 + field.length }
  requireValue(length <= 4096)
  const result = new Uint8Array(length)
  let offset = 0
  for (const field of fields) {
    result.set(uint(field.length, 2), offset)
    result.set(field, offset + 2)
    offset += field.length + 2
  }
  return result
}
function metadataFields(value: unknown): Uint8Array[] {
  exact(value, metadataKeys)
  requireValue(value.version === 2 && value.mode === 0 && (value.domain === 'user' || value.domain === 'admin'))
  const kid = hex(value.kid, 32)
  const operation = ascii(value.operation, /^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*){1,7}$/, 128)
  const operationVersion = ascii(value.operation_version, /^[1-9][0-9]{0,5}$/, 6)
  requireValue(typeof value.request_id === 'string' && value.request_id.length === 36
    && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value.request_id))
  const requestId = hex(value.request_id.replace(/-/g, ''), 16)
  const issued = uint(value.issued_at, 8), expires = uint(value.expires_at, 8)
  requireValue((value.issued_at as number) > 0 && (value.expires_at as number) > (value.issued_at as number)
    && (value.expires_at as number) - (value.issued_at as number) <= 60)
  const nonce = fromBase64url(value.nonce, 32)
  const origin = ascii(value.origin, /^https:\/\/[a-z0-9.:-]+$/, 255)
  let parsed: URL
  try { parsed = new URL(value.origin as string) } catch { throw new CodecFailure() }
  // No implicit default-port/path/case/IDNA normalization or user-selected target.
  requireValue(parsed.origin === value.origin && parsed.protocol === 'https:' && !parsed.username
    && !parsed.password && !parsed.search && !parsed.hash && parsed.pathname === '/' && parsed.hostname.length > 0)
  requireValue(value.path === `/gateway/v2/channel/${value.domain}`)
  return [uint(2, 2), uint(0, 2), ...suiteIds.map(id => uint(id, 2)), encoder.encode(value.domain),
    kid, operation, operationVersion, requestId, issued, expires, nonce, origin, encoder.encode(value.path)]
}

export function requestAad(metadata: RequestMetadata): Uint8Array {
  return tuple([encoder.encode(`${prototype}/request-aad`), ...metadataFields(metadata)])
}
export function responseAad(metadata: RequestMetadata, binding: ResponseBinding): Uint8Array {
  exact(binding, ['request_digest', 'kind', 'status'])
  const digest = fromBase64url(binding.request_digest, 32)
  requireValue(binding.kind === 'result' || binding.kind === 'error')
  requireValue(typeof binding.status === 'number' && Number.isSafeInteger(binding.status)
    && binding.status >= 200 && binding.status <= 599
    && (binding.kind === 'result' ? binding.status < 300 : binding.status >= 300))
  return tuple([encoder.encode(`${prototype}/response-aad`), ...metadataFields(metadata), digest,
    encoder.encode(binding.kind), uint(binding.status, 2)])
}
/** Digest input binds parsed metadata, actual HPKE encapsulation and ciphertext. */
export function requestDigestInput(metadata: RequestMetadata, enc: Uint8Array, ciphertext: Uint8Array): Uint8Array {
  requireValue(enc instanceof Uint8Array && enc.length === 65 && enc[0] === 4)
  requireValue(ciphertext instanceof Uint8Array && ciphertext.length >= 16 && ciphertext.length <= 3072)
  // Elliptic-curve membership/authentication remains the HPKE implementation's job.
  return tuple([encoder.encode(`${prototype}/request-digest`), requestAad(metadata), enc, ciphertext])
}
/** Exporter labels are separate and bounded to the existing library's 128-byte input. */
export function responseExporterContext(requestDigest: string, purpose: 'key' | 'nonce'): Uint8Array {
  requireValue(purpose === 'key' || purpose === 'nonce')
  return tuple([encoder.encode(`${prototype}/response/${purpose}`), fromBase64url(requestDigest, 32)])
}

/** Time-window admission only; key trust, shared replay and actor authorization are separate. */
export function validateTimeWindow(metadata: RequestMetadata, now: number): void {
  metadataFields(metadata)
  requireValue(Number.isSafeInteger(now) && now > 0 && now >= metadata.issued_at - 5 && now < metadata.expires_at)
}
