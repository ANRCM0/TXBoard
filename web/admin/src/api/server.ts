import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type NodeProtocolType =
  | 'hysteria'
  | 'vless'
  | 'trojan'
  | 'vmess'
  | 'tuic'
  | 'shadowsocks'
  | 'anytls'
  | 'socks'
  | 'naive'
  | 'http'
  | 'mieru'

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

export type MachineLoadStatus = {
  cpu?: number
  mem?: { total?: number; used?: number }
  swap?: { total?: number; used?: number }
  disk?: { total?: number; used?: number }
  net?: { in_speed?: number; out_speed?: number }
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

export type GroupItem = { id: number; name?: string; [key: string]: unknown }
export type RouteItem = { id: number; remarks?: string; match?: string[]; action?: string; action_value?: string; [key: string]: unknown }

export async function getProtocolDefinitions() {
  const { data } = await apiClient.get('/server/manage/protocols')
  return unwrap<ProtocolDefinitionMeta[]>(data) || []
}

export async function getNodes() {
  const { data } = await apiClient.get('/server/manage/getNodes')
  return unwrap<NodeItem[]>(data) || []
}
export async function saveNode(payload: Partial<NodeItem>) {
  const { data } = await apiClient.post('/server/manage/save', payload)
  return unwrap(data)
}
export async function updateNode(id: number, payload: Partial<NodeItem>) {
  const { data } = await apiClient.post('/server/manage/update', { id, ...payload })
  return unwrap(data)
}
export async function batchUpdateNodes(
  ids: number[],
  payload: { show?: 0 | 1; enabled?: boolean; machine_id?: number | null },
) {
  const { data } = await apiClient.post('/server/manage/batchUpdate', { ids, ...payload })
  return unwrap(data)
}
export async function deleteNode(id: number) {
  const { data } = await apiClient.post('/server/manage/drop', { id })
  return unwrap(data)
}
export async function saveNodeOrder(items: Array<{ id: number; order: number }>) {
  const { data } = await apiClient.post('/server/manage/sort', items)
  return unwrap(data)
}

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

export async function getGroups() {
  const { data } = await apiClient.get('/server/group/fetch')
  return unwrap<GroupItem[]>(data) || []
}
export async function saveGroup(payload: Partial<GroupItem>) {
  const { data } = await apiClient.post('/server/group/save', payload)
  return unwrap(data)
}
export async function deleteGroup(id: number) {
  const { data } = await apiClient.post('/server/group/drop', { id })
  return unwrap(data)
}

export async function getRoutes() {
  const { data } = await apiClient.get('/server/route/fetch')
  return unwrap<RouteItem[]>(data) || []
}
export async function saveRoute(payload: Partial<RouteItem>) {
  const { data } = await apiClient.post('/server/route/save', payload)
  return unwrap(data)
}
export async function deleteRoute(id: number) {
  const { data } = await apiClient.post('/server/route/drop', { id })
  return unwrap(data)
}
