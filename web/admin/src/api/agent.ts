import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type AgentTargetScope = {
  mode: 'all' | 'restricted'
  node_ids: number[]
  machine_ids: number[]
}

export type AgentPairing = {
  code: string
  expires_at: string
  expires_in_seconds: number
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

export type AgentSupportReply = {
  request_id: string
  ticket_id: number
  status: string
  message: string
  created_at: number
  approved_at?: number | null
}

export {
  getAgentAbilities, getAgentTokens, createAgentToken, revokeAgentToken,
  getAgentActions, approveAgentAction, rejectAgentAction,
  getAgentFleetHealth, getAgentInspections, runAgentInspection,
  getAgentSupportReplies, approveAgentSupportReply, rejectAgentSupportReply,
} from './agent-admin'
