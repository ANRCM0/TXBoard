import { coreOperations, type CoreOperation } from './core.generated.js'

export const CORE_USER_PREFIX = '/gateway/v1/core/user'

/** Reviewed browser surface. Adding a generated/internal operation never exposes it automatically. */
export const CORE_USER_OPERATIONS = [
  'support.tickets.list',
  'support.tickets.get',
  'support.tickets.messages.list',
  'support.tickets.create',
  'support.tickets.reply',
  'support.tickets.close',
  'content.user-notices.list',
  'content.user-notices.get',
  'content.user-knowledge.list',
  'content.user-knowledge.get',
  'content.user-knowledge.categories.list',
  'billing.gift-batches.list',
  'billing.gift-batches.get',
  'billing.gift-batches.issue',
  'support.tickets.attachments.begin',
  'support.tickets.attachments.append',
  'support.tickets.attachments.finish',
  'support.tickets.attachments.cancel',
  'support.tickets.attachments.get',
  'support.tickets.attachments.download',

  'billing.order-payment-attempts.list',
  'identity.referrals.get',
  'identity.referrals.code.create',
  'identity.referrals.bind',
  'billing.commissions.balance.get',
  'billing.commissions.policy.get',
  'billing.commissions.rewards.list',
  'billing.commissions.payouts.list',
  'billing.commissions.payouts.request',
  'identity.session.create',
  'subscription.delivery-options.get', 'subscription.deliveries.authorize',
  'catalog.offers.list', 'commerce.orders.create', 'commerce.orders.list', 'commerce.orders.get', 'commerce.orders.events.list',
  'commerce.orders.checkout', 'commerce.orders.cancel', 'billing.wallet.get', 'billing.payment-methods.list',
  'billing.gift-cards.list', 'billing.gift-cards.issue', 'billing.gift-cards.redeem', 'billing.gift-cards.revoke',
  'billing.recharges.create', 'billing.recharges.list', 'billing.recharges.get', 'billing.recharges.checkout',
  'subscription.mine.get', 'usage.mine.list', 'identity.account.get', 'identity.sessions.list',
  'identity.sessions.revoke', 'identity.session.end', 'billing.payment-attempts.get', 'billing.payment-attempts.query',
] as const satisfies readonly CoreOperation[]

export type CoreUserOperation = (typeof CORE_USER_OPERATIONS)[number]
const admitted = new Set<string>(CORE_USER_OPERATIONS)
export const isCoreUserOperation = (value: string): value is CoreUserOperation => admitted.has(value)
export const requiresCommerceWrites = (id: CoreUserOperation): boolean =>
  coreOperations[id].idempotency !== 'read' && (id.startsWith('commerce.') || id.startsWith('billing.'))
