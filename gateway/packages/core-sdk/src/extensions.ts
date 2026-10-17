import type { CallOptions } from './index.js'

export type JsonSchema = Readonly<Record<string, unknown>>
export type ExtensionOperation = Readonly<{
  actor: 'user' | 'admin'; permission: string | null; idempotency: 'read' | 'domain'
  input: JsonSchema; output: JsonSchema; recovery?: boolean
}>
export type ExtensionDescriptor = Readonly<{
  schema: 'module-presentation/v1'; module: string; version: string; fingerprint: string
  actor: 'user' | 'admin'; title: string; entry: string; assets: readonly string[]
  operations: Readonly<Record<string, ExtensionOperation>>
  configuration_schema?: JsonSchema; configuration?: Readonly<Record<string, unknown>>; configuration_version?: string
}>
export type ExtensionTransport = (operation: string, input: unknown, options: CallOptions) => Promise<unknown>

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** The closed module Schema vocabulary matches the host validator, including nested unknown-field rejection. */
export function acceptsModuleSchema(value: unknown, schema: JsonSchema, depth = 0): boolean {
  if (depth > 24) return false
  if (Array.isArray(schema.anyOf)) return schema.anyOf.some(variant => record(variant) && acceptsModuleSchema(value, variant, depth + 1))
  if (Object.hasOwn(schema, 'const')) return Object.is(value, schema.const)
  if (Array.isArray(schema.enum) && !schema.enum.some(item => Object.is(item, value))) return false
  switch (schema.type) {
    case 'null': return value === null
    case 'boolean': return typeof value === 'boolean'
    case 'integer': return typeof value === 'number' && Number.isSafeInteger(value)
      && (typeof schema.minimum !== 'number' || value >= schema.minimum) && (typeof schema.maximum !== 'number' || value <= schema.maximum)
    case 'string': {
      if (typeof value !== 'string') return false
      const length = [...value].length
      if (typeof schema.minLength === 'number' && length < schema.minLength || typeof schema.maxLength === 'number' && length > schema.maxLength) return false
      if (typeof schema.pattern === 'string') {
        try { if (!new RegExp(schema.pattern, 'u').test(value)) return false } catch { return false }
      }
      const maximum = schema['x-decimal-maximum']
      return typeof maximum !== 'string' || value.length < maximum.length || value.length === maximum.length && value <= maximum
    }
    case 'array': return Array.isArray(value) && typeof schema.maxItems === 'number' && value.length <= schema.maxItems
      && (typeof schema.minItems !== 'number' || value.length >= schema.minItems) && record(schema.items)
      && value.every(item => acceptsModuleSchema(item, schema.items as JsonSchema, depth + 1))
    case 'object': {
      if (!record(value) || !record(schema.properties) || schema.additionalProperties !== false || !Array.isArray(schema.required)) return false
      const properties = schema.properties
      return schema.required.every(field => typeof field === 'string' && Object.hasOwn(value, field))
        && Object.entries(value).every(([field, item]) => Object.hasOwn(properties, field) && record(properties[field]) && acceptsModuleSchema(item, properties[field], depth + 1))
    }
    default: return false
  }
}

export function parseExtensionDescriptor(value: unknown): ExtensionDescriptor {
  if (!record(value) || value.schema !== 'module-presentation/v1' || typeof value.module !== 'string' || !/^[a-z][a-z0-9_]{0,63}$/.test(value.module)
    || typeof value.version !== 'string' || value.version.length > 80 || typeof value.fingerprint !== 'string' || !/^[0-9a-f]{64}$/.test(value.fingerprint)
    || !['user', 'admin'].includes(String(value.actor)) || typeof value.title !== 'string' || value.title.length > 80
    || !Array.isArray(value.assets) || value.assets.length > 32 || typeof value.entry !== 'string' || !value.assets.includes(value.entry)
    || !value.assets.every(asset => typeof asset === 'string' && /^ui\/[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\.(html|js|css|svg|png|woff2)$/.test(asset))
    || !record(value.operations) || Object.keys(value.operations).length > 64) throw new Error('MODULE_DESCRIPTOR_INVALID')
  for (const [id, spec] of Object.entries(value.operations)) {
    if (!/^[a-z][a-z0-9.-]{0,63}$/.test(id) || !record(spec) || spec.actor !== value.actor
      || !['read', 'domain'].includes(String(spec.idempotency)) || !record(spec.input) || !record(spec.output)) throw new Error('MODULE_DESCRIPTOR_INVALID')
  }
  return value as unknown as ExtensionDescriptor
}

export class ExtensionClient {
  constructor(readonly descriptor: ExtensionDescriptor, private readonly transport: ExtensionTransport) {}

  async call(operation: string, input: unknown, options: CallOptions = {}): Promise<unknown> {
    if (!Object.hasOwn(this.descriptor.operations, operation)) throw new Error('MODULE_OPERATION_NOT_FOUND')
    const spec = this.descriptor.operations[operation]!
    if (!acceptsModuleSchema(input, spec.input)) throw new Error('MODULE_INPUT_INVALID')
    if (spec.idempotency !== 'read' && !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(options.idempotencyKey ?? '')) {
      throw new Error('MODULE_IDEMPOTENCY_KEY_REQUIRED')
    }
    const output = await this.transport(operation, input, options)
    if (!acceptsModuleSchema(output, spec.output)) throw new Error('MODULE_OUTPUT_INVALID')
    return output
  }
}

export type BridgeRequest = Readonly<{ channel: 'core-module/v1'; id: string; operation: string; input: unknown; idempotencyKey?: string }>

/** Source-window verification belongs to the browser host; no role, URL or credentials can be supplied by the frame. */
export function parseBridgeRequest(value: unknown): BridgeRequest | null {
  if (!record(value) || value.channel !== 'core-module/v1' || typeof value.id !== 'string' || !/^[a-zA-Z0-9_-]{1,80}$/.test(value.id)
    || typeof value.operation !== 'string' || !/^[a-z][a-z0-9.-]{0,63}$/.test(value.operation) || !Object.hasOwn(value, 'input')
    || Object.keys(value).some(key => !['channel', 'id', 'operation', 'input', 'idempotencyKey'].includes(key))
    || Object.hasOwn(value, 'idempotencyKey') && typeof value.idempotencyKey !== 'string') return null
  try { if (JSON.stringify(value).length > 32768) return null } catch { return null }
  return value as BridgeRequest
}
