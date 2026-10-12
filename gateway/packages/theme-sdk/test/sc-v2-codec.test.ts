import { describe, expect, it } from 'vitest'
import vectors from './vectors/sc-v2-codec.json' with { type: 'json' }
import {
  CodecFailure, fromBase64url, requestAad, requestDigestInput, responseAad,
  responseExporterContext, toBase64url, validateTimeWindow,
  type RequestMetadata, type ResponseBinding,
} from '../src/experimental/sc-v2-codec.js'

const hex = (value: Uint8Array) => Buffer.from(value).toString('hex')
const metadata = vectors.metadata as RequestMetadata
const binding = vectors.binding as ResponseBinding
const enc = Uint8Array.from(Buffer.from(vectors.enc_hex, 'hex'))
const ciphertext = Uint8Array.from(Buffer.from(vectors.ciphertext_hex, 'hex'))

describe('experimental SC-v2 metadata codec', () => {
  it('matches independent Python big-endian length-prefixed vectors', () => {
    expect(hex(requestAad(metadata))).toBe(vectors.request_aad_hex)
    expect(hex(responseAad(metadata, binding))).toBe(vectors.response_aad_hex)
    expect(hex(requestDigestInput(metadata, enc, ciphertext))).toBe(vectors.request_digest_input_hex)
    expect(hex(responseExporterContext(binding.request_digest, 'key'))).toBe(vectors.response_key_context_hex)
    expect(hex(responseExporterContext(binding.request_digest, 'nonce'))).toBe(vectors.response_nonce_context_hex)
  })
  it('does not depend on JSON object field order', () => {
    const reordered = Object.fromEntries(Object.entries(metadata).reverse()) as RequestMetadata
    expect(requestAad(reordered)).toEqual(requestAad(metadata))
  })
  it('binds both domains and every metadata field and response direction', () => {
    const baseline = requestAad(metadata)
    const alternatives: RequestMetadata[] = [
      { ...metadata, domain: 'admin', path: '/gateway/v2/channel/admin' },
      { ...metadata, kid: 'ab'.repeat(32) }, { ...metadata, operation: 'user.order.get' },
      { ...metadata, operation_version: '2' },
      { ...metadata, request_id: '12345678-1234-4234-9234-123456789abc' },
      { ...metadata, issued_at: metadata.issued_at + 1 },
      { ...metadata, expires_at: metadata.expires_at - 1 },
      { ...metadata, nonce: toBase64url(new Uint8Array(32).fill(1)) },
      { ...metadata, origin: 'https://another.example.test' },
    ]
    for (const alternative of alternatives) expect(requestAad(alternative)).not.toEqual(baseline)
    expect(responseAad(metadata, binding)).not.toEqual(baseline)
    expect(responseAad(metadata, { ...binding, kind: 'error', status: 403 })).not.toEqual(responseAad(metadata, binding))
    expect(responseAad(metadata, { ...binding, status: 201 })).not.toEqual(responseAad(metadata, binding))
    expect(responseAad(metadata, { ...binding, request_digest: toBase64url(new Uint8Array(32)) })).not.toEqual(responseAad(metadata, binding))
    expect(responseExporterContext(binding.request_digest, 'key')).not.toEqual(responseExporterContext(binding.request_digest, 'nonce'))
    expect(responseExporterContext(binding.request_digest, 'key').length).toBeLessThanOrEqual(128)
  })
  it('rejects absent/null/unknown fields, alternate versions and ambiguous text encodings', () => {
    const absent = { ...metadata } as Partial<RequestMetadata>; delete absent.nonce
    const invalid: unknown[] = [null, [], absent, { ...metadata, unknown: true },
      { ...metadata, mode: 1 }, { ...metadata, version: 1 }, { ...metadata, domain: 'User' },
      { ...metadata, kid: metadata.kid.toUpperCase() }, { ...metadata, kid: 'a'.repeat(63) },
      { ...metadata, kid: metadata.kid + '\n' }, { ...metadata, request_id: metadata.request_id + '\n' },
      { ...metadata, operation: metadata.operation + '\n' }, { ...metadata, operation_version: '1\n' },
      { ...metadata, operation: 'user.资料.get' }, { ...metadata, operation: 'user.profile.get\nadmin.order.get' },
      { ...metadata, operation_version: '01' }, { ...metadata, request_id: metadata.request_id.toUpperCase() },
      { ...metadata, request_id: '12345678-1234-1234-8234-123456789abc' },
      { ...metadata, origin: 'http://gateway.example.test' }, { ...metadata, origin: 'https://Gateway.example.test' },
      { ...metadata, origin: 'https://gateway.example.test:443' }, { ...metadata, origin: 'https://gateway.example.test/' },
      { ...metadata, origin: 'https://u:p@gateway.example.test' }, { ...metadata, origin: 'https://gateway.example.test?x=1' },
      { ...metadata, path: '/gateway/v2/channel/admin' }, { ...metadata, path: '/gateway/v2/channel/user?x=1' },
      { ...metadata, nonce: null }, { ...metadata, nonce: metadata.nonce + '=' },
      { ...metadata, issued_at: '1791770400' }, { ...metadata, issued_at: NaN },
      { ...metadata, issued_at: -1 }, { ...metadata, issued_at: 1.5 },
      { ...metadata, expires_at: Number.MAX_SAFE_INTEGER + 1 },
      { ...metadata, expires_at: metadata.issued_at }, { ...metadata, expires_at: metadata.issued_at + 61 },
    ]
    for (const value of invalid) expect(() => requestAad(value as RequestMetadata)).toThrow(CodecFailure)
  })
  it('rejects noncanonical base64url trailing bits and invalid lengths', () => {
    const zero = toBase64url(new Uint8Array(32))
    expect(fromBase64url(zero, 32)).toEqual(new Uint8Array(32))
    for (const value of [zero.slice(0, -1) + 'B', zero + '=', zero.slice(1), '+'.repeat(43), '', null]) {
      expect(() => fromBase64url(value, 32)).toThrow(CodecFailure)
    }
    expect(() => fromBase64url(zero, 31)).toThrow(CodecFailure)
  })
  it('checks half-open time window and bounded future clock skew', () => {
    validateTimeWindow(metadata, metadata.issued_at - 5)
    validateTimeWindow(metadata, metadata.expires_at - 1)
    for (const now of [metadata.issued_at - 6, metadata.expires_at, NaN, 1.5, 0]) {
      expect(() => validateTimeWindow(metadata, now)).toThrow(CodecFailure)
    }
  })
  it('validates response kind/status and fixed digest, encapsulation and ciphertext bounds', () => {
    for (const value of [{ ...binding, status: 300 }, { ...binding, status: 199 },
      { ...binding, kind: 'error', status: 200 }, { ...binding, extra: false },
      { ...binding, request_digest: metadata.nonce + '=' }]) {
      expect(() => responseAad(metadata, value as ResponseBinding)).toThrow(CodecFailure)
    }
    expect(() => requestDigestInput(metadata, new Uint8Array(64), ciphertext)).toThrow(CodecFailure)
    expect(() => requestDigestInput(metadata, new Uint8Array(65), ciphertext)).toThrow(CodecFailure)
    expect(() => requestDigestInput(metadata, enc, new Uint8Array(15))).toThrow(CodecFailure)
    expect(() => requestDigestInput(metadata, enc, new Uint8Array(3073))).toThrow(CodecFailure)
    const changed = ciphertext.slice(); changed[0] = 1
    expect(requestDigestInput(metadata, enc, changed)).not.toEqual(requestDigestInput(metadata, enc, ciphertext))
  })
})
