/** Offline experiment only: one response per closure, with no shared replay ownership. */
import { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } from '@hpke/core'
import { fromBase64url, requestAad, requestDigestInput, responseAad, responseExporterContext,
  toBase64url, validateTimeWindow, type RequestMetadata, type ResponseBinding } from './sc-v2-codec.js'
import { saltedResponseAad, responseSaltPrototype, type SaltedResponseBinding } from './sc-v2-response-salt-codec.js'

export class ExperimentalCryptoFailure extends Error {
  constructor() { super('SC_EXPERIMENT_INVALID'); this.name = 'ExperimentalCryptoFailure' }
}
export type ExperimentalRequest = Readonly<{ metadata: Readonly<RequestMetadata>; enc: string; ct: string }>
export type ExperimentalResponse = Readonly<ResponseBinding & { ct: string }>
export type SaltedExperimentalResponse = Readonly<SaltedResponseBinding & { ct: string }>
const suite = new CipherSuite({ kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm() })
const info = new TextEncoder().encode('txboard.sc-v2-crypto-experiment/1/request')
const saltedInfo = new TextEncoder().encode(`${responseSaltPrototype}/request`)
const fields = ['version', 'mode', 'domain', 'kid', 'operation', 'operation_version', 'request_id',
  'issued_at', 'expires_at', 'nonce', 'origin', 'path']
const typedArray = Object.getPrototypeOf(Uint8Array.prototype) as object
const bufferOf = Object.getOwnPropertyDescriptor(typedArray, 'buffer')!.get!
const lengthOf = Object.getOwnPropertyDescriptor(typedArray, 'byteLength')!.get!
const offsetOf = Object.getOwnPropertyDescriptor(typedArray, 'byteOffset')!.get!
function requireValue(value: unknown): asserts value { if (!value) throw new ExperimentalCryptoFailure() }
function object(value: unknown, keys: string[]): Record<string, unknown> {
  requireValue(value !== null && typeof value === 'object')
  const prototype = Object.getPrototypeOf(value)
  requireValue(prototype === Object.prototype || prototype === null)
  const descriptors = Object.getOwnPropertyDescriptors(value)
  const actual = Reflect.ownKeys(descriptors)
  requireValue(actual.length === keys.length && keys.every(key => Object.hasOwn(descriptors, key)))
  const result: Record<string, unknown> = Object.create(null)
  for (const key of keys) {
    const descriptor = descriptors[key]!
    requireValue(Object.hasOwn(descriptor, 'value') && descriptor.enumerable)
    result[key] = descriptor.value
  }
  return result
}
function metadata(value: unknown): Readonly<RequestMetadata> {
  const snapshot = object(value, fields) as unknown as RequestMetadata
  // The committed codec validates every scalar and rejects unknown encodings.
  requestAad(snapshot)
  return Object.freeze(snapshot)
}
function bytes(value: unknown, max: number): Uint8Array<ArrayBuffer> {
  requireValue(value instanceof Uint8Array)
  const buffer = bufferOf.call(value) as ArrayBuffer
  const length = lengthOf.call(value) as number
  const offset = offsetOf.call(value) as number
  requireValue(buffer instanceof ArrayBuffer && length <= max)
  // New view rejects detached buffers, including detached empty typed arrays.
  const view = new Uint8Array(buffer, offset, length)
  const result = new Uint8Array(length)
  Uint8Array.prototype.set.call(result, view)
  return result
}
function ciphertext(value: unknown): Uint8Array<ArrayBuffer> {
  requireValue(typeof value === 'string' && value.length >= 22 && value.length <= 4096)
  const size = Math.floor(value.length * 3 / 4)
  requireValue(size >= 16 && size <= 3072)
  return bytes(fromBase64url(value, size), 3072)
}
function key(value: CryptoKey, type: 'public' | 'private'): void {
  requireValue(value instanceof CryptoKey && value.type === type && value.algorithm.name === 'ECDH'
    && (value.algorithm as EcKeyAlgorithm).namedCurve === 'P-256'
    && (type === 'public' || value.usages.includes('deriveBits')))
}
function current(value: RequestMetadata): void { validateTimeWindow(value, Math.floor(Date.now() / 1000)) }
async function digest(value: RequestMetadata, enc: Uint8Array, ct: Uint8Array): Promise<string> {
  const input = bytes(requestDigestInput(value, enc, ct), 4096)
  return toBase64url(new Uint8Array(await crypto.subtle.digest('SHA-256', input)))
}
type Exporter = { export(context: Uint8Array, length: number): Promise<ArrayBuffer> }
async function responseMaterial(context: Exporter, requestDigest: string, usage: 'encrypt' | 'decrypt') {
  const rawKey = new Uint8Array(await context.export(responseExporterContext(requestDigest, 'key'), 32))
  try {
    const key = await crypto.subtle.importKey('raw', rawKey, 'AES-GCM', false, [usage])
    const nonce = new Uint8Array(await context.export(responseExporterContext(requestDigest, 'nonce'), 12))
    return { key, nonce }
  } finally { rawKey.fill(0) }
}

