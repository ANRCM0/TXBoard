export type NodeConfiguration = {
  type: string; name: string; host: string; port: string; server_port: number
  code: string | null; group_ids: string[]; route_ids: string[]; parent_id: string | null; machine_id: string | null
  show: boolean; enabled: boolean | null; rate: string; rate_time_enable: boolean
  rate_time_ranges: { start: string; end: string; rate: string }[]; tags: string[]; transfer_enable: string | null
  protocol_settings: Record<string, unknown>; custom_outbounds: unknown[]; custom_routes: unknown[]; cert_config: unknown
}
export type NodeProtocolField = {
  key: string; label: string; type: 'text' | 'number' | 'checkbox' | 'textarea' | 'select' | 'json' | 'json-array' | 'json-object' | 'string-list'
  placeholder?: string; full?: boolean; min?: number; max?: number; separator?: string
  options?: { value: string | number; label: string }[]; visible_when?: { field: string; equals?: unknown; one_of?: unknown[] }[]
  templates?: { label: string; value: unknown; transport?: string }[]
  generator?: { kind: 'x25519' | 'hex' | 'ech'; label: string; map: Record<string, string>; params?: { bytes?: number; public_name?: string } }
}
export type NodeProtocol = { type: string; label: string; schema_version: number; defaults: Record<string, unknown>; form_schema: NodeProtocolField[]; certificate_schema?: NodeProtocolField[] }
const idPattern = /^[1-9][0-9]{0,18}$/
const validId = (value: unknown): value is string => typeof value === 'string' && idPattern.test(value) && BigInt(value) <= 9223372036854775807n
const safePath = (path: string) => /^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/.test(path)
  && !path.split('.').some(key => ['__proto__', 'prototype', 'constructor'].includes(key))
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value)

export function parseNodeProtocols(json: string): NodeProtocol[] {
  if (json.length > 131072) throw new Error('INVALID_NODE_PROTOCOL_METADATA')
  const source: unknown = JSON.parse(json)
  if (!Array.isArray(source) || source.length > 32 || !source.length) throw new Error('INVALID_NODE_PROTOCOL_METADATA')
  for (const item of source) {
    if (!record(item) || typeof item.type !== 'string' || typeof item.label !== 'string' || !record(item.defaults)
      || !Array.isArray(item.form_schema) || item.form_schema.length > 200) throw new Error('INVALID_NODE_PROTOCOL_METADATA')
    if (item.certificate_schema !== undefined && (!Array.isArray(item.certificate_schema) || item.certificate_schema.length > 64)) throw new Error('INVALID_NODE_PROTOCOL_METADATA')
    for (const field of [...item.form_schema, ...((item.certificate_schema ?? []) as unknown[])]) {
      if (!record(field) || typeof field.key !== 'string' || !safePath(field.key) || typeof field.label !== 'string'
        || !['text', 'number', 'checkbox', 'textarea', 'select', 'json', 'json-array', 'json-object', 'string-list'].includes(String(field.type))) throw new Error('INVALID_NODE_PROTOCOL_METADATA')
      if (field.visible_when !== undefined && (!Array.isArray(field.visible_when)
        || field.visible_when.some(rule => !record(rule) || typeof rule.field !== 'string' || !safePath(rule.field)
          || rule.one_of !== undefined && (!Array.isArray(rule.one_of) || rule.one_of.length > 32)))) throw new Error('INVALID_NODE_PROTOCOL_METADATA')
      if (field.templates !== undefined && (!Array.isArray(field.templates) || field.templates.length > 32
        || field.templates.some(template => !record(template) || typeof template.label !== 'string'
          || template.transport !== undefined && typeof template.transport !== 'string'))) throw new Error('INVALID_NODE_PROTOCOL_METADATA')
      if (field.generator !== undefined && (!record(field.generator) || !record(field.generator.map)
        || Object.values(field.generator.map).some(path => typeof path !== 'string' || !safePath(path)))) throw new Error('INVALID_NODE_PROTOCOL_METADATA')
    }
  }
  return source as NodeProtocol[]
}

