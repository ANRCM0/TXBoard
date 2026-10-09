import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi } from './client'
import { checkGiftCard, redeemGiftCard, fetchGiftCardHistory, fetchGiftCardDetail } from './gift-card'

const oldNative = nativeApi.defaults.adapter
const oldLegacy = api.defaults.adapter
const seen: string[] = []

beforeEach(() => {
  seen.length = 0
  nativeApi.defaults.adapter = async config => {
    seen.push(`${config.method} ${config.url}`)
    return {
      data: { request_id: 'gift-trace', data: config.url === '/gift-cards/history'
        ? { data: [], pagination: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
        : { id: 7 } },
      status: 200, statusText: 'OK', headers: {}, config,
    } as never
  }
  api.defaults.adapter = async () => { throw new Error('Unexpected V1 gift-card request') }
})
afterEach(() => {
  nativeApi.defaults.adapter = oldNative
  api.defaults.adapter = oldLegacy
})

describe('native gift-card adapter', () => {
  it('checks and redeems via TXAPI', async () => {
    await checkGiftCard('gift-test')
    await redeemGiftCard('gift-test')
    expect(seen).toEqual(['post /gift-cards/check', 'post /gift-cards/redeem'])
  })
  it('unwraps native history and scopes detail path', async () => {
    const history = await fetchGiftCardHistory()
    expect(history.pagination.total).toBe(0)
    await fetchGiftCardDetail(7)
    expect(seen).toEqual(['get /gift-cards/history', 'get /gift-cards/history/7'])
  })
})
