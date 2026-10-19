import { describe, expect, it, vi } from 'vitest'
import { appendOrderEventPage, createCoreAdminClient, createCoreUserClient, type OrderEvent, type OrderEventHistory } from '../src/index.js'

const event = (sequence = '1', type: OrderEvent['type'] = 'created', status = 0): OrderEvent => ({ sequence, type, status, occurred_at: '2026-10-19T00:00:00Z' })
const page = (data: OrderEvent[], cursor = data.at(-1)?.sequence ?? '0', complete = true, more = false) => ({ data, meta: { next_after_sequence: cursor, history_complete: complete, has_more: more } })
const requestId = '11111111-1111-4111-8111-111111111111'

describe('order event cursor application', () => {
  it('applies contiguous pages atomically and rejects replay, gaps and backwards events', () => {
    const first = appendOrderEventPage(undefined, page([event()], '1', true, true))
    const second = appendOrderEventPage(first, page([event('2', 'payment_accepted', 1)]))
    expect(second.events).toHaveLength(2)
    expect(second.afterSequence).toBe('2')
    for (const value of ['1', '3']) expect(() => appendOrderEventPage(first, page([event(value)]))).toThrow('OUTPUT_INVALID')
    expect(first.events).toHaveLength(1)
    expect(first.afterSequence).toBe('1')
    expect(appendOrderEventPage(second, page([], '2'))).toEqual(second)
  })

  it('preserves signed 64-bit decimal precision through continuation', () => {
    const previous: OrderEventHistory = { events: [], afterSequence: '9007199254740992', hasMore: true, historyComplete: false }
    const applied = appendOrderEventPage(previous, page([event('9007199254740993')], '9007199254740993', false))
    expect(applied.afterSequence).toBe('9007199254740993')
    expect(applied.historyComplete).toBe(false)
  })

  it('rejects inconsistent status, metadata, noncanonical decimals and injected fields', () => {
    expect(() => appendOrderEventPage(undefined, page([event('1', 'fulfilled', 0)]))).toThrow('OUTPUT_INVALID')
    for (const sequence of ['01', '1e0', '9223372036854775808']) {
      expect(() => appendOrderEventPage(undefined, page([event(sequence)]))).toThrow('OUTPUT_INVALID')
    }
    expect(() => appendOrderEventPage(undefined, page([{ ...event(), token: 'secret' } as OrderEvent]))).toThrow('OUTPUT_INVALID')
    expect(() => appendOrderEventPage(undefined, page([event()], '2'))).toThrow('META_INVALID')
    expect(() => appendOrderEventPage(undefined, page([], '0', true, true))).toThrow('META_INVALID')
    const initial = appendOrderEventPage(undefined, page([event()]))
    expect(() => appendOrderEventPage(initial, page([], '1', false))).toThrow('META_INVALID')
  })
})

it.each(['user', 'admin'] as const)('transports %s event reads with owner metadata and rejects malformed responses', async domain => {
  const result = page([event('9007199254740993')], '9007199254740993', false)
  const send = vi.fn<typeof fetch>().mockResolvedValueOnce(Response.json({ ok: true, data: result.data,
    meta: { version: '1', requestId: 'gateway-trace', coreRequestId: requestId, coreMeta: result.meta } }))
    .mockResolvedValueOnce(Response.json({ ok: true, data: [{ ...event(), sequence: 1 }],
      meta: { version: '1', requestId: 'gateway-trace', coreRequestId: requestId, coreMeta: result.meta } }))
  const options = { getToken: () => 'original-actor-token', fetchImpl: send }
  const client = domain === 'user' ? createCoreUserClient(options) : createCoreAdminClient({ ...options, getAdminPath: () => 'admin_path' })
  const operation = domain === 'user' ? 'commerce.orders.events.list' : 'commerce.admin-orders.events.list'
  const input = { trade_no: 'TX-1', after_sequence: '9007199254740992', limit: 10 }
  expect(await client.callResult(operation as never, input)).toEqual({ ...result, requestId })
  const [url, init] = send.mock.calls[0]!
  expect(url).toBe(`/gateway/v1/core/${domain}/operations/${operation}`)
  expect(JSON.parse(init?.body as string)).toEqual({ input })
  expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer original-actor-token')
  expect(new Headers(init?.headers).has('Idempotency-Key')).toBe(false)
  if (domain === 'admin') expect(new Headers(init?.headers).get('X-Core-Admin-Path')).toBe('admin_path')
  await expect(client.callResult(operation as never, input)).rejects.toMatchObject({ code: 'INVALID_RESPONSE' })
})
