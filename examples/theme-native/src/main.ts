import { createThemeClient, type CoreOutput } from '@txboard/theme-sdk'

/** The host owns this in-memory token; the SDK never persists it. */
export function createTheme(getToken: () => string | undefined) {
  const client = createThemeClient({ getToken })
  return {
    capabilities: () => client.capabilities(),
    offers: (): Promise<CoreOutput<'catalog.offers.list'>> => client.call('catalog.offers.list', {}),
    account: () => client.call('identity.account.get', {}),
    order: (tradeNo: string) => client.call('commerce.orders.get', { trade_no: tradeNo }),
    // Keep input and key unchanged if a response is unknown; ask the user before retrying.
    purchase: (offerId: string, idempotencyKey: string) => client.call('commerce.orders.create',
      { offer_id: offerId }, { idempotencyKey }),
  }
}
