import { requestId, nativeError } from './native-fixtures.js'
import { describe, expect, it, vi } from 'vitest'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import {
  POLICY_DEFINITIONS,
  ROUTE_OPERATIONS,
  activeRouteDefinitions,
  operationsFor,
  routeDefinitions,
  validatePolicies,
} from '../src/config/policies.js'
import {
  canonicalJson,
  orderIdempotencyHint,
  validateIdempotencyKey,
} from '../src/middleware/idempotency.js'
import { resolveRoutePolicy } from '../src/middleware/rate-limit.js'
import { invalidateCache } from '../src/middleware/cache.js'

/**
 * GW-304: the Gateway-side order-write adaptation layer.
 *
 * Covers, per the task's acceptance criteria:
 *  1. The write ban is the DEFAULT and stays structural (405, zero upstream
 *     calls) unless GATEWAY_ORDER_WRITE_ENABLED is set — and no header,
 *     manifest or capability can weaken it.
 *  2. The policy table promotes exactly one route to exactly one posture,
 *     and validatePolicies() refuses an unsound promotion at boot.
 *  3. Idempotency: a missing/malformed key is refused with ZERO upstream
 *     calls; the key and the Gateway-derived hint are forwarded upstream;
 *     the hint contains no user identity and is not authoritative.
 *  4. Authorization: bearer required before any upstream call.
 *  5. Error mapping: IDEMPOTENCY_IN_PROGRESS / IDEMPOTENCY_CONFLICT /
 *     business rejections surface with their own codes and statuses, never a
 *     generic 502.
 *  6. The Gateway never forwards an admin token or copies a business rule:
 *     the body it sends is exactly the validated body, and the only headers
 *     it adds are the two idempotency ones.
 */

function testConfig(overrides: Record<string, string> = {}) {
  return loadConfig({
    TXBOARD_UPSTREAM_URL: 'https://txboard.example/',
    GATEWAY_ALLOWED_ORIGINS: 'https://theme.example',
    ...overrides,
  })
}

/** A TXBoard success envelope. */
function okEnvelope(data: unknown) {
  return { request_id: requestId, data }
}

/** A TXBoard failure envelope with a business code (Laravel's vocabulary). */
function failEnvelope(code: string, status = 422) {
  return vi.fn(async () => Response.json(
    nativeError(code),
    { status, headers: status === 409 ? { 'Retry-After': '2' } : undefined },
  ))
}

const USER_BEARER = 'Bearer usertoken_longer_than_eight'
const VALID_ORDER = { plan_id: 1, period: 'month_price' }