async function saltedMaterial(context: Exporter, enc: Uint8Array, responseNonce: string, usage: 'encrypt' | 'decrypt') {
  const exported = new Uint8Array(await context.export(new TextEncoder().encode(`${responseSaltPrototype}/response`), 32))
  let rawKey: Uint8Array<ArrayBuffer> | undefined
  try {
    const hkdf = await crypto.subtle.importKey('raw', exported, 'HKDF', false, ['deriveBits'])
    const salt = new Uint8Array(97)
    salt.set(enc); salt.set(fromBase64url(responseNonce, 32), 65)
    const parameters = { name: 'HKDF', hash: 'SHA-256', salt }
    rawKey = new Uint8Array(await crypto.subtle.deriveBits({ ...parameters, info: new TextEncoder().encode('key') }, hkdf, 256))
    const key = await crypto.subtle.importKey('raw', rawKey, 'AES-GCM', false, [usage])
    const nonce = new Uint8Array(await crypto.subtle.deriveBits({ ...parameters, info: new TextEncoder().encode('nonce') }, hkdf, 96))
    return { key, nonce }
  } finally { exported.fill(0); rawKey?.fill(0) }
}

function responseBytes(snapshot: RequestMetadata, binding: ResponseBinding | SaltedResponseBinding, salted: boolean) {
  return bytes(salted ? saltedResponseAad(snapshot, binding as SaltedResponseBinding) : responseAad(snapshot, binding), 4096)
}

/** Fresh HPKE sender. Trusted key and caller metadata provide no actor authorization. */
async function createRequest(publicKey: CryptoKey, inputMetadata: RequestMetadata, plaintext: Uint8Array, salted: boolean) {
  let copy: Uint8Array<ArrayBuffer> | undefined
  try {
    const snapshot = metadata(inputMetadata)
    copy = bytes(plaintext, 3056); current(snapshot); key(publicKey, 'public')
    let sender: Awaited<ReturnType<typeof suite.createSenderContext>> | undefined = await suite.createSenderContext({ recipientPublicKey: publicKey, info: salted ? saltedInfo : info })
    const ct = new Uint8Array(await sender.seal(copy, requestAad(snapshot)))
    const enc = new Uint8Array(sender.enc)
    const requestDigest = await digest(snapshot, enc, ct)
    current(snapshot)
    const envelope: ExperimentalRequest = Object.freeze({ metadata: snapshot, enc: toBase64url(enc), ct: toBase64url(ct) })
    let spent = false
    return Object.freeze({ envelope, openResponse: async (input: unknown): Promise<Uint8Array> => {
      if (spent) throw new ExperimentalCryptoFailure()
      spent = true // Claim before any argument property access or await.
      const owner = sender; sender = undefined // Spent closures retain no context reference.
      let nonce: Uint8Array<ArrayBuffer> | undefined
      let decrypted: Uint8Array<ArrayBuffer> | undefined
      try {
        current(snapshot)
        requireValue(owner)
        const row = object(input, ['request_digest', 'kind', 'status', ...(salted ? ['response_nonce'] : []), 'ct'])
        const binding = { request_digest: row.request_digest, kind: row.kind, status: row.status,
          ...(salted ? { response_nonce: row.response_nonce } : {}) } as ResponseBinding | SaltedResponseBinding
        const aad = responseBytes(snapshot, binding, salted)
        requireValue(binding.request_digest === requestDigest)
        const ciphertextBytes = ciphertext(row.ct)
        const material = salted ? await saltedMaterial(owner, enc, (binding as SaltedResponseBinding).response_nonce, 'decrypt')
          : await responseMaterial(owner, requestDigest, 'decrypt'); nonce = material.nonce
        decrypted = new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: nonce, additionalData: aad, tagLength: 128 }, material.key, ciphertextBytes))
        current(snapshot)
        const result = new Uint8Array(decrypted); decrypted.fill(0)
        return result
      } catch { decrypted?.fill(0); throw new ExperimentalCryptoFailure() }
      finally { nonce?.fill(0) }
    } })
  } catch { throw new ExperimentalCryptoFailure() }
  finally { copy?.fill(0) }
}

