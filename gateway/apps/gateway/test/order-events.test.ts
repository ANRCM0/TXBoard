import { afterEach, describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { NativeCoreClient, type NativeServiceConfig } from '../src/services/native-internal.js'
import { requestId } from './native-fixtures.js'

const base = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.example.test' })
const service = (domain: 'user' | 'admin'): NativeServiceConfig => ({ domain, serviceId: `${domain}-fixture`, credential: 'a'.repeat(64), epoch: '1', caFile: '/fixture/ca', certificateFile: '/fixture/cert', keyFile: '/fixture/key' })
afterEach(() => vi.restoreAllMocks())

describe.each(['user', 'admin'] as const)('%s order event admission', domain => {
  const operation = domain === 'user' ? 'commerce.orders.events.list' : 'commerce.admin-orders.events.list'
  const headers = { 'content-type': 'application/json', Authorization: 'Bearer original-actor-token', ...(domain === 'admin' ? { 'X-Core-Admin-Path': 'rotated_path' } : {}) }
  const config = { ...base, coreUserOperationsEnabled: true, nativeUserService: service('user'), coreAdminOperationsEnabled: true, nativeAdminService: service('admin'), orderWriteEnabled: false }
  const app = () => createGatewayApp(config, undefined, undefined, { limiter: { check: vi.fn(async () => {}) } })
  const url = `/gateway/v1/core/${domain}/operations/${operation}`
  const input = { trade_no: 'TX-1', after_sequence: '9007199254740993', limit: 25 }

  it('admits authenticated reads with writes disabled and preserves exact cursor metadata', async () => {
    const meta = { next_after_sequence: '9007199254740994', has_more: true, history_complete: false }
    const data = [{ sequence: '9007199254740994', type: 'fulfilled', status: 3, occurred_at: '2026-10-19T00:00:00Z' }]
    const call = vi.spyOn(NativeCoreClient.prototype, 'call').mockResolvedValue({ data, meta, request_id: requestId })
    const response = await app().request(url, { method: 'POST', headers, body: JSON.stringify({ input }) })
    expect(response.status).toBe(200)
    expect(await response.json()).toMatchObject({ data, meta: { coreRequestId: requestId, coreMeta: meta } })
    expect(response.headers.get('cache-control')).toContain('no-store')
    expect(call).toHaveBeenCalledExactlyOnceWith(operation, expect.objectContaining({ input, authorization: 'Bearer original-actor-token', ...(domain === 'admin' ? { adminPath: 'rotated_path' } : {}) }))
  })

  it('rejects absent identity and malformed cursor/limit before Core I/O', async () => {
    const call = vi.spyOn(NativeCoreClient.prototype, 'call')
    const gateway = app()
    expect((await gateway.request(url, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ input }) })).status).toBe(401)
    for (const badInput of [{ ...input, after_sequence: 2 }, { ...input, after_sequence: '01' }, { ...input, after_sequence: '9223372036854775808' }, { ...input, limit: 101 }, { ...input, user_id: '1' }]) {
      expect((await gateway.request(url, { method: 'POST', headers, body: JSON.stringify({ input: badInput }) })).status).toBe(422)
    }
    expect(call).not.toHaveBeenCalled()
  })
})

describe.each(['user', 'admin'] as const)('%s native order event response validation', domain => {
  it('rejects malformed owner events and metadata before returning to the browser', async () => {
    const operation = domain === 'user' ? 'commerce.orders.events.list' : 'commerce.admin-orders.events.list'
    const event = { sequence: '1', type: 'created', status: 0, occurred_at: '2026-10-19T00:00:00Z' }
    const meta = { next_after_sequence: '1', has_more: false, history_complete: true }
    for (const payload of [
      { data: [{ ...event, sequence: 1 }], meta },
      { data: [{ ...event, secret: 'private' }], meta },
      { data: [event], meta: { ...meta, next_after_sequence: 1 } },
      { data: [event], meta: { next_after_sequence: '1', has_more: false } },
    ]) {
      const send = vi.fn().mockResolvedValue({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...payload, request_id: requestId }) })
      const client = new NativeCoreClient(new URL('https://core.example.test'), service(domain), 8000, 1048576, send)
      await expect(client.call(operation, { input: { trade_no: 'TX-1' }, authorization: 'Bearer original-actor-token', ...(domain === 'admin' ? { adminPath: 'rotated_path' } : {}) })).rejects.toMatchObject({ status: 502 })
    }
  })
})