function postOrder(
  app: ReturnType<typeof createGatewayApp>,
  body: unknown = VALID_ORDER,
  headers: Record<string, string> = { 'Idempotency-Key': 'order-key-abc123' },
) {
  return app.request('/gateway/v1/orders', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
}

/** Type a mocked upstream call's arguments as `[url, init]`. */
function upstreamCall(fetcher: ReturnType<typeof vi.fn>, index = 0): [string, RequestInit] {
  const call = fetcher.mock.calls[index] as unknown as [string, RequestInit] | undefined
  if (!call) throw new Error(`expected an upstream call at index ${index}`)
  return call
}

describe('GW-304 the write ban stays the default posture', () => {
  it('answers 405 with zero upstream calls when the flag is unset', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig(), fetcher)
    const headersets: Array<Record<string, string>> = [
      { 'Idempotency-Key': 'k1' },
      { Authorization: USER_BEARER, 'Idempotency-Key': 'k1' },
      { Origin: 'https://theme.example', Authorization: USER_BEARER, 'Idempotency-Key': 'k1' },
    ]
    for (const headers of headersets) {
      const res = await postOrder(app, VALID_ORDER, headers)
      expect(res.status).toBe(405)
      expect((await res.json() as { error: { code: string } }).error.code).toBe('METHOD_NOT_ALLOWED')
    }
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('keeps disabledWrite mapped to the empty operation set', () => {
    const ban = routeDefinitions.find(d => d.policy.name === 'disabledWrite')!
    expect(routeKeyOf(ban)).toBe('POST /gateway/v1/orders')
    expect(operationsFor(ban)).toEqual([])
    expect(ROUTE_OPERATIONS['POST /gateway/v1/orders']).toEqual([])
  })

  it('promotes the route to orderWrite only with the flag, and nowhere else', () => {
    const banned = activeRouteDefinitions({})
    expect(banned.find(d => d.method === 'POST' && d.path === '/gateway/v1/orders')!.policy.name)
      .toBe('disabledWrite')

    const enabled = activeRouteDefinitions({ orderWrite: true })
    const promoted = enabled.find(d => d.method === 'POST' && d.path === '/gateway/v1/orders')!
    expect(promoted.policy).toBe(POLICY_DEFINITIONS.orderWrite)
    expect(operationsFor(promoted)).toEqual(['userOrderCreate'])
    // Exactly one entry changed posture; nothing else moved.
    expect(enabled.filter(d => d.policy.name === 'orderWrite')).toHaveLength(1)
    expect(enabled.length).toBe(banned.length)
  })

  it('declares the orderWrite posture as bearer + json + never cached', () => {
    expect({ ...POLICY_DEFINITIONS.orderWrite }).toEqual({
      name: 'orderWrite', auth: 'bearer', body: 'json', limiter: 'public', cache: 'off',
    })
  })

  it('boots the write ban closed even with every other capability present', async () => {
    // HPKE + Redis + account workflows, but no order-write flag: still banned.
    const fetcher = vi.fn(async () => Response.json(okEnvelope({})))
    const app = createGatewayApp(testConfig(), fetcher, undefined, { limiter: { check: vi.fn() } })
    expect((await postOrder(app, VALID_ORDER, { Authorization: USER_BEARER })).status).toBe(405)
    expect(fetcher).not.toHaveBeenCalled()
  })
})

describe('GW-304 the promotion is boot-validated', () => {
  it('accepts the promoted table and maps exactly one write operation', () => {
    expect(() => validatePolicies(activeRouteDefinitions({ orderWrite: true }), { orderWrite: true }))
      .not.toThrow()
  })

  it('rejects an orderWrite route mapped to a read operation', () => {
    const promoted = activeRouteDefinitions({ orderWrite: true })
    // The operations override is keyed by route, so a promoted table with a
    // read mapping is exactly the unsound combination the boot must refuse.
    expect(() => validatePolicies(promoted, { orderWrite: true }, {
      ...ROUTE_OPERATIONS,
      'POST /gateway/v1/orders': ['userOrders'],
    })).toThrow(/orderWrite/)
  })

  it('rejects a disabledWrite route paired with the order write operation', () => {
    // The ban itself must never be stated together with the write operation:
    // that is the exact combination that would open a transaction path with
    // no credential check.
    expect(() => validatePolicies(routeDefinitions, {}, {
      ...ROUTE_OPERATIONS,
      'POST /gateway/v1/orders': ['userOrderCreate'],
    })).toThrow(/disabledWrite/)
  })
})

describe('GW-304 idempotency key validation', () => {
  it('rejects a missing key with 400 and zero upstream calls', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const res = await postOrder(app, VALID_ORDER, { Authorization: USER_BEARER })
    expect(res.status).toBe(400)
    expect((await res.json() as { error: { code: string; message: string } }).error)
      .toMatchObject({ code: 'VALIDATION_ERROR', message: 'Idempotency-Key header is required' })
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('rejects an empty, whitespace or comma-joined key', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    for (const key of ['', '   ', 'k1,k2', 'a b', 'key with spaces', 'k'.repeat(129), 'ünïcode']) {
      const res = await postOrder(app, VALID_ORDER, {
        Authorization: USER_BEARER, 'Idempotency-Key': key,
      })
      expect(res.status, `key ${JSON.stringify(key)}`).toBe(400)
    }
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('accepts a bounded opaque token and forwards it verbatim', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T9' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const key = 'Order-Key_2026.10~x'
    const res = await postOrder(app, VALID_ORDER, { Authorization: USER_BEARER, 'Idempotency-Key': key })
    expect(res.status).toBe(201)
    const [, init] = fetcher.mock.calls[0] as unknown as [string, RequestInit]
    expect((init.headers as Headers).get('Idempotency-Key')).toBe(key)
  })

  it('validates the key shape as data, independent of the route', () => {
    expect(validateIdempotencyKey(undefined)).toEqual({ valid: false, reason: 'missing' })
    expect(validateIdempotencyKey(null)).toEqual({ valid: false, reason: 'missing' })
    expect(validateIdempotencyKey('  ')).toEqual({ valid: false, reason: 'missing' })
    expect(validateIdempotencyKey('a'.repeat(129))).toEqual({ valid: false, reason: 'too_long' })
    expect(validateIdempotencyKey('has space')).toEqual({ valid: false, reason: 'malformed' })
    expect(validateIdempotencyKey('k1,k2')).toEqual({ valid: false, reason: 'malformed' })
    expect(validateIdempotencyKey('Ok-Key_1.2~3')).toEqual({ valid: true, key: 'Ok-Key_1.2~3' })
  })
})

describe('GW-304 authorization', () => {
  it('requires a bearer before any upstream call', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const missing = await postOrder(app, VALID_ORDER, { 'Idempotency-Key': 'k1' })
    expect(missing.status).toBe(401)
    const malformed = await postOrder(app, VALID_ORDER, {
      Authorization: 'Basic abc', 'Idempotency-Key': 'k1',
    })
    expect(malformed.status).toBe(401)
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('rejects an Origin-less write without a credential, but with one it passes', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const build = (headers: Record<string, string>) => new Request('https://gateway.test/gateway/v1/orders', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: JSON.stringify(VALID_ORDER),
    })
    // No Origin + no bearer: the GW-215 layer refuses before the handler.
    const bare = await app.request(build({ 'Idempotency-Key': 'k1' }))
    expect(bare.status).toBe(401)
    expect(fetcher).not.toHaveBeenCalled()
    // With the bearer the same Origin-less request is accepted.
    const credentialed = await app.request(build({
      'Idempotency-Key': 'k1', Authorization: USER_BEARER,
    }))
    expect(credentialed.status).toBe(201)
    expect(fetcher).toHaveBeenCalledTimes(1)
  })
})

describe('GW-304 input validation', () => {
  it('rejects an unknown field, a bad plan id and a bad period with zero upstream calls', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const bodies: unknown[] = [
      { plan_id: 1, period: 'month_price', admin_discount: 100 },
      { plan_id: 0, period: 'month_price' },
      { plan_id: -3, period: 'month_price' },
      { plan_id: 1.5, period: 'month_price' },
      { plan_id: 1, period: 'forever' },
      { plan_id: 1 },
      { period: 'month_price' },
      { plan_id: 1, period: 'month_price', coupon_code: '' },
      null,
    ]
    for (const body of bodies) {
      const res = await postOrder(app, body, { Authorization: USER_BEARER, 'Idempotency-Key': 'k1' })
      expect(res.status, `body ${JSON.stringify(body)}`).toBe(400)
    }
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('forwards exactly the validated body, nothing more', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T42' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const res = await postOrder(app,
      { plan_id: 7, period: 'year_price', coupon_code: 'SAVE10' },
      { Authorization: USER_BEARER, 'Idempotency-Key': 'k1' })
    expect(res.status).toBe(201)
    expect((await res.json() as { data: unknown }).data).toEqual({ trade_no: 'T42' })
    const [url, init] = upstreamCall(fetcher)
    expect(url).toBe('https://txboard.example/txapi/orders')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body as string)).toEqual({
      plan_id: 7, period: 'year_price', coupon_code: 'SAVE10',
    })
    // The user's own bearer, and only the two idempotency headers.
    const headers = init.headers as Headers
    expect(headers.get('Authorization')).toBe(USER_BEARER)
    expect([...headers.keys()].sort()).toEqual([
      'accept', 'authorization', 'content-type', 'idempotency-key', 'x-idempotency-key-hint',
    ])
  })

  it('never forwards a client X-Idempotency-Key-Hint or any other custom header', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const res = await postOrder(app, VALID_ORDER, {
      Authorization: USER_BEARER,
      'Idempotency-Key': 'k1',
      'X-Idempotency-Key-Hint': 'client-supplied-hint',
    })
    expect(res.status).toBe(201)
    const [, init] = upstreamCall(fetcher)
    const headers = init.headers as Headers
    // The hint upstream is the Gateway's own derivation, never the client's.
    expect(headers.get('X-Idempotency-Key-Hint')).not.toBe('client-supplied-hint')
    expect(headers.get('X-Idempotency-Key-Hint')).toBe(
      orderIdempotencyHint('order.create', VALID_ORDER),
    )
    // Only the two idempotency headers and the standard envelope survive.
    expect([...headers.keys()].sort()).toEqual([
      'accept', 'authorization', 'content-type', 'idempotency-key', 'x-idempotency-key-hint',
    ])
  })

  it('drops a forged proxy header before the write reaches the handler', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const res = await postOrder(app, VALID_ORDER, {
      Authorization: USER_BEARER,
      'Idempotency-Key': 'k1',
      'X-Forwarded-For': '1.2.3.4',
    })
    // GW-204: an untrusted peer claiming to be proxied is rejected 403 before
    // any handler — the forged header never reaches the upstream either.
    expect(res.status).toBe(403)
    expect(fetcher).not.toHaveBeenCalled()
  })
})

