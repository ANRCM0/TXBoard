import { afterEach, describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { NativeCoreClient, type NativeServiceConfig } from '../src/services/native-internal.js'
import { requestId } from './native-fixtures.js'

const base = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.example.test' })
const service: NativeServiceConfig = { domain: 'admin', serviceId: 'admin-fixture', credential: 'b'.repeat(64),
  epoch: '1', caFile: '/fixture/ca', certificateFile: '/fixture/cert', keyFile: '/fixture/key' }
const config = { ...base, coreAdminOperationsEnabled: true, nativeAdminService: service }
const prefix = '/gateway/v1/core/admin'
const limiter = { check: vi.fn(async () => {}) }
const app = () => createGatewayApp(config, undefined, undefined, { limiter })
afterEach(() => { vi.restoreAllMocks(); limiter.check.mockClear() })

describe('administrator browser admission', () => {
  it('requires isolated admin service and an account limiter, defaults off, and excludes arbitrary writes', async () => {
    expect(() => createGatewayApp(config)).toThrow('Redis')
    expect(() => loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test', GATEWAY_CORE_ADMIN_OPERATIONS_ENABLED: 'true' })).toThrow('admin service')
    expect((await createGatewayApp(base).request(`${prefix}/capabilities`)).status).toBe(404)
    expect((await app().request(`${prefix}/operations/settings.site.update`, { method: 'POST' })).status).toBe(404)
  })
  it('requires the actor and current path before Core and never accepts asserted permission', async () => {
    const call = vi.spyOn(NativeCoreClient.prototype, 'call')
    const gateway = app()
    const send = (body: string, headers = {}) => gateway.request(`${prefix}/operations/identity.admin.get`, {
      method: 'POST', headers: { 'content-type': 'application/json', ...headers }, body,
    })
    expect((await send('{"input":{}}')).status).toBe(401)
    expect((await send('{"input":{}}', { Authorization: 'Bearer administrator-token' })).status).toBe(401)
    expect((await send('{"input":{},"permission":"*"}', { Authorization: 'Bearer administrator-token', 'X-Core-Admin-Path': 'admin_path' })).status).toBe(422)
    expect(call).not.toHaveBeenCalled()
  })
  it('throttles login and sends original actor and path for authenticated reads', async () => {
    const call = vi.spyOn(NativeCoreClient.prototype, 'call').mockResolvedValue({ data: { id: '1', email: 'admin@example.test' }, request_id: requestId })
    const gateway = app()
    const read = await gateway.request(`${prefix}/operations/identity.admin.get`, { method: 'POST', body: '{"input":{}}',
      headers: { 'content-type': 'application/json', Authorization: 'Bearer administrator-token', 'X-Core-Admin-Path': 'rotated_path' } })
    expect(read.status).toBe(200)
    expect(call).toHaveBeenLastCalledWith('identity.admin.get', expect.objectContaining({ authorization: 'Bearer administrator-token', adminPath: 'rotated_path' }))
    await gateway.request(`${prefix}/operations/identity.admin-session.create`, { method: 'POST',
      body: JSON.stringify({ input: { email: 'admin@example.test', password: 'password-long' } }),
      headers: { 'content-type': 'application/json', 'Idempotency-Key': requestId } })
    expect(limiter.check).toHaveBeenCalledWith('login', 'admin@example.test', undefined)
  })
})
