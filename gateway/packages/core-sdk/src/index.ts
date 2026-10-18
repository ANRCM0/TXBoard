export { CoreClient, CoreContractError, type CallOptions, type CoreTransport, type CoreResult } from './client.js'
export { appendOrderEventPage, type OrderEvent, type OrderEventHistory } from './order-events.js'
export { createCoreUserClient, CoreUserApiError, type CoreUserClient, type CoreUserClientOptions, type CoreUserCapabilities } from './user.js'
export { ExtensionClient, acceptsModuleSchema, isModuleSchema, parseExtensionDescriptor, parseBridgeRequest,
  type ExtensionDescriptor, type ExtensionOperation, type ExtensionTransport, type BridgeRequest, type JsonSchema } from './extensions.js'

export { coreOperations, type CoreInput, type CoreOperation, type CoreOutput, type CoreMeta } from './contracts.generated.js'
export { createCoreAdminClient, CoreAdminApiError, CORE_ADMIN_OPERATIONS, CORE_ADMIN_READ_OPERATIONS, CORE_ADMIN_WRITE_OPERATIONS,
  type CoreAdminOperation, type CoreAdminReadOperation, type CoreAdminWriteOperation, type CoreAdminClient, type CoreAdminClientOptions } from './admin.js'
export { createCoreAdminSession, StaleAdminContext, type CoreAdminSessionOptions, type CoreAdminSession } from './admin-session.js'