describe('GW-304 the idempotency hint is a correlation value, not authority', () => {
  it('derives from the canonical body only, with no user identity', () => {
    const hintA = orderIdempotencyHint('order.create', VALID_ORDER)
    const hintB = orderIdempotencyHint('order.create', { period: 'month_price', plan_id: 1 })
    // Key order must not change the hint: canonical JSON is stable.
    expect(hintA).toBe(hintB)
    expect(hintA).toMatch(/^[0-9a-f]{64}$/)
    // A different plan or period is a different hint.
    expect(orderIdempotencyHint('order.create', { plan_id: 2, period: 'month_price' }))
      .not.toBe(hintA)
    expect(orderIdempotencyHint('order.cancel', VALID_ORDER)).not.toBe(hintA)
  })

  it('canonicalJson sorts keys and keeps arrays and scalars intact', () => {
    expect(canonicalJson({ b: 1, a: [3, { d: 4, c: 5 }] }))
      .toBe('{"a":[3,{"c":5,"d":4}],"b":1}')
    expect(canonicalJson(null)).toBe('null')
    expect(canonicalJson([1, 2])).toBe('[1,2]')
  })

  it('sends the same hint for the same body under different tokens', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    await postOrder(app, VALID_ORDER, { Authorization: 'Bearer tokenone_longer_than_eight', 'Idempotency-Key': 'k1' })
    await postOrder(app, VALID_ORDER, { Authorization: 'Bearer tokentwo_longer_than_eight', 'Idempotency-Key': 'k2' })
    const hints = fetcher.mock.calls.map(([, init]) =>
      ((init as RequestInit).headers as Headers).get('X-Idempotency-Key-Hint'))
    expect(hints[0]).toBe(hints[1])
    expect(hints[0]).toBe(orderIdempotencyHint('order.create', VALID_ORDER))
  })
})

