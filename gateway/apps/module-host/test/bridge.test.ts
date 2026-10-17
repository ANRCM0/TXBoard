import { describe, expect, it, vi } from 'vitest'
import { ExtensionClient, type ExtensionDescriptor } from '@txboard/core-sdk'
import { ModuleBridge } from '../src/bridge.js'

const schema = { type: 'object', properties: { value: { type: 'string', maxLength: 16 } }, required: ['value'], additionalProperties: false }
const descriptor: ExtensionDescriptor = { schema: 'module-presentation/v1', module: 'test', version: '1.0.0', fingerprint: 'a'.repeat(64),
  actor: 'admin', title: 'test', entry: 'ui/index.html', assets: ['ui/index.html'], operations: {
    'status.get': { actor: 'admin', permission: 'extensions.test.view', idempotency: 'read', input: schema, output: schema },
  } }
const message = { channel: 'core-module/v1', id: 'one', operation: 'status.get', input: { value: 'hello' } }

describe('isolated module bridge', () => {
  it('admits only the attached sandbox window and declared SDK call', async () => {
    const send = vi.fn().mockResolvedValue({ value: 'world' })
    const source = {}
    const bridge = new ModuleBridge(source, new ExtensionClient(descriptor, send))
    expect(await bridge.receive({}, 'null', message)).toBeNull()
    expect(await bridge.receive(source, 'https://evil.example', message)).toBeNull()
    expect(await bridge.receive(source, 'null', { ...message, url: 'https://evil.example' })).toBeNull()
    expect(send).not.toHaveBeenCalled()
    expect(await bridge.receive(source, 'null', message)).toMatchObject({ id: 'one', data: { value: 'world' } })
    expect(await bridge.receive(source, 'null', message)).toBeNull()
    expect(send).toHaveBeenCalledTimes(1)
  })

  it('rejects actor smuggling and permission revocation without exposing transport errors', async () => {
    const send = vi.fn().mockRejectedValue(new Error('MODULE_PERMISSION_DENIED'))
    const source = {}
    const bridge = new ModuleBridge(source, new ExtensionClient(descriptor, send))
    expect(await bridge.receive(source, 'null', { ...message, input: { value: 'x', actor: 'admin' } })).toMatchObject({ error: 'MODULE_INPUT_INVALID' })
    expect(send).not.toHaveBeenCalled()
    expect(await bridge.receive(source, 'null', { ...message, id: 'two' })).toMatchObject({ error: 'MODULE_PERMISSION_DENIED' })
    send.mockRejectedValueOnce(new Error('credentials should remain private'))
    expect(await bridge.receive(source, 'null', { ...message, id: 'three' })).toMatchObject({ error: 'MODULE_REQUEST_FAILED' })
  })

  it('drops in-flight results when the host switches or disables the page', async () => {
    let resolve!: (value: unknown) => void
    const send = vi.fn(() => new Promise(done => { resolve = done }))
    const source = {}
    const bridge = new ModuleBridge(source, new ExtensionClient(descriptor, send))
    const pending = bridge.receive(source, 'null', message)
    bridge.close()
    resolve({ value: 'world' })
    expect(await pending).toBeNull()
    expect(await bridge.receive(source, 'null', { ...message, id: 'two' })).toBeNull()
  })
})
