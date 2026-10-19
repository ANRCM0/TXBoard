import { CoreContractError, type CoreResult } from './client.js'
import { coreOperations, type CoreOutput } from './contracts.generated.js'

export type OrderEvent = CoreOutput<'commerce.orders.events.list'>[number]
export type OrderEventHistory = Readonly<{
  events: readonly OrderEvent[]
  afterSequence: string
  hasMore: boolean
  historyComplete: boolean
}>
type Page = Pick<CoreResult<'commerce.orders.events.list'>, 'data' | 'meta'>
const status = { created: 0, payment_accepted: 1, fulfilled: 3, cancelled: 2 } as const

/** Apply one validated page only after it succeeds. A failed request never advances the cursor. */
export function appendOrderEventPage(previous: OrderEventHistory | undefined, page: Page): OrderEventHistory {
  const spec = coreOperations['commerce.orders.events.list']
  if (!spec.output.safeParse(page.data).success) throw new CoreContractError('OUTPUT_INVALID')
  if (!spec.meta.safeParse(page.meta).success) throw new CoreContractError('META_INVALID')
  let cursor = previous?.afterSequence ?? '0'
  for (const event of page.data) {
    if (BigInt(event.sequence) !== BigInt(cursor) + 1n || event.status !== status[event.type]) {
      throw new CoreContractError('OUTPUT_INVALID')
    }
    cursor = event.sequence
  }
  if (cursor !== page.meta.next_after_sequence || page.meta.has_more && page.data.length === 0
      || previous?.historyComplete && !page.meta.history_complete) throw new CoreContractError('META_INVALID')
  return { events: [...previous?.events ?? [], ...page.data], afterSequence: cursor,
    hasMore: page.meta.has_more, historyComplete: page.meta.history_complete }
}
