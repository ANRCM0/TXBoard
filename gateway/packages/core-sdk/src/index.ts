import { coreOperations, type CoreInput, type CoreOperation, type CoreOutput } from './contracts.generated.js'
export { ExtensionClient, acceptsModuleSchema, isModuleSchema, parseExtensionDescriptor, parseBridgeRequest,
  type ExtensionDescriptor, type ExtensionOperation, type ExtensionTransport, type BridgeRequest, type JsonSchema } from './extensions.js'

export { coreOperations, type CoreInput, type CoreOperation, type CoreOutput } from './contracts.generated.js'

export type CallOptions = Readonly<{ idempotencyKey?: string; signal?: AbortSignal }>
export type CoreTransport = (operation: CoreOperation, input: unknown, options: CallOptions) => Promise<unknown>

export class CoreContractError extends Error {
  constructor(readonly code: 'INPUT_INVALID' | 'OUTPUT_INVALID' | 'IDEMPOTENCY_KEY_REQUIRED') {
    super(code)
    this.name = 'CoreContractError'
  }
}

/** The host supplies its authenticated transport. No service credentials or implicit retry. */
export class CoreClient {
  constructor(private readonly transport: CoreTransport) {}

  async call<K extends CoreOperation>(operation: K, input: CoreInput<K>, options: CallOptions = {}): Promise<CoreOutput<K>> {
    const spec = coreOperations[operation]
    const checked = spec.input.safeParse(input)
    if (!checked.success) throw new CoreContractError('INPUT_INVALID')
    if (spec.idempotency !== 'read' && !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(options.idempotencyKey ?? '')) {
      throw new CoreContractError('IDEMPOTENCY_KEY_REQUIRED')
    }
    const result = spec.output.safeParse(await this.transport(operation, checked.data, options))
    if (!result.success) throw new CoreContractError('OUTPUT_INVALID')
    return result.data as CoreOutput<K>
  }
}
