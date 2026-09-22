import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type AgentTargetScope = {
  mode: 'all' | 'restricted'
  node_ids: number[]
  machine_ids: number[]
}

export type AgentTokenItem = {
  id: number
  client_name: string
  abilities: string[]
  target_scope?: AgentTargetScope
  last_used_at?: string | null
  expires_at?: string | null
  created_at?: string | null
}

export type AgentActionItem = {
  request_id: string
  node_id: number
  action: string
  risk_level: string
  status: string
  input?: Record<string, unknown> | null
  result?: Record<string, unknown> | null
  error_code?: string | null
  approved_by?: number | null
  approved_at?: number | null
  started_at?: number | null
  finished_at?: number | null
  created_at?: number | null
}

export type AgentAbilities = {
  default_read: string[]
  all: string[]
}

export type FleetFinding = {
  node_id: number
  name: string
  status: 'healthy' | 'degraded' | 'critical'
  online: boolean
  websocket: boolean
  kernel_running?: boolean | null
  warnings: Array<{ code: string; severity: string; [key: string]: unknown }>
}

export type FleetHealth = {
  status: 'healthy' | 'degraded' | 'critical'
  summary: {
    status: 'healthy' | 'degraded' | 'critical'
    total_nodes: number
    healthy_nodes: number
    degraded_nodes: number
    critical_nodes: number
    warning_count: number
  }
  nodes: FleetFinding[]
  generated_at: number
}

export type AgentInspectionItem = {
  inspection_id: string
  source: string
  status: 'healthy' | 'degraded' | 'critical'
  summary: FleetHealth['summary']
  findings: FleetFinding[]
  started_at: number
  finished_at: number
  created_at: number
}

export async function getAgentAbilities() {
  const { data } = await apiClient.get('/agent/abilities')
  return unwrap<AgentAbilities>(data) || { default_read: [], all: [] }
}

export async function getAgentTokens() {
  const { data } = await apiClient.get('/agent/tokens')
  return unwrap<AgentTokenItem[]>(data) || []
}

export async function createAgentToken(payload: {
  client_name: string
  abilities: string[]
  expires_in_days: number
  target_mode: 'all' | 'restricted'
  target_node_ids?: number[]
  target_machine_ids?: number[]
}) {
  const { data } = await apiClient.post('/agent/tokens/create', payload)
  return unwrap<AgentTokenItem & { plain_text_token: string }>(data)
}

export async function revokeAgentToken(id: number) {
  const { data } = await apiClient.post('/agent/tokens/revoke', { id })
  return unwrap(data)
}

export async function getAgentActions(status?: string) {
  const { data } = await apiClient.get('/agent/actions', {
    params: { ...(status ? { status } : {}), limit: 50 },
  })
  return unwrap<AgentActionItem[]>(data) || []
}

export async function approveAgentAction(request_id: string) {
  const { data } = await apiClient.post('/agent/actions/approve', { request_id })
  return unwrap<AgentActionItem>(data)
}

export async function rejectAgentAction(request_id: string, reason?: string) {
  const { data } = await apiClient.post('/agent/actions/reject', { request_id, reason })
  return unwrap<AgentActionItem>(data)
}


export async function getAgentFleetHealth() {
  const { data } = await apiClient.get('/agent/fleet/health')
  return unwrap<FleetHealth>(data)
}

export async function getAgentInspections(limit = 10) {
  const { data } = await apiClient.get('/agent/inspections', { params: { limit } })
  return unwrap<AgentInspectionItem[]>(data) || []
}

export async function runAgentInspection() {
  const { data } = await apiClient.post('/agent/inspections/run')
  return unwrap<AgentInspectionItem>(data)
}
