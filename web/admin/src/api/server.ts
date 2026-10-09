import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type NodeProtocolType = string

export type NodeRateTimeRange = {
  start: string
  end: string
  rate: number
}

export type ProtocolFormCondition = {
  field: string
  equals: unknown
}

export type ProtocolFormOption = {
  value: string | number | boolean
  label: string
}

export type ProtocolGeneratorKind = 'x25519' | 'hex' | 'ech'

/**
 * Declarative key generation for a protocol field. The editor calls the
 * generator endpoint once and writes every response key into its mapped
 * protocol_settings path, so admins never run `xray x25519` by hand.
 */
export type ProtocolFieldGenerator = {
  kind: ProtocolGeneratorKind
  label?: string
  params?: Record<string, string | number>
  /** response key -> protocol_settings path */
  map: Record<string, string>
}

export type ProtocolFormField = {
  key: string
  label: string
  type: 'text' | 'number' | 'select' | 'checkbox' | 'textarea' | 'json' | 'json-array' | 'string-list'
  placeholder?: string
  full?: boolean
  min?: number
  max?: number
  step?: number | string
  separator?: 'comma' | 'newline'
  options?: ProtocolFormOption[]
  visible_when?: ProtocolFormCondition | ProtocolFormCondition[]
  generator?: ProtocolFieldGenerator
}

export type ProtocolDefinitionMeta = {
  type: NodeProtocolType
  label: string
  schema_version: number
  defaults: Record<string, unknown>
  form_schema: ProtocolFormField[]
}

export type NodeItem = {
  id: number
  name?: string
  type?: NodeProtocolType | string
  host?: string
  port?: number
  server_port?: number
  group_id?: number
  group_ids?: number[]
  route_ids?: number[]
  tags?: string[]
  excludes?: unknown[]
  ips?: unknown[]
  parent_id?: number | null
  machine_id?: number | null
  show?: number | boolean
  enabled?: boolean
  online?: number | boolean
  rate?: number
  rate_time_enable?: boolean
  rate_time_ranges?: NodeRateTimeRange[]
  protocol_settings?: Record<string, unknown>
  transfer_enable?: number
  custom_outbounds?: unknown[]
  custom_routes?: unknown[]
  cert_config?: Record<string, unknown> | unknown[]
  code?: string | null
  spectific_key?: string | null
  [key: string]: unknown
}

export type MachineRuntimeUpdateStatus = {
  request_id: string
  target: 'latest'
  status: 'accepted' | 'running' | 'succeeded' | 'failed' | 'rolled_back'
  updated_at: number
  message?: string
}

export type MachineRuntimeStatus = {
  version?: string
  build_time?: string
  deployment?: 'docker' | 'unknown'
  updater_available?: boolean
  update?: MachineRuntimeUpdateStatus
}

export type MachineLoadStatus = {
  cpu?: number
  mem?: { total?: number; used?: number }
  swap?: { total?: number; used?: number }
  disk?: { total?: number; used?: number }
  net?: { in_speed?: number; out_speed?: number }
  runtime?: MachineRuntimeStatus
  updated_at?: number
}

export type MachineItem = {
  id: number
  name?: string
  notes?: string | null
  is_active?: boolean
  last_seen_at?: number | string | null
  load_status?: MachineLoadStatus | null
  servers_count?: number
  created_at?: number | string
  updated_at?: number | string
  [key: string]: unknown
}

export type GroupItem = {
  id: number
  name?: string
  users_count?: number
  plans_count?: number
  server_count?: number
  created_at?: number | string
  updated_at?: number | string
  [key: string]: unknown
}

export type RouteItem = {
  id: number
  remarks?: string
  match?: string[]
  action?: 'block' | 'direct' | 'dns' | 'proxy' | string
  action_value?: string | null
  enabled?: boolean
  sort?: number | null
  server_count?: number
  created_at?: number | string
  updated_at?: number | string
  [key: string]: unknown
}

export type RouteSimulationResult = {
  node: { id: number; name?: string }
  target: string
  authoritative: boolean
  warning?: string | null
  match?: {
    layer: 'built_in' | 'panel'
    id?: number
    remarks: string
    action: string
    action_value?: string | null
    pattern?: string | null
  } | null
  evaluated_routes: Array<{
    id: number
    remarks?: string
    matched: boolean
    matched_pattern?: string | null
    action: string
    action_value?: string | null
  }>
  unresolved_patterns: string[]
}

export {
  generateSecret, getProtocolDefinitions, getNodes, saveNode, updateNode,
  batchUpdateNodes, copyNode, deleteNode, saveNodeOrder,
  batchDeleteNodes, resetNodeTraffic, batchResetNodeTraffic,
} from './server-nodes'

export async function getMachines() {
  const { data } = await apiClient.get('/server/machine/fetch')
  return unwrap<MachineItem[]>(data) || []
}
export async function saveMachine(payload: Partial<MachineItem>) {
  const { data } = await apiClient.post('/server/machine/save', payload)
  return unwrap(data)
}
export async function getMachineToken(id: number) {
  const { data } = await apiClient.get('/server/machine/getToken', { params: { id } })
  return unwrap<{ token?: string }>(data)?.token || ''
}
export async function getInstallCommand(id: number) {
  const { data } = await apiClient.get('/server/machine/installCommand', { params: { id } })
  return unwrap<{ command?: string }>(data)?.command || ''
}
export async function resetMachineToken(id: number) {
  const { data } = await apiClient.post('/server/machine/resetToken', { id })
  return unwrap(data)
}

export type MachineRuntimeUpdateRequestResult = {
  machine_id: number
  request_id: string
  target: 'latest'
  status: 'accepted'
}

export async function updateMachineRuntime(id: number) {
  const { data } = await apiClient.post('/server/machine/runtime/update', {
    machine_id: id,
    target: 'latest',
  })
  return unwrap<MachineRuntimeUpdateRequestResult>(data)
}
export async function deleteMachine(id: number) {
  const { data } = await apiClient.post('/server/machine/drop', { id })
  return unwrap(data)
}
export async function getMachineHistory(machineId: number, limit = 240, rangeHours = 24) {
  const { data } = await apiClient.get('/server/machine/history', {
    params: { machine_id: machineId, limit, range_hours: rangeHours },
  })
  return unwrap(data)
}
export async function getMachineNodes(machineId: number) {
  const { data } = await apiClient.get('/server/machine/nodes', { params: { machine_id: machineId } })
  return unwrap<NodeItem[]>(data) || []
}

export { getGroups, saveGroup, deleteGroup } from './server-groups'
export { getRoutes, saveRoute, deleteRoute, sortRoutes, simulateRoute } from './server-routes'
