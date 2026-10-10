import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'
import type {
  AgentAbilities, AgentActionItem, AgentInspectionItem, AgentPairing,
  AgentSupportReply, AgentTokenItem, FleetHealth,
} from './agent'

const base = () => nativeAdminPath('agents')

async function allTokenPages(): Promise<AgentTokenItem[]> {
  const tokens: AgentTokenItem[] = []
  for (let page = 1; page <= 100; page++) {
    const { data: response } = await nativeApiClient.get<NativeApiEnvelope<AgentTokenItem[]>>(
      base() + '/tokens', { params: { page, per_page: 100 } },
    )
    if (!response || !response.request_id || !Array.isArray(response.data) ||
      !response.meta || response.meta.page !== page ||
      !Number.isSafeInteger(response.meta.last_page)) {
      throw new Error('Invalid native Agent tokens page')
    }
    tokens.push(...response.data)
    if (page >= response.meta.last_page) return tokens
  }
  throw new Error('Agent token inventory exceeds supported UI pagination bound')
}

export function getAgentAbilities(): Promise<AgentAbilities> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<AgentAbilities>>(
    base() + '/abilities',
  ))
}

export const getAgentTokens = allTokenPages

export function createAgentToken(payload: {
  client_name: string
  abilities: string[]
  expires_in_days: number
  target_mode: 'all' | 'restricted'
  target_node_ids?: number[]
  target_machine_ids?: number[]
}): Promise<AgentTokenItem & { plain_text_token: string; pairing?: AgentPairing | null }> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<
    AgentTokenItem & { plain_text_token: string; pairing?: AgentPairing | null }
  >>(base() + '/tokens', payload))
}

export function revokeAgentToken(id: number) {
  if (!Number.isSafeInteger(id) || id <= 0) throw new Error('Invalid Agent token ID')
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(
    base() + '/tokens/' + id,
  ))
}

export function getAgentActions(status?: string): Promise<AgentActionItem[]> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<AgentActionItem[]>>(
    base() + '/actions', { params: { ...(status ? { status } : {}), limit: 50 } },
  ))
}

export function approveAgentAction(request_id: string): Promise<AgentActionItem> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<AgentActionItem>>(
    base() + '/actions/approve', { request_id },
  ))
}

export function rejectAgentAction(request_id: string, reason?: string): Promise<AgentActionItem> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<AgentActionItem>>(
    base() + '/actions/reject', { request_id, reason },
  ))
}

export function getAgentFleetHealth(): Promise<FleetHealth> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<FleetHealth>>(
    base() + '/fleet/health',
  ))
}

export function getAgentInspections(limit = 10): Promise<AgentInspectionItem[]> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<AgentInspectionItem[]>>(
    base() + '/inspections', { params: { limit } },
  ))
}

export function runAgentInspection(): Promise<AgentInspectionItem> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<AgentInspectionItem>>(
    base() + '/inspections', {},
  ))
}

export function getAgentSupportReplies(): Promise<AgentSupportReply[]> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<AgentSupportReply[]>>(
    base() + '/support/reply-requests', { params: { limit: 50 } },
  ))
}

export function approveAgentSupportReply(request_id: string): Promise<AgentSupportReply> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<AgentSupportReply>>(
    base() + '/support/reply-requests/approve', { request_id },
  ))
}

export function rejectAgentSupportReply(request_id: string): Promise<AgentSupportReply> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<AgentSupportReply>>(
    base() + '/support/reply-requests/reject', { request_id },
  ))
}
