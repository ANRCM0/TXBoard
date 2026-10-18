import type { CoreOperation } from './core.generated.js'
export const CORE_ADMIN_PREFIX = '/gateway/v1/core/admin'
export const CORE_ADMIN_OPERATIONS = [
  'identity.admin-session.create', 'identity.admin.get', 'identity.sessions.list',
  'identity.sessions.revoke', 'identity.session.end', 'network.nodes.list',
  'network.machines.list', 'settings.site.get',
  'identity.accounts.list', 'identity.accounts.get',
  'commerce.admin-orders.list', 'commerce.admin-orders.get',
] as const satisfies readonly CoreOperation[]
export type CoreAdminOperation = (typeof CORE_ADMIN_OPERATIONS)[number]
const admitted = new Set<string>(CORE_ADMIN_OPERATIONS)
export const isCoreAdminOperation = (id: string): id is CoreAdminOperation => admitted.has(id)