describe('GW-304 error mapping', () => {
  type FetcherMock = ReturnType<typeof vi.fn<() => Promise<Response>>>

  function appWith(fetcher: FetcherMock) {
    return createGatewayApp(
      testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }),
      fetcher as unknown as typeof fetch,
    )
  }

  it('surfaces IDEMPOTENCY_IN_PROGRESS as 409, never a 502', async () => {
    const fetcher = failEnvelope('IDEMPOTENCY_IN_PROGRESS', 409)
    const res = await postOrder(appWith(fetcher), VALID_ORDER, {
      Authorization: USER_BEARER, 'Idempotency-Key': 'k1',
    })
    expect(res.status).toBe(409)
    expect((await res.json() as { error: { code: string } }).error.code)
      .toBe('IDEMPOTENCY_IN_PROGRESS')
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('surfaces IDEMPOTENCY_CONFLICT as 409', async () => {
    const fetcher = failEnvelope('IDEMPOTENCY_CONFLICT', 409)
    const res = await postOrder(appWith(fetcher), VALID_ORDER, {
      Authorization: USER_BEARER, 'Idempotency-Key': 'k1',
    })
    expect(res.status).toBe(409)
    expect((await res.json() as { error: { code: string } }).error.code)
      .toBe('IDEMPOTENCY_CONFLICT')
  })

  it('maps a business refusal (insufficient balance, invalid plan) to ORDER_REJECTED', async () => {
    for (const code of ['INSUFFICIENT_BALANCE', 'PLAN_NOT_FOUND', 'PLAN_NOT_AVAILABLE',
      'PERIOD_NOT_ALLOWED', 'COUPON_NOT_FOUND', 'COUPON_EXPIRED']) {
      const fetcher = failEnvelope(code, 422)
      const res = await postOrder(appWith(fetcher), VALID_ORDER, {
        Authorization: USER_BEARER, 'Idempotency-Key': 'k1',
      })
      expect(res.status, code).toBe(422)
      const error = (await res.json() as { error: { code: string; message: string } }).error
      expect(error).toMatchObject({ code: 'ORDER_REJECTED' })
      // The upstream's own message is never echoed back.
      expect(error.message).toBe('Invalid request data')
    }
  })

  it('keeps the generic mapping for an unmapped upstream failure', async () => {
    const fetcher = vi.fn(async () => Response.json(
      { status: 'fail', message: 'boom', data: null, error: null, code: 'SOMETHING_ELSE' },
      { status: 500 },
    ))
    const res = await postOrder(appWith(fetcher), VALID_ORDER, {
      Authorization: USER_BEARER, 'Idempotency-Key': 'k1',
    })
    expect(res.status).toBe(502)
    expect((await res.json() as { error: { code: string } }).error.code).toBe('UPSTREAM_ERROR')
  })

  it('rejects a malformed upstream success with 502, never a fabricated order', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ order_id: 1 })))
    const res = await postOrder(appWith(fetcher), VALID_ORDER, {
      Authorization: USER_BEARER, 'Idempotency-Key': 'k1',
    })
    expect(res.status).toBe(502)
  })

  it('does not leak an upstream error message into the response', async () => {
    const fetcher = vi.fn(async () => Response.json(
      { status: 'fail', message: 'user 42 balance=900 admin_key=private', data: null, error: null },
      { status: 422 },
    ))
    const res = await postOrder(appWith(fetcher), VALID_ORDER, {
      Authorization: USER_BEARER, 'Idempotency-Key': 'k1',
    })
    const json = JSON.stringify(await res.json())
    expect(json).not.toContain('admin_key')
    expect(json).not.toContain('balance=900')
  })
})

