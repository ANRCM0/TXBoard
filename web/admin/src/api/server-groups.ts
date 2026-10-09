import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'
import type { GroupItem } from './server'

export async function getGroups() {
  return (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<GroupItem[]>>(
    nativeAdminPath('network-groups'),
  ))) || []
}

export async function saveGroup(payload: Partial<GroupItem>) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean; id: number }>>(
    nativeAdminPath('network-groups'), payload,
  ))
}

export async function deleteGroup(id: number) {
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('network-groups') + '/' + id,
  ))
}
