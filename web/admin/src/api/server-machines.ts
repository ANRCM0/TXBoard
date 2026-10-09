import { nativeAdminPath, nativeApiClient, unwrapNative, type NativeApiEnvelope } from './client'
import type { MachineItem, MachineRuntimeUpdateRequestResult, NodeItem } from './server'

const base = () => nativeAdminPath('network-machines')
const path = (id: number) => {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid machine ID')
  return base() + '/' + id
}
export type MachineCredentials = { token: string; install_command: string }

async function readPages<T>(endpoint: string): Promise<T[]> {
  const records: T[] = []
  for (let page = 1; page <= 100; page++) {
    const { data: response } = await nativeApiClient.get<NativeApiEnvelope<T[]>>(
      endpoint, { params: { page, per_page: 100 } },
    )
    if (!response || !response.request_id || !Array.isArray(response.data) ||
      !response.meta || response.meta.page !== page ||
      !Number.isSafeInteger(response.meta.last_page)) {
      throw new Error('Invalid native machines page')
    }
    records.push(...response.data)
    if (page >= response.meta.last_page) return records
  }
  throw new Error('Machine inventory exceeds supported UI pagination bound')
}

export function getMachines(): Promise<MachineItem[]> {
  return readPages<MachineItem>(base())
}

export async function saveMachine(payload: Partial<MachineItem>) {
  const { id, ...input } = payload
  if (id) {
    return unwrapNative(nativeApiClient.put<NativeApiEnvelope<{ ok: boolean }>>(
      path(id), input,
    ))
  }
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<MachineCredentials & { id: number }>>(
    base(), input,
  ))
}

export function getMachineCredentials(id: number): Promise<MachineCredentials> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<MachineCredentials>>(
    path(id) + '/credentials', {},
  ))
}

export function resetMachineToken(id: number): Promise<MachineCredentials> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<MachineCredentials>>(
    path(id) + '/token/rotate', {},
  ))
}

export function updateMachineRuntime(id: number): Promise<MachineRuntimeUpdateRequestResult> {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<MachineRuntimeUpdateRequestResult>>(
    path(id) + '/runtime/update', { target: 'latest' },
  ))
}

export function deleteMachine(id: number) {
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(path(id)))
}

export function getMachineNodes(id: number): Promise<NodeItem[]> {
  return readPages<NodeItem>(path(id) + '/nodes')
}

export function getMachineHistory(machineId: number, limit = 240, rangeHours = 24) {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<Array<Record<string, number>>>>(
    path(machineId) + '/history', { params: { limit, range_hours: rangeHours } },
  ))
}