describe('GW-304 capability advertisement and hardening', () => {
  it('advertises orders.create only when the write layer is enabled', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ app_name: 'x' })))
    const off = await createGatewayApp(testConfig(), fetcher).request('/gateway/v1/bootstrap')
    expect((await off.json() as { data: { capabilities: string[] } }).data.capabilities)
      .not.toContain('orders.create')
    // The GW-218 public cache is process-wide: without an invalidation the
    // enabled response would be served from the disabled one's entry.
    invalidateCache()
    const on = await createGatewayApp(
      testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher,
    ).request('/gateway/v1/bootstrap')
    expect((await on.json() as { data: { capabilities: string[] } }).data.capabilities)
      .toContain('orders.create')
  })

  it('preflight-allows Idempotency-Key for an allowed origin only', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({})))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const allowed = await app.request('/gateway/v1/orders', {
      method: 'OPTIONS',
      headers: {
        Origin: 'https://theme.example',
        'Access-Control-Request-Method': 'POST',
        'Access-Control-Request-Headers': 'idempotency-key',
      },
    })
    expect(allowed.status).toBe(204)
    expect(allowed.headers.get('Access-Control-Allow-Headers')).toContain('Idempotency-Key')
    const denied = await app.request('/gateway/v1/orders', {
      method: 'OPTIONS',
      headers: { Origin: 'https://evil.example', 'Access-Control-Request-Method': 'POST' },
    })
    expect(denied.status).toBe(403)
  })

  it('rate-limits the write with its own tighter fail-closed policy', () => {
    const write = resolveRoutePolicy('/gateway/v1/orders', 'POST')!
    expect(write.id).toBe('user-order-write')
    expect(write.failClosed).toBe(true)
    expect(write.subjectFrom).toBe('bearer')
    const read = resolveRoutePolicy('/gateway/v1/orders', 'GET')!
    expect(read.id).toBe('user-orders')
    expect(write.ipLimit).toBeLessThan(read.ipLimit)
  })

  it('never caches the write response', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const first = await postOrder(app, VALID_ORDER, { Authorization: USER_BEARER, 'Idempotency-Key': 'k1' })
    const second = await postOrder(app, VALID_ORDER, { Authorization: USER_BEARER, 'Idempotency-Key': 'k2' })
    expect(first.headers.get('X-Cache')).toBeNull()
    expect(second.headers.get('X-Cache')).toBeNull()
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('rejects an oversized write body before any upstream call', async () => {
    const fetcher = vi.fn(async () => Response.json(okEnvelope({ trade_no: 'T1' })))
    const app = createGatewayApp(testConfig({ GATEWAY_ORDER_WRITE_ENABLED: 'true' }), fetcher)
    const res = await app.request('/gateway/v1/orders', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: USER_BEARER,
        'Idempotency-Key': 'k1',
        'Content-Length': '999999',
      },
      body: JSON.stringify(VALID_ORDER),
    })
    expect(res.status).toBe(413)
    expect(fetcher).not.toHaveBeenCalled()
  })
})

function routeKeyOf(definition: { method: string; path: string }): string {
  return `${definition.method} ${definition.path}`
}
