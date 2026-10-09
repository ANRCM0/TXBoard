import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'
import type { NodeItem, ProtocolDefinitionMeta, ProtocolGeneratorKind } from './server'

const base = () => nativeAdminPath('network-nodes')
const path = (id: number) => {
  if (!Number.isSafeInteger(id) || id <= 0) throw new Error('Invalid node ID')
  return base() + '/' + id
}

export async function getProtocolDefinitions() {
  return (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<ProtocolDefinitionMeta[]>>(
    base() + '/protocols',
  ))) || []
}

export async function generateSecret(kind: ProtocolGeneratorKind, params: Record<string, unknown> = {}) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<Record<string, string>>>(
    base() + '/secrets', { kind, ...params },
  ))
}

export async function getNodes(): Promise<NodeItem[]> {
  const nodes: NodeItem[] = []
  // Keep the existing UI array contract while the native server pages results.
  // Fail explicitly instead of silently returning a truncated node inventory.
  for (let page = 1; page <= 100; page++) {
    const { data: response } = await nativeApiClient.get<NativeApiEnvelope<NodeItem[]>>(
      base(), { params: { page, per_page: 100 } },
    )
    if (!response || !response.request_id || !Array.isArray(response.data) ||
      !response.meta || response.meta.page !== page ||
      !Number.isSafeInteger(response.meta.last_page)) {
      throw new Error('Invalid native nodes page')
    }
    nodes.push(...response.data)
    if (page >= response.meta.last_page) return nodes
  }
  throw new Error('Node inventory exceeds supported UI pagination bound')
}

export async function saveNode(payload: Partial<NodeItem>) {
  const { id, ...data } = payload
  if (id && id > 0) {
    return unwrapNative(nativeApiClient.put<NativeApiEnvelope<{ ok: boolean; id: number }>>(
      path(id), data,
    ))
  }
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean; id: number }>>(
    base(), data,
  ))
}

export async function updateNode(id: number, payload: Partial<NodeItem>) {
  const { id: _ignored, ...data } = payload
  return unwrapNative(nativeApiClient.patch<NativeApiEnvelope<{ ok: boolean }>>(
    path(id), data,
  ))
}

export async function batchUpdateNodes(
  ids: number[], payload: { show?: 0 | 1; enabled?: boolean; machine_id?: number | null },
) {
  return unwrapNative(nativeApiClient.patch<NativeApiEnvelope<{ ok: boolean }>>(
    base() + '/batch', { ids, ...payload },
  ))
}

export async function copyNode(id: number) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<number>>(
    path(id) + '/copy', {},
  ))
}

export async function deleteNode(id: number) {
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(path(id)))
}

export async function saveNodeOrder(items: Array<{ id: number; order: number }>) {
  return unwrapNative(nativeApiClient.put<NativeApiEnvelope<{ ok: boolean }>>(
    base() + '/sort', items,
  ))
}

export async function batchDeleteNodes(ids: number[]) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    base() + '/batch-delete', { ids },
  ))
}

export async function resetNodeTraffic(id: number) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    path(id) + '/traffic-reset', {},
  ))
}

export async function batchResetNodeTraffic(ids: number[]) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    base() + '/batch-traffic-reset', { ids },
  ))
}
