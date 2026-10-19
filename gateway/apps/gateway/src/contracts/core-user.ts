import { coreOperations, type CoreOperation } from './core.generated.js'

export const CORE_USER_PREFIX = '/gateway/v1/core/user'

/** Reviewed browser surface. Adding a generated/internal operation never exposes it automatically. */
export const CORE_USER_OPERATIONS = [
  'billing.order-payment-attempts.list',
  'identity.session.create',
  'catalog.offers.list', 'commerce.orders.create', 'commerce.orders.list', 'commerce.orders.get', 'commerce.orders.events.list',
  'commerce.orders.checkout', 'commerce.orders.cancel', 'billing.wallet.get', 'billing.payment-methods.list',
  'billing.recharges.create', 'billing.recharges.list', 'billing.recharges.get', 'billing.recharges.checkout',
  'subscription.mine.get', 'usage.mine.list', 'identity.account.get', 'identity.sessions.list',
  'identity.sessions.revoke', 'identity.session.end', 'billing.payment-attempts.get', 'billing.payment-attempts.query',
] as const satisfies readonly CoreOperation[]

export type CoreUserOperation = (typeof CORE_USER_OPERATIONS)[number]
const admitted = new Set<string>(CORE_USER_OPERATIONS)
export const isCoreUserOperation = (value: string): value is CoreUserOperation => admitted.has(value)
export const requiresCommerceWrites = (id: CoreUserOperation): boolean =>
  coreOperations[id].idempotency !== 'read' && !id.startsWith('identity.')
