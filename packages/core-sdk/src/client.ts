import { z } from 'zod'
import { coreOperations, type CoreInput, type CoreOperation, type CoreOutput, type CoreMeta } from './contracts.generated.js'

export type CallOptions = Readonly<{ idempotencyKey?: string; signal?: AbortSignal }>
export type CoreTransport = (operation: CoreOperation, input: unknown, options: CallOptions) => Promise<unknown>
export type CoreResult<K extends CoreOperation> = Readonly<{ data: CoreOutput<K>; meta: CoreMeta<K>; requestId: string }>
const resultEnvelope = z.strictObject({ data: z.unknown(), meta: z.unknown(), requestId: z.uuid() })

export class CoreContractError extends Error {
  constructor(readonly code: 'INPUT_INVALID' | 'OUTPUT_INVALID' | 'IDEMPOTENCY_KEY_REQUIRED'
    | 'RESULT_TRANSPORT_REQUIRED' | 'RESULT_INVALID' | 'META_INVALID') {
    super(code)
    this.name = 'CoreContractError'
  }
}

/** The host supplies its authenticated transport. No service credentials or implicit retry. */
export class CoreClient {
  constructor(private readonly transport: CoreTransport, private readonly resultTransport?: CoreTransport) {}

  private input<K extends CoreOperation>(operation: K, input: CoreInput<K>, options: CallOptions): unknown {
    if (!Object.hasOwn(coreOperations, operation)) throw new CoreContractError('INPUT_INVALID')
    const spec = coreOperations[operation]
    const checked = spec.input.safeParse(input)
    if (!checked.success) throw new CoreContractError('INPUT_INVALID')
    if (spec.idempotency !== 'read' && !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(options.idempotencyKey ?? '')) {
      throw new CoreContractError('IDEMPOTENCY_KEY_REQUIRED')
    }
    return checked.data
  }

  async call<K extends CoreOperation>(operation: K, input: CoreInput<K>, options: CallOptions = {}): Promise<CoreOutput<K>> {
    const value = this.input(operation, input, options)
    const result = coreOperations[operation].output.safeParse(await this.transport(operation, value, options))
    if (!result.success) throw new CoreContractError('OUTPUT_INVALID')
    return result.data as CoreOutput<K>
  }

  async callResult<K extends CoreOperation>(operation: K, input: CoreInput<K>, options: CallOptions = {}): Promise<CoreResult<K>> {
    const value = this.input(operation, input, options)
    if (!this.resultTransport) throw new CoreContractError('RESULT_TRANSPORT_REQUIRED')
    const parsed = resultEnvelope.safeParse(await this.resultTransport(operation, value, options))
    if (!parsed.success || !Object.hasOwn(parsed.data, 'data') || !Object.hasOwn(parsed.data, 'meta')) throw new CoreContractError('RESULT_INVALID')
    const data = coreOperations[operation].output.safeParse(parsed.data.data)
    if (!data.success) throw new CoreContractError('OUTPUT_INVALID')
    const meta = coreOperations[operation].meta.safeParse(parsed.data.meta)
    if (!meta.success) throw new CoreContractError('META_INVALID')
    return { data: data.data as CoreOutput<K>, meta: meta.data as CoreMeta<K>, requestId: parsed.data.requestId }
  }
}
