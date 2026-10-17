import { describe, expect, it, vi } from 'vitest'
import { CoreClient, type CoreTransport } from '../src/index.js'

const key = '78a166a1-d6cc-4f11-a214-cb2d1f9b1d1c'

describe('Core SDK contract consumer', () => {
  it('retains decimal identity above JS safe integer through the real typed call', async () => {
    const send = vi.fn<CoreTransport>().mockResolvedValue({
      id: '9007199254740993', trade_no: 'TX-1', status: 0, amount_minor: '1200',
      currency: 'CNY', purchase_terms_version: 'tx-core.purchase-terms/v2',
    })
    const result = await new CoreClient(send).call('commerce.orders.create', { offer_id: '9007199254740993' }, { idempotencyKey: key })
    expect(result.id).toBe('9007199254740993')
    expect(send).toHaveBeenCalledWith('commerce.orders.create', { offer_id: '9007199254740993' }, { idempotencyKey: key })
  })

  it('rejects out-of-range identifiers and input smuggling before invoking transport', async () => {
    const send = vi.fn<CoreTransport>()
    const client = new CoreClient(send)
    await expect(client.call('commerce.orders.create', { offer_id: '9223372036854775808' }, { idempotencyKey: key })).rejects.toMatchObject({ code: 'INPUT_INVALID' })
    await expect(client.call('catalog.offers.list', { actor: 'admin' } as never)).rejects.toMatchObject({ code: 'INPUT_INVALID' })
    expect(send).not.toHaveBeenCalled()
  })

  it('rejects nested unexpected fields and never retries an unknown write result', async () => {
    const send = vi.fn<CoreTransport>().mockResolvedValue({ schema: 'native-wallet/v1', balances: [
      { currency: 'CNY', available_minor: '10', reserved_minor: '0', secret: 'private' },
    ] })
    await expect(new CoreClient(send).call('billing.wallet.get', {})).rejects.toMatchObject({ code: 'OUTPUT_INVALID' })
    send.mockRejectedValueOnce(new Error('result unknown'))
    await expect(new CoreClient(send).call('commerce.orders.create', { offer_id: '1' }, { idempotencyKey: key })).rejects.toThrow('result unknown')
    expect(send).toHaveBeenCalledTimes(2)
  })
})
