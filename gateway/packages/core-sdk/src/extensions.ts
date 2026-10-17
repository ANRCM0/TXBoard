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

function patternExpression(pattern: string): RegExp {
  let source = '', escaped = false, bracket = false
  for (const character of pattern) {
    if (escaped) { source += character; escaped = false }
    else if (character === '\\') { source += character; escaped = true }
    else if (character === '[') { source += character; bracket = true }
    else if (character === ']') { source += character; bracket = false }
    else source += character === '$' && !bracket ? '(?![\\s\\S])' : character
  }
  return new RegExp(source, 'u')
}

export function isModuleSchema(schema: unknown, depth = 0): schema is JsonSchema {
  if (!record(schema) || depth > 20 || Object.keys(schema).some(key => !['anyOf', 'const', 'enum', 'type', 'properties', 'required', 'additionalProperties',
    'items', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'x-decimal-maximum'].includes(key))) return false
  if (Array.isArray(schema.anyOf)) return Object.keys(schema).length === 1 && schema.anyOf.length >= 2 && schema.anyOf.length <= 8 && schema.anyOf.every(item => isModuleSchema(item, depth + 1))
  if (Object.hasOwn(schema, 'const')) return Object.keys(schema).length === 1 && (schema.const === null || ['string', 'boolean'].includes(typeof schema.const)
    || typeof schema.const === 'number' && Number.isSafeInteger(schema.const))
  if (!['object', 'array', 'string', 'integer', 'boolean', 'null'].includes(String(schema.type))) return false
  for (const key of ['minLength', 'maxLength', 'minItems', 'maxItems', 'minimum', 'maximum']) {
    const value = schema[key]
    if (value !== undefined && (typeof value !== 'number' || !Number.isSafeInteger(value) || !['minimum', 'maximum'].includes(key) && value < 0)) return false
  }
  for (const [minimum, maximum] of [['minLength', 'maxLength'], ['minItems', 'maxItems'], ['minimum', 'maximum']]) {
    if (typeof schema[minimum!] === 'number' && typeof schema[maximum!] === 'number' && (schema[minimum!] as number) > (schema[maximum!] as number)) return false
  }
  if (schema.enum !== undefined && (!Array.isArray(schema.enum) || schema.enum.length < 1 || schema.enum.length > 64
    || schema.enum.some(value => value !== null && !['string', 'boolean'].includes(typeof value) && !(typeof value === 'number' && Number.isSafeInteger(value))))) return false
  if (schema['x-decimal-maximum'] !== undefined && (schema.type !== 'string' || typeof schema['x-decimal-maximum'] !== 'string'
    || !/^(?:0|[1-9][0-9]{0,18})(?![\s\S])/u.test(schema['x-decimal-maximum']))) return false
  if (schema.type === 'object' && (!record(schema.properties) || schema.additionalProperties !== false || Object.keys(schema.properties).length > 64
    || !Array.isArray(schema.required) || new Set(schema.required).size !== schema.required.length || !schema.required.every(name => typeof name === 'string' && Object.hasOwn(schema.properties as object, name))
    || !Object.entries(schema.properties).every(([name, property]) => /^[a-z][a-z0-9_]{0,63}(?![\s\S])/.test(name) && isModuleSchema(property, depth + 1)))) return false
  if (schema.type === 'array' && (typeof schema.maxItems !== 'number' || !Number.isInteger(schema.maxItems) || schema.maxItems < 0 || schema.maxItems > 1000
    || !isModuleSchema(schema.items, depth + 1))) return false
  if (schema.pattern !== undefined) {
    if (typeof schema.pattern !== 'string' || schema.pattern.length > 512) return false
    try { patternExpression(schema.pattern) } catch { return false }
  }
  return true
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
        try { if (!patternExpression(schema.pattern).test(value)) return false } catch { return false }
      }
      const maximum = schema['x-decimal-maximum']
      return typeof maximum !== 'string' || /^(?:0|[1-9][0-9]*)(?![\s\S])/u.test(value)
        && (value.length < maximum.length || value.length === maximum.length && value <= maximum)
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
  if (!record(value) || Object.keys(value).some(key => !['schema', 'module', 'version', 'fingerprint', 'actor', 'title', 'entry', 'assets', 'operations',
    'configuration_schema', 'configuration', 'configuration_version'].includes(key)) || value.schema !== 'module-presentation/v1' || typeof value.module !== 'string' || !/^[a-z][a-z0-9_]{0,63}(?![\s\S])/.test(value.module)
    || typeof value.version !== 'string' || value.version.length > 80 || typeof value.fingerprint !== 'string' || !/^[0-9a-f]{64}(?![\s\S])/.test(value.fingerprint)
    || !['user', 'admin'].includes(String(value.actor)) || typeof value.title !== 'string' || value.title.length > 80
    || !Array.isArray(value.assets) || value.assets.length > 32 || new Set(value.assets).size !== value.assets.length || typeof value.entry !== 'string' || !value.entry.endsWith('.html') || !value.assets.includes(value.entry)
    || !value.assets.every(asset => typeof asset === 'string' && /^ui\/[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\.(html|js|css|svg|png|woff2)(?![\s\S])/.test(asset))
    || !record(value.operations) || Object.keys(value.operations).length > 64) throw new Error('MODULE_DESCRIPTOR_INVALID')
  for (const [id, spec] of Object.entries(value.operations)) {
    if (!/^[a-z][a-z0-9.-]{0,63}(?![\s\S])/.test(id) || !record(spec) || spec.actor !== value.actor
      || Object.keys(spec).some(key => !['actor', 'permission', 'idempotency', 'input', 'output', 'recovery'].includes(key))
      || !['read', 'domain'].includes(String(spec.idempotency)) || !isModuleSchema(spec.input) || !isModuleSchema(spec.output)
      || spec.recovery !== undefined && typeof spec.recovery !== 'boolean'
      || value.actor === 'user' && spec.permission !== null
      || value.actor === 'admin' && (typeof spec.permission !== 'string' || !spec.permission.startsWith(`extensions.${value.module}.`))) throw new Error('MODULE_DESCRIPTOR_INVALID')
  }
  if (value.configuration_schema !== undefined || value.configuration !== undefined || value.configuration_version !== undefined) {
    if (value.actor !== 'admin' || !isModuleSchema(value.configuration_schema) || !acceptsModuleSchema(value.configuration, value.configuration_schema)
      || typeof value.configuration_version !== 'string' || !/^[1-9][0-9]{0,18}(?![\s\S])/.test(value.configuration_version)) throw new Error('MODULE_DESCRIPTOR_INVALID')
  }
  return value as unknown as ExtensionDescriptor
}

