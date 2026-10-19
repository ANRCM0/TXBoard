import { afterEach, describe, expect, it, vi } from 'vitest'
import { CoreClient, createCoreUserClient } from '../src/index.js'

const key = '78a166a1-d6cc-4f11-a214-cb2d1f9b1d1c'
const order = { id: '9007199254740993', trade_no: 'TX-1', status: 0, amount_minor: '1200',
  currency: 'CNY', purchase_terms_version: 'tx-core.purchase-terms/v2' }
const envelope = (data: unknown, coreMeta: unknown = {}) => ({ ok: true, data,
  meta: { version: '1', requestId: 'gateway-trace', coreRequestId: key, coreMeta } })
afterEach(() => vi.useRealTimers())

describe('browser Core SDK', () => {
  it('keeps canonical decimals and pagination metadata without changing call data semantics', async () => {
    const send = vi.fn<typeof fetch>().mockImplementation(async () => Response.json(envelope([], {
      next_after_id: '9007199254740993',
    })))
    const client = createCoreUserClient({ getToken: () => 'user-token-long', fetchImpl: send })
    const result = await client.callResult('billing.recharges.list', {})
    expect(result).toEqual({ data: [], meta: { next_after_id: '9007199254740993' }, requestId: key })
    expect(await client.call('billing.recharges.list', {})).toEqual([])
    const [url, init] = send.mock.calls[0]!
    expect(url).toBe('/gateway/v1/core/user/operations/billing.recharges.list')
    expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer user-token-long')
    expect(JSON.parse(init?.body as string)).toEqual({ input: {} })
    expect(init).toMatchObject({ method: 'POST', credentials: 'omit', redirect: 'error', cache: 'no-store' })
  })

  it('requires the original action key and never retries an unknown write', async () => {
    const send = vi.fn<typeof fetch>().mockRejectedValueOnce(new Error('PRIVATE_TOKEN'))
      .mockResolvedValueOnce(Response.json(envelope(order)))
    const client = createCoreUserClient({ getToken: () => 'user-token-long', fetchImpl: send })
    await expect(client.call('commerce.orders.create', { offer_id: '1' })).rejects.toMatchObject({ code: 'IDEMPOTENCY_KEY_REQUIRED' })
    expect(send).not.toHaveBeenCalled()
    await expect(client.call('commerce.orders.create', { offer_id: '1' }, { idempotencyKey: key }))
      .rejects.toMatchObject({ code: 'RESULT_UNKNOWN', needsStateConfirmation: true, message: 'RESULT_UNKNOWN' })
    expect(send).toHaveBeenCalledTimes(1)
    expect(await client.call('commerce.orders.create', { offer_id: '1' }, { idempotencyKey: key })).toEqual(order)
    for (const [, init] of send.mock.calls) expect(new Headers(init?.headers).get('Idempotency-Key')).toBe(key)
  })

  it('rejects malformed metadata and keeps service failure distinct from expired user sessions', async () => {
    const send = vi.fn<typeof fetch>().mockResolvedValueOnce(Response.json(envelope([], { next_after_id: 9007199254740992 })))
      .mockResolvedValueOnce(Response.json({ ok: false, error: { code: 'UPSTREAM_UNAVAILABLE', message: 'private detail' },
        meta: { version: '1', requestId: 'safe-trace' } }, { status: 503 }))
    const client = createCoreUserClient({ getToken: () => 'user-token-long', fetchImpl: send })
    await expect(client.callResult('billing.recharges.list', {})).rejects.toMatchObject({ code: 'INVALID_RESPONSE' })
    await expect(client.call('billing.payment-attempts.query', { attempt_id: '1' }, { idempotencyKey: key }))
      .rejects.toMatchObject({ code: 'UPSTREAM_UNAVAILABLE', status: 503, needsStateConfirmation: true })
  })

  it('bounds streaming bodies including a stalled response after headers', async () => {
    vi.useFakeTimers()
    const cancel = vi.fn()
    const send = vi.fn<typeof fetch>().mockResolvedValue(new Response(new ReadableStream({ cancel }), {
      headers: { 'content-type': 'application/json' },
    }))
    const client = createCoreUserClient({ timeoutMs: 500, fetchImpl: send })
    const pending = expect(client.call('catalog.offers.list', {})).rejects.toMatchObject({ code: 'RESULT_UNKNOWN' })
    await vi.advanceTimersByTimeAsync(501)
    await pending
    expect(cancel).toHaveBeenCalledTimes(1)
  })

  it('checks discovery versions, omits user secrets from public reads and rejects unsafe origins', async () => {
    const getToken = vi.fn(() => 'private-token')
    const send = vi.fn<typeof fetch>().mockResolvedValueOnce(Response.json({ ok: true,
      data: { contract: 'core-user/v1', operations: [{ id: 'catalog.offers.list', version: '1', actor: 'anonymous', idempotency: 'read' }] },
      meta: { version: '1', requestId: 'trace' } })).mockResolvedValueOnce(Response.json(envelope([])))
    const client = createCoreUserClient({ getToken, fetchImpl: send })
    expect((await client.capabilities()).operations).toHaveLength(1)
    expect(await client.call('catalog.offers.list', {})).toEqual([])
    expect(getToken).not.toHaveBeenCalled()
    expect(() => createCoreUserClient({ baseURL: 'http://example.test/gateway' })).toThrow()
    expect(() => createCoreUserClient({ baseURL: '//example.test/gateway' })).toThrow()
  })

  it('requires a result transport and validates owner metadata independently', async () => {
    await expect(new CoreClient(async () => []).callResult('commerce.orders.list', {}))
      .rejects.toMatchObject({ code: 'RESULT_TRANSPORT_REQUIRED' })
    const result = async () => ({ data: [], meta: { page: 1, per_page: 10, total: 0, last_page: 1 }, requestId: key })
    expect((await new CoreClient(async () => [], result).callResult('commerce.orders.list', {})).meta.total).toBe(0)
  })
})
