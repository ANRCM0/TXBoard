import { expect, it, vi } from 'vitest'
import { createThemeClient, THEME_BROWSER_CONTRACT } from '../src/index.js'

const key = '78a166a1-d6cc-4f11-a214-cb2d1f9b1d1c'
const envelope = (data: unknown, coreMeta = {}) => ({ ok: true, data,
  meta: { version: '1', requestId: 'browser-trace', coreRequestId: key, coreMeta } })

it('exposes the current Core contract without legacy account or payment convenience endpoints', async () => {
  const send = vi.fn<typeof fetch>().mockResolvedValue(Response.json(envelope([])))
  const client = createThemeClient({ fetchImpl: send })
  expect(THEME_BROWSER_CONTRACT).toBe('core-browser/v1')
  expect(client).not.toHaveProperty('auth'); expect(client).not.toHaveProperty('orders')
  await expect(client.call('catalog.offers.list', {})).resolves.toEqual([])
  expect(send.mock.calls[0]![0]).toBe('/gateway/v1/core/user/operations/catalog.offers.list')
  expect(send.mock.calls[0]![1]).toMatchObject({ credentials: 'omit', redirect: 'error', cache: 'no-store' })
})

it('keeps exact identifier and pagination metadata through the generated result API', async () => {
  const send = vi.fn<typeof fetch>().mockResolvedValue(Response.json(envelope([], { next_after_id: '9007199254740993' })))
  const client = createThemeClient({ fetchImpl: send, getToken: () => 'theme-user-token' })
  const result = await client.callResult('billing.recharges.list', {})
  expect(result.meta.next_after_id).toBe('9007199254740993')
  expect(new Headers(send.mock.calls[0]![1]?.headers).get('Authorization')).toBe('Bearer theme-user-token')
})

it('retains the caller action key after an unknown write without automatic retries', async () => {
  const order = { id: '9007199254740993', trade_no: 'THEME-1', status: 0, amount_minor: '1200',
    currency: 'CNY', purchase_terms_version: 'tx-core.purchase-terms/v2' }
  const send = vi.fn<typeof fetch>().mockRejectedValueOnce(new Error('lost private response'))
    .mockResolvedValueOnce(Response.json(envelope(order)))
  const client = createThemeClient({ fetchImpl: send, getToken: () => 'theme-user-token' })
  await expect(client.call('commerce.orders.create', { offer_id: '1' })).rejects.toMatchObject({ code: 'IDEMPOTENCY_KEY_REQUIRED' })
  expect(send).not.toHaveBeenCalled()
  await expect(client.call('commerce.orders.create', { offer_id: '1' }, { idempotencyKey: key }))
    .rejects.toMatchObject({ code: 'RESULT_UNKNOWN', needsStateConfirmation: true })
  expect(send).toHaveBeenCalledTimes(1)
  await expect(client.call('commerce.orders.create', { offer_id: '1' }, { idempotencyKey: key })).resolves.toEqual(order)
  for (const [, input] of send.mock.calls) expect(new Headers(input?.headers).get('Idempotency-Key')).toBe(key)
})

it('discovers native capabilities and rejects unsupported input before transport', async () => {
  const send = vi.fn<typeof fetch>().mockResolvedValue(Response.json({ ok: true,
    data: { contract: 'core-user/v1', operations: [{ id: 'catalog.offers.list', version: '1', actor: 'anonymous', idempotency: 'read' }] },
    meta: { version: '1', requestId: 'browser-trace' } }))
  const client = createThemeClient({ fetchImpl: send })
  expect((await client.capabilities()).operations[0]!.id).toBe('catalog.offers.list')
  await expect(client.call('catalog.offers.list', { actor: 'admin' } as never)).rejects.toBeDefined()
  expect(send).toHaveBeenCalledTimes(1)
})
