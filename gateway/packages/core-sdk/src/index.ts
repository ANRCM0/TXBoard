export { CoreClient, CoreContractError, type CallOptions, type CoreTransport, type CoreResult } from './client.js'
export { createCoreUserClient, CoreUserApiError, type CoreUserClient, type CoreUserClientOptions, type CoreUserCapabilities } from './user.js'
export { ExtensionClient, acceptsModuleSchema, isModuleSchema, parseExtensionDescriptor, parseBridgeRequest,
  type ExtensionDescriptor, type ExtensionOperation, type ExtensionTransport, type BridgeRequest, type JsonSchema } from './extensions.js'

export { coreOperations, type CoreInput, type CoreOperation, type CoreOutput, type CoreMeta } from './contracts.generated.js'