export class ExtensionClient {
  constructor(readonly descriptor: ExtensionDescriptor, private readonly transport: ExtensionTransport) {}

  async call(operation: string, input: unknown, options: CallOptions = {}): Promise<unknown> {
    if (!Object.hasOwn(this.descriptor.operations, operation)) throw new Error('MODULE_OPERATION_NOT_FOUND')
    const spec = this.descriptor.operations[operation]!
    if (!acceptsModuleSchema(input, spec.input)) throw new Error('MODULE_INPUT_INVALID')
    if (spec.idempotency !== 'read' && !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(?![\s\S])/i.test(options.idempotencyKey ?? '')) {
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
  if (!record(value) || value.channel !== 'core-module/v1' || typeof value.id !== 'string' || !/^[a-zA-Z0-9_-]{1,80}(?![\s\S])/.test(value.id)
    || typeof value.operation !== 'string' || !/^[a-z][a-z0-9.-]{0,63}(?![\s\S])/.test(value.operation) || !Object.hasOwn(value, 'input')
    || Object.keys(value).some(key => !['channel', 'id', 'operation', 'input', 'idempotencyKey'].includes(key))
    || Object.hasOwn(value, 'idempotencyKey') && typeof value.idempotencyKey !== 'string') return null
  try { if (JSON.stringify(value).length > 32768) return null } catch { return null }
  return value as BridgeRequest
}
