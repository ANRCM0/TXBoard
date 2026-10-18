import { createBrowserCoreClient, CoreUserApiError, type CoreUserClientOptions, type CoreUserCapabilities } from './user.js'
import type { CallOptions, CoreResult } from './client.js'
import type { CoreInput, CoreOutput } from './contracts.generated.js'
import { CORE_ADMIN_OPERATIONS, type CoreAdminOperation } from './admin.generated.js'
export { CORE_ADMIN_OPERATIONS, CORE_ADMIN_READ_OPERATIONS, CORE_ADMIN_WRITE_OPERATIONS,
  type CoreAdminOperation, type CoreAdminReadOperation, type CoreAdminWriteOperation } from './admin.generated.js'
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
