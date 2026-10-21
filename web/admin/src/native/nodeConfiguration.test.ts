import { expect, it } from 'vitest'
import { nodeQuotaFromGb, nodeQuotaGb, parseNodeProtocols, withProtocolValue, protocolValue } from './nodeConfiguration'

it('round-trips exact byte limits beyond Number precision and rejects overflow and rounded fractions', () => {
  expect(nodeQuotaFromGb('9007199.254740999')).toBe('9007199254740999')
  expect(nodeQuotaGb('9007199254740999')).toBe('9007199.254740999')
  expect(nodeQuotaFromGb('9223372036.854775807')).toBe('9223372036854775807')
  expect(() => nodeQuotaFromGb('9223372036.854775808')).toThrow()
  expect(() => nodeQuotaFromGb('0.0000000001')).toThrow()
  expect(() => nodeQuotaFromGb('1e3')).toThrow()
  expect(nodeQuotaFromGb('')).toBeNull()
})

it('updates nested protocol fields without discarding sibling settings or mutating the old draft', () => {
  const source = { tls: 2, reality_settings: { public_key: 'old', short_id: '1234' }, network: 'grpc' }
  const next = withProtocolValue(source, 'reality_settings.public_key', 'new')
  expect(next).toEqual({ tls: 2, reality_settings: { public_key: 'new', short_id: '1234' }, network: 'grpc' })
  expect(protocolValue(source, 'reality_settings.public_key')).toBe('old')
  expect(() => withProtocolValue({}, '__proto__.polluted', true)).toThrow()
  expect(Object.prototype).not.toHaveProperty('polluted')
})

it('rejects metadata that could assign prototype properties or unsupported field types', () => {
  const metadata = { type: 'vless', label: 'VLESS', defaults: {}, schema_version: 1, form_schema: [{ key: 'constructor.prototype', label: 'bad', type: 'text' }] }
  expect(() => parseNodeProtocols(JSON.stringify([metadata]))).toThrow()
  metadata.form_schema[0] = { key: 'tls', label: 'TLS', type: 'javascript' }
  expect(() => parseNodeProtocols(JSON.stringify([metadata]))).toThrow()
})
