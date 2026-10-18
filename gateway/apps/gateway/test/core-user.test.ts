import { afterEach, describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { CORE_USER_OPERATIONS } from '../src/contracts/core-user.js'
import { coreOperations } from '../src/contracts/core.generated.js'
import { NativeCoreClient, type NativeServiceConfig } from '../src/services/native-internal.js'
import { requestId } from './native-fixtures.js'
import { GatewayFailure } from '../src/services/failures.js'

const prefix = '/gateway/v1/core/user'
const service: NativeServiceConfig = { domain: 'user', serviceId: 'fixture', credential: 'a'.repeat(64), epoch: '1',
  caFile: '/fixture/ca', certificateFile: '/fixture/cert', keyFile: '/fixture/key' }
const base = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.example.test' })
const configured = (write = false) => ({ ...base, coreUserOperationsEnabled: true, nativeUserService: service, orderWriteEnabled: write })
const post = (app: ReturnType<typeof createGatewayApp>, id: string, body: string, auth = true, key?: string) => app.request(`${prefix}/operations/${id}`, {
  method: 'POST', headers: { 'content-type': 'application/json', ...(auth ? { Authorization: 'Bearer user-token-long' } : {}),
    ...(key ? { 'Idempotency-Key': key } : {}) }, body,
})
afterEach(() => vi.restoreAllMocks())

describe('reviewed Core user surface', () => {
  it('keeps the surface absent by default and financial commands absent without opt-in', async () => {
    const call = vi.spyOn(NativeCoreClient.prototype, 'call')
    const disabled = createGatewayApp(base)
    expect((await disabled.request(`${prefix}/capabilities`)).status).toBe(404)
    const read = createGatewayApp(configured())
    expect((await post(read, 'commerce.orders.create', '{"input":{"offer_id":"1"}}', true, requestId)).status).toBe(404)
    expect((await post(read, 'settings.site.update', '{"input":{}}')).status).toBe(404)
    expect(call).not.toHaveBeenCalled()
    expect(() => loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test', GATEWAY_CORE_USER_OPERATIONS_ENABLED: 'true' })).toThrow()
  })

  it('rejects asserted identity, duplicate keys and missing actor or key before Core I/O', async () => {
    const call = vi.spyOn(NativeCoreClient.prototype, 'call')
    const app = createGatewayApp(configured(true))
    expect((await post(app, 'billing.wallet.get', '{"input":{}}', false)).status).toBe(401)
    expect((await post(app, 'catalog.offers.list', '{"input":{},"actor":"admin"}')).status).toBe(422)
    expect((await post(app, 'catalog.offers.list', '{"input":{},"input":{}}')).status).toBe(400)
    expect((await post(app, 'commerce.orders.create', '{"input":{"offer_id":1}}', true, requestId)).status).toBe(422)
    expect((await post(app, 'commerce.orders.create', '{"input":{"offer_id":"1"}}')).status).toBe(400)
    expect(call).not.toHaveBeenCalled()
  })

  it('preserves DTO, cursor, original actor and request correlation', async () => {
    const call = vi.spyOn(NativeCoreClient.prototype, 'call').mockResolvedValue({ data: [], request_id: requestId,
      meta: { next_after_id: '9007199254740993' } })
    const app = createGatewayApp(configured(true))
    const response = await post(app, 'billing.recharges.list', '{"input":{"after_id":"9007199254740993"}}')
    expect(response.status).toBe(200)
    expect(await response.json()).toMatchObject({ data: [], meta: { coreRequestId: requestId,
      coreMeta: { next_after_id: '9007199254740993' } } })
    expect(response.headers.get('cache-control')).toContain('no-store')
    expect(call).toHaveBeenCalledWith('billing.recharges.list', expect.objectContaining({
      authorization: 'Bearer user-token-long', input: { after_id: '9007199254740993' },
    }))
  })

  it('discovers only actual service grants admitted by local feature gates', async () => {
    const rows = CORE_USER_OPERATIONS.map(id => ({ id, version: '1', actor: coreOperations[id].actor, idempotency: coreOperations[id].idempotency }))
    vi.spyOn(NativeCoreClient.prototype, 'call').mockResolvedValue({ request_id: requestId, data: {
      contract: 'native-internal-api/v1', domain: 'user', operations: rows.filter(row => row.id !== 'billing.wallet.get'),
    } })
    const response = await createGatewayApp(configured()).request(`${prefix}/capabilities`)
    expect(response.status).toBe(200)
    const body = await response.json() as { data: { operations: { id: string }[] } }
    expect(body.data.operations.map(row => row.id)).toContain('identity.session.end')
    expect(body.data.operations.map(row => row.id)).not.toContain('commerce.orders.create')
    expect(body.data.operations.map(row => row.id)).not.toContain('billing.wallet.get')
  })
})

it('admits native user login without bearer or commerce writes and preserves Core CAPTCHA authority', async () => {
  const call = vi.spyOn(NativeCoreClient.prototype, 'call').mockResolvedValue({ data: { auth_data: 'Bearer user-token-long' }, request_id: requestId })
  const check = vi.fn(async () => {})
  const app = createGatewayApp(configured(false), undefined, undefined, { limiter: { check } })
  const input = { email: 'User@example.test', password: 'password-long', turnstile_token: 'original-challenge' }
  const response = await post(app, 'identity.session.create', JSON.stringify({ input }), false, requestId)
  expect(response.status).toBe(200)
  expect(check).toHaveBeenCalledWith('login', 'user@example.test', '0.0.0.0')
  expect(call).toHaveBeenCalledExactlyOnceWith('identity.session.create', expect.objectContaining({ input, idempotencyKey: requestId, authorization: undefined }))
  expect(response.headers.get('cache-control')).toContain('no-store')
})

it('validates anonymous login before limiting and fails closed without an account limiter', async () => {
  const call = vi.spyOn(NativeCoreClient.prototype, 'call')
  const check = vi.fn(async () => {})
  const app = createGatewayApp(configured(), undefined, undefined, { limiter: { check } })
  expect((await post(app, 'identity.session.create', '{"input":{"password":"password-long"}}', false, requestId)).status).toBe(422)
  expect((await post(app, 'identity.session.create', '{"input":{"email":"user@example.test","password":"password-long"}}', false)).status).toBe(400)
  expect(check).not.toHaveBeenCalled()
  const response = await post(createGatewayApp(configured()), 'identity.session.create', '{"input":{"email":"user@example.test","password":"password-long"}}', false, requestId)
  expect(response.status).toBe(503)
  expect(call).not.toHaveBeenCalled()
})

it('propagates Core login rejection and limiter rejection without authentication bypass', async () => {
  const call = vi.spyOn(NativeCoreClient.prototype, 'call').mockRejectedValue(new GatewayFailure('CAPTCHA_REQUIRED', 403, 'challenge required'))
  const input = JSON.stringify({ input: { email: 'user@example.test', password: 'password-long' } })
  const app = createGatewayApp(configured(), undefined, undefined, { limiter: { check: vi.fn(async () => {}) } })
  expect((await post(app, 'identity.session.create', input, false, requestId)).status).toBe(403)
  call.mockClear()
  const limited = createGatewayApp(configured(), undefined, undefined, { limiter: { check: vi.fn(async () => { throw new GatewayFailure('RATE_LIMITED', 429, 'limited') }) } })
  expect((await post(limited, 'identity.session.create', input, false, requestId)).status).toBe(429)
  expect(call).not.toHaveBeenCalled()
})
