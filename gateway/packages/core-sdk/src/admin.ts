import { createBrowserCoreClient, CoreUserApiError, type CoreUserClientOptions, type CoreUserCapabilities } from './user.js'
import type { CallOptions, CoreResult } from './client.js'
import type { CoreInput, CoreOutput } from './contracts.generated.js'

export const CORE_ADMIN_OPERATIONS = [
  'identity.admin-session.create', 'identity.admin.get', 'identity.sessions.list',
  'identity.sessions.revoke', 'identity.session.end', 'network.nodes.list',
  'network.machines.list', 'settings.site.get',
] as const
export type CoreAdminOperation = (typeof CORE_ADMIN_OPERATIONS)[number]
export type CoreAdminClientOptions = CoreUserClientOptions & {
  getAdminPath: () => string | null | undefined | Promise<string | null | undefined>
}
export type CoreAdminClient = {
  call<K extends CoreAdminOperation>(operation: K, input: CoreInput<K>, options?: CallOptions): Promise<CoreOutput<K>>
  callResult<K extends CoreAdminOperation>(operation: K, input: CoreInput<K>, options?: CallOptions): Promise<CoreResult<K>>
  capabilities(options?: { signal?: AbortSignal }): Promise<CoreUserCapabilities>
}
export { CoreUserApiError as CoreAdminApiError }

export function createCoreAdminClient(options: CoreAdminClientOptions): CoreAdminClient {
  return createBrowserCoreClient(options, { domain: 'admin', contract: 'core-admin/v1',
    prefix: '/gateway/v1/core/admin', operations: CORE_ADMIN_OPERATIONS, getAdminPath: options.getAdminPath })
}
