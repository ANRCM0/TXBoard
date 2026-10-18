import { describe, expect, it, vi } from 'vitest'
import { NativeCoreClient, type NativeServiceConfig, type NativeTransport } from '../src/services/native-internal.js'
import { loadConfig } from '../src/config/env.js'
import { upstreamRequest } from '../src/services/upstream.js'

const service: NativeServiceConfig = {
  domain: 'user', serviceId: 'user-gateway', credential: 'a'.repeat(64), epoch: '3',
  caFile: '/run/secrets/core-ca.pem', certificateFile: '/run/secrets/user-cert.pem', keyFile: '/run/secrets/user-key.pem',
}
const requestId = '11111111-1111-4111-8111-111111111111'
const actor = 'Bearer 1|actor-session-credential'
const result = { data: { id: '9007199254740993', trade_no: 'TX-1', status: 0,
  amount_minor: '1200', currency: 'CNY', purchase_terms_version: 'tx-core.purchase-terms/v2' }, request_id: requestId }
const transport = () => vi.fn<NativeTransport>().mockResolvedValue({
  status: 200, contentType: 'application/json', body: JSON.stringify(result),
})

describe('production native service consumer', () => {
  it('pins the operation and service domain while forwarding a distinct actor and original key', async () => {
    const send = transport()
    const client = new NativeCoreClient(new URL('https://core.internal/'), service, 8000, 1048576, send)
    expect(await client.call('commerce.orders.create', {
      input: { offer_id: '9007199254740993' }, authorization: actor,
      idempotencyKey: requestId, requestId,
    })).toEqual(result)
    const [url, options] = send.mock.calls[0]!
    expect(url.toString()).toBe('https://core.internal/txapi/internal/native/v1/user/operations/commerce.orders.create')
    expect(options.headers.Authorization).toBe(actor)
    expect(options.headers['X-Core-Service-Credential']).toBe(service.credential)
    expect(options.headers['Idempotency-Key']).toBe(requestId)
    expect(options.headers).not.toHaveProperty('X-Core-Tls-Verified')
    expect(options.headers).not.toHaveProperty('X-Core-Tls-Certificate-Sha256')
    expect(JSON.parse(options.body).input.offer_id).toBe('9007199254740993')
    expect(send).toHaveBeenCalledTimes(1)
  })
  it('never promotes a user service to admin or accepts missing actors/keys', async () => {
    const send = transport()
    const client = new NativeCoreClient(new URL('https://core.internal/'), service, 8000, 1048576, send)
    await expect(client.call('network.nodes.list', { authorization: actor })).rejects.toMatchObject({ status: 403 })
    await expect(client.call('commerce.orders.create', { input: { offer_id: '1' } })).rejects.toMatchObject({ status: 401 })
    await expect(client.call('commerce.orders.create', { authorization: actor })).rejects.toMatchObject({ status: 400 })
    await expect(client.call('../admin/operations/network.nodes.list' as never)).rejects.toMatchObject({ status: 403 })
    expect(send).not.toHaveBeenCalled()
  })
  it('uses a separate admin identity and requires the current admin path', async () => {
    const send = transport()
    const client = new NativeCoreClient(new URL('https://core.internal/'), {
      ...service, domain: 'admin', serviceId: 'admin-gateway', credential: 'b'.repeat(64),
    }, 8000, 1048576, send)
    await expect(client.call('network.machines.list', { authorization: actor })).rejects.toMatchObject({ status: 403 })
    await client.call('network.machines.list', { authorization: actor, adminPath: 'current-path' })
    expect(send.mock.calls[0]![1].headers['X-Core-Service-Id']).toBe('admin-gateway')
    expect(send.mock.calls[0]![1].headers['X-Core-Admin-Path']).toBe('current-path')
  })
  it('fails closed on plaintext and incomplete channel configuration', () => {
    expect(() => new NativeCoreClient(new URL('http://core.internal/'), service)).toThrow('HTTPS')
    expect(() => loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.internal/',
      GATEWAY_CORE_USER_ENABLED: 'true' })).toThrow('configuration')
    expect(() => new NativeCoreClient(new URL('https://core.internal/'), service, Number.NaN)).toThrow('configuration')
    expect(() => new NativeCoreClient(new URL('https://core.internal/'), service, 8000, Number.NaN)).toThrow('configuration')
  })
  it('rejects non-object or unserializable input without exposing field names', async () => {
    const send = transport()
    const client = new NativeCoreClient(new URL('https://core.internal/'), service, 8000, 1048576, send)
    await expect(client.call('system.capabilities.get', { input: [] as never }))
      .rejects.toMatchObject({ status: 400, message: 'Core input object required' })
    const input: Record<string, unknown> = {}
    input['PRIVATE_SECRET_FIELD'] = input
    await expect(client.call('system.capabilities.get', { input }))
      .rejects.toMatchObject({ status: 400, message: 'Invalid Core request' })
    expect(send).not.toHaveBeenCalled()
  })
  it('does not retry unknown writes or leak upstream error messages', async () => {
    const send = vi.fn<NativeTransport>().mockRejectedValue(new Error('secret TLS key material'))
    const client = new NativeCoreClient(new URL('https://core.internal/'), service, 8000, 1048576, send)
    await expect(client.call('commerce.orders.create', { input: { offer_id: '1' },
      authorization: actor, idempotencyKey: requestId })).rejects.toMatchObject({
      status: 503, message: 'Core result unknown; retain the original idempotency key',
    })
    expect(send).toHaveBeenCalledTimes(1)
    send.mockResolvedValue({ status: 403, contentType: 'application/json',
      body: JSON.stringify({ request_id: requestId, error: { code: 'FORBIDDEN', message: 'PRIVATE SECRET' } }) })
    await expect(client.call('identity.sessions.list', { authorization: actor }))
      .rejects.toMatchObject({ status: 403, code: 'FORBIDDEN', message: 'Core operation not granted' })
  })
  it('keeps audit or dependency 5xx outcomes unknown and never retries them', async () => {
    const send = transport()
    send.mockResolvedValue({ status: 503, contentType: 'application/json',
      body: JSON.stringify({ request_id: requestId, error: { code: 'AUDIT_UNAVAILABLE', message: 'PRIVATE SECRET' } }) })
    const client = new NativeCoreClient(new URL('https://core.internal/'), service, 8000, 1048576, send)
    await expect(client.call('commerce.orders.create', { input: { offer_id: '1' },
      authorization: actor, idempotencyKey: requestId })).rejects.toMatchObject({
      status: 503, code: 'UPSTREAM_UNAVAILABLE',
      message: 'Core result unknown; retain the original idempotency key',
    })
    expect(send).toHaveBeenCalledTimes(1)
  })
  it('rejects duplicate response keys and misleading JSON media types', async () => {
    const send = transport()
    const client = new NativeCoreClient(new URL('https://core.internal/'), service, 8000, 1048576, send)
    send.mockResolvedValue({ status: 200, contentType: 'application/json',
      body: `{"request_id":"${requestId}","data":{},"data":{"PRIVATE_SECRET":"value"}}` })
    await expect(client.call('system.capabilities.get')).rejects.toMatchObject({ status: 502 })
    send.mockResolvedValue({ status: 200, contentType: 'application/jsonp', body: JSON.stringify(result) })
    await expect(client.call('system.capabilities.get')).rejects.toMatchObject({ status: 502 })
  })
  it('uses the configured user service for real login/config consumers without forwarding unrelated actor headers', async () => {
    const config = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.internal/' })
    config.nativeUserService = service
    config.nativeAdminService = { ...service, domain: 'admin', serviceId: 'admin-gateway', credential: 'b'.repeat(64) }
    const fetcher = vi.fn<typeof fetch>()
    const call = vi.spyOn(NativeCoreClient.prototype, 'call')
    try {
      call.mockResolvedValue({ request_id: requestId, data: { app_name: 'Native site' } })
      expect(await upstreamRequest(config, fetcher, 'guestConfig', { auth: actor })).toEqual({ app_name: 'Native site' })
      expect(call).toHaveBeenLastCalledWith('settings.public.get', { input: {}, signal: undefined })
      expect(call.mock.contexts[0]).toMatchObject({ service })
      call.mockResolvedValue({ request_id: requestId, data: { auth_data: actor } })
      expect(await upstreamRequest(config, fetcher, 'login', {
        auth: 'Bearer unrelated-session', body: { email: 'actor@example.test', password: 'PrivatePassword' },
      })).toEqual({ auth_data: actor })
      expect(call).toHaveBeenLastCalledWith('identity.session.create', {
        input: { email: 'actor@example.test', password: 'PrivatePassword' }, signal: undefined,
      })
      expect(fetcher).not.toHaveBeenCalled()
    } finally {
      call.mockRestore()
    }
  })
  it('never promotes the independent admin service into public browser consumption', async () => {
    const config = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.internal/' })
    config.nativeAdminService = { ...service, domain: 'admin', serviceId: 'admin-gateway', credential: 'b'.repeat(64) }
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      request_id: requestId, data: { app_name: 'Direct site' },
    }), { headers: { 'Content-Type': 'application/json' } }))
    const call = vi.spyOn(NativeCoreClient.prototype, 'call')
    try {
      expect(await upstreamRequest(config, fetcher, 'guestConfig')).toEqual({ app_name: 'Direct site' })
      expect(call).not.toHaveBeenCalled()
      expect(fetcher).toHaveBeenCalledTimes(1)
    } finally {
      call.mockRestore()
    }
  })
})