/** No replay claim: a second call for this envelope creates another response owner. */
async function openRequest(trustedKeyPair: CryptoKeyPair, input: unknown, salted: boolean) {
  try {
    const pair = object(trustedKeyPair, ['privateKey', 'publicKey']) as unknown as CryptoKeyPair
    key(pair.privateKey, 'private'); key(pair.publicKey, 'public')
    const row = object(input, ['metadata', 'enc', 'ct'])
    const snapshot = metadata(row.metadata); current(snapshot)
    const enc = bytes(fromBase64url(row.enc, 65), 65), ct = ciphertext(row.ct)
    let recipient: Awaited<ReturnType<typeof suite.createRecipientContext>> | undefined = await suite.createRecipientContext({ recipientKey: pair, enc, info: salted ? saltedInfo : info })
    const opened = new Uint8Array(await recipient.open(ct, requestAad(snapshot)))
    try {
      const requestDigest = await digest(snapshot, enc, ct); current(snapshot)
      const plaintext = new Uint8Array(opened)
      let spent = false
      return Object.freeze({ plaintext, sealResponse: async (inputBytes: Uint8Array, inputBinding: Pick<ResponseBinding, 'kind' | 'status'>): Promise<ExperimentalResponse> => {
        if (spent) throw new ExperimentalCryptoFailure()
        spent = true // Per-closure only; shared ownership MUST precede any network consumer.
        const owner = recipient; recipient = undefined
        let copy: Uint8Array<ArrayBuffer> | undefined, nonce: Uint8Array<ArrayBuffer> | undefined
        try {
          current(snapshot)
          requireValue(owner)
          const row = object(inputBinding, ['kind', 'status'])
          copy = bytes(inputBytes, 3056)
          const binding = { request_digest: requestDigest, kind: row.kind, status: row.status,
            ...(salted ? { response_nonce: toBase64url(crypto.getRandomValues(new Uint8Array(32))) } : {}) } as ResponseBinding | SaltedResponseBinding
          const aad = responseBytes(snapshot, binding, salted)
          const material = salted ? await saltedMaterial(owner, enc, (binding as SaltedResponseBinding).response_nonce, 'encrypt')
            : await responseMaterial(owner, requestDigest, 'encrypt'); nonce = material.nonce
          const encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: nonce, additionalData: aad, tagLength: 128 }, material.key, copy)
          current(snapshot)
          return Object.freeze({ ...binding, ct: toBase64url(new Uint8Array(encrypted)) })
        } catch { throw new ExperimentalCryptoFailure() }
        finally { copy?.fill(0); nonce?.fill(0) }
      } })
    } finally { opened.fill(0) }
  } catch { throw new ExperimentalCryptoFailure() }
}

/** Unchanged B6.2 labels and deterministic response derivation. No network adoption. */
export function createExperimentalRequest(publicKey: CryptoKey, metadata: RequestMetadata, plaintext: Uint8Array) {
  return createRequest(publicKey, metadata, plaintext, false)
}
export function openExperimentalRequest(keyPair: CryptoKeyPair, input: unknown) {
  return openRequest(keyPair, input, false)
}
/** Separate offline response-salt candidate; no shared business replay claim. */
export function createSaltedExperimentalRequest(publicKey: CryptoKey, metadata: RequestMetadata, plaintext: Uint8Array) {
  return createRequest(publicKey, metadata, plaintext, true)
}
export function openSaltedExperimentalRequest(keyPair: CryptoKeyPair, input: unknown) {
  return openRequest(keyPair, input, true).then(opened => Object.freeze({
    plaintext: opened.plaintext,
    sealResponse: opened.sealResponse as (bytes: Uint8Array, binding: Pick<ResponseBinding, 'kind' | 'status'>) => Promise<SaltedExperimentalResponse>,
  }))
}