export function parseNodeConfiguration(json: string): NodeConfiguration {
  if (json.length > 131072) throw new Error('INVALID_NODE_CONFIGURATION')
  const value: unknown = JSON.parse(json)
  if (!record(value) || !['type', 'name', 'host', 'port', 'rate'].every(key => typeof value[key] === 'string')
    || !Number.isInteger(value.server_port) || !record(value.protocol_settings)
    || typeof value.show !== 'boolean' || typeof value.rate_time_enable !== 'boolean'
    || value.enabled !== null && typeof value.enabled !== 'boolean'
    || value.code !== null && typeof value.code !== 'string'
    || value.transfer_enable !== null && (typeof value.transfer_enable !== 'string' || !/^(?:0|[1-9][0-9]{0,18})$/.test(value.transfer_enable) || BigInt(value.transfer_enable) > 9223372036854775807n)
    || !['group_ids', 'route_ids', 'tags', 'rate_time_ranges', 'custom_outbounds', 'custom_routes'].every(key => Array.isArray(value[key]))) throw new Error('INVALID_NODE_CONFIGURATION')
  for (const field of ['parent_id', 'machine_id']) if (value[field] !== null && !validId(value[field])) throw new Error('INVALID_NODE_CONFIGURATION')
  for (const field of ['group_ids', 'route_ids']) if ((value[field] as unknown[]).some(id => !validId(id))) throw new Error('INVALID_NODE_CONFIGURATION')
  if ((value.tags as unknown[]).some(tag => typeof tag !== 'string') || (value.rate_time_ranges as unknown[]).some(range => !record(range)
    || !['start', 'end', 'rate'].every(key => typeof range[key] === 'string'))) throw new Error('INVALID_NODE_CONFIGURATION')
  return value as NodeConfiguration
}

export function newNodeConfiguration(protocol: NodeProtocol, machineId?: string): NodeConfiguration {
  return { type: protocol.type, name: '', host: '', port: '443', server_port: 443, code: null,
    group_ids: [], route_ids: [], parent_id: null, machine_id: machineId || null, show: false, enabled: true,
    rate: '1', rate_time_enable: false, rate_time_ranges: [], tags: [], transfer_enable: null,
    protocol_settings: structuredClone(protocol.defaults), custom_outbounds: [], custom_routes: [], cert_config: null }
}

export function protocolValue(source: Record<string, unknown>, path: string): unknown {
  if (!safePath(path)) return undefined
  return path.split('.').reduce<unknown>((value, key) => record(value) && Object.hasOwn(value, key) ? value[key] : undefined, source)
}
export function withProtocolValue(source: Record<string, unknown>, path: string, value: unknown): Record<string, unknown> {
  if (!safePath(path)) throw new Error('INVALID_NODE_PROTOCOL_PATH')
  const result = structuredClone(source), keys = path.split('.')
  let cursor = result
  keys.forEach((key, index) => {
    if (index === keys.length - 1) cursor[key] = value
    else { if (!record(cursor[key])) cursor[key] = {}; cursor = cursor[key] as Record<string, unknown> }
  })
  return result
}

export function nodeQuotaFromGb(value: string): string | null {
  if (!value.trim()) return null
  if (!/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,9})?$/.test(value)) throw new Error('流量上限须为非负 GB 数值，最多九位小数。')
  const [whole, fraction = ''] = value.split('.')
  const bytes = BigInt(whole) * 1000000000n + BigInt(fraction.padEnd(9, '0'))
  if (bytes > 9223372036854775807n) throw new Error('流量上限超出可保存范围。')
  return bytes.toString()
}
export function nodeQuotaGb(value: string | null): string {
  if (value === null) return ''
  const bytes = BigInt(value), fraction = (bytes % 1000000000n).toString().padStart(9, '0').replace(/0+$/, '')
  return (bytes / 1000000000n).toString() + (fraction ? '.' + fraction : '')
}
