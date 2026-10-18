import { describe, expect, it, vi } from 'vitest'
import { ExtensionClient, acceptsModuleSchema, isModuleSchema, parseBridgeRequest, parseExtensionDescriptor } from '../src/extensions.js'
import { CoreClient, type CoreTransport } from '../src/index.js'

const empty = { type: 'object', properties: {}, required: [], additionalProperties: false }
const descriptor = () => ({ schema: 'module-presentation/v1', module: 'example', version: '1.0.0', fingerprint: 'a'.repeat(64),
  actor: 'user', title: 'Example', entry: 'ui/index.html', assets: ['ui/index.html'], operations: {
    'version.get': { actor: 'user', permission: null, idempotency: 'read', input: empty,
      output: { type: 'object', properties: { version: { type: 'string', minLength: 1, maxLength: 80 } }, required: ['version'], additionalProperties: false } },
  } })

describe('extension contracts and dynamic SDK', () => {
  it('uses the same absolute-end and Unicode length rules as PHP and generated contracts', async () => {
    const schema = { type: 'string', pattern: '^[1-9][0-9]*$', 'x-decimal-maximum': '2147483647' }
    expect(acceptsModuleSchema('123\n', schema)).toBe(false)
    expect(acceptsModuleSchema('01', schema)).toBe(false)
    expect(acceptsModuleSchema('2147483648', schema)).toBe(false)
    expect(acceptsModuleSchema('123', schema)).toBe(true)
    const scalar = String.fromCodePoint(0x1f642)
    expect(acceptsModuleSchema(scalar, { type: 'string', maxLength: 1 })).toBe(true)
    expect(acceptsModuleSchema(scalar + scalar, { type: 'string', maxLength: 1 })).toBe(false)
    const send = vi.fn<CoreTransport>()
    await expect(new CoreClient(send).call('commerce.orders.create', { offer_id: '12\n' }, {
      idempotencyKey: '78a166a1-d6cc-4f11-a214-cb2d1f9b1d1c' })).rejects.toMatchObject({ code: 'INPUT_INVALID' })
    expect(send).not.toHaveBeenCalled()
  })

  it('rejects malformed declarations before exposing a callable descriptor', () => {
    for (const malformed of [{ type: 'string', maxLength: -1 }, { type: 'array', items: empty }, { const: 1, type: 'integer' },
      { type: 'string', pattern: '[' }, { type: 'string', 'x-decimal-maximum': '01' }]) expect(isModuleSchema(malformed)).toBe(false)
    const metadata = descriptor()
    expect(parseExtensionDescriptor(metadata).module).toBe('example')
    expect(() => parseExtensionDescriptor({ ...metadata, assets: ['Plugin.php'], entry: 'Plugin.php' })).toThrow('MODULE_DESCRIPTOR_INVALID')
    expect(() => parseExtensionDescriptor({ ...metadata, actor: 'admin' })).toThrow('MODULE_DESCRIPTOR_INVALID')
    expect(() => parseExtensionDescriptor({ ...metadata, url: 'https://private' })).toThrow('MODULE_DESCRIPTOR_INVALID')
  })

  it('rejects input smuggling, arbitrary operations and sensitive output', async () => {
    const send = vi.fn().mockResolvedValue({ version: '1.0.0', secret: 'private' })
    const client = new ExtensionClient(parseExtensionDescriptor(descriptor()), send)
    await expect(client.call('unknown', {})).rejects.toThrow('MODULE_OPERATION_NOT_FOUND')
    await expect(client.call('version.get', { actor: 'admin' })).rejects.toThrow('MODULE_INPUT_INVALID')
    expect(send).not.toHaveBeenCalled()
    await expect(client.call('version.get', {})).rejects.toThrow('MODULE_OUTPUT_INVALID')
    expect(send).toHaveBeenCalledTimes(1)
  })

  it('keeps transport failures unknown and rejects role, credential and URL fields in Bridge messages', async () => {
    const send = vi.fn().mockRejectedValue(new Error('result unknown'))
    await expect(new ExtensionClient(parseExtensionDescriptor(descriptor()), send).call('version.get', {})).rejects.toThrow('result unknown')
    expect(send).toHaveBeenCalledTimes(1)
    const request = { channel: 'core-module/v1', id: '1', operation: 'version.get', input: {} }
    expect(parseBridgeRequest(request)).toEqual(request)
    for (const field of ['actor', 'Authorization', 'url']) expect(parseBridgeRequest({ ...request, [field]: 'private' })).toBeNull()
  })
})
