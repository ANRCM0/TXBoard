import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'
import type { RouteItem, RouteSimulationResult } from './server'

export async function getRoutes() {
  return (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<RouteItem[]>>(
    nativeAdminPath('network-routes'),
  ))) || []
}

export async function saveRoute(payload: Partial<RouteItem>) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean; id: number }>>(
    nativeAdminPath('network-routes'), payload,
  ))
}

export async function deleteRoute(id: number) {
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('network-routes') + '/' + id,
  ))
}

export async function sortRoutes(items: Array<{ id: number; sort: number }>) {
  return unwrapNative(nativeApiClient.put<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('network-routes') + '/sort', items,
  ))
}

export async function simulateRoute(nodeId: number, target: string) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<RouteSimulationResult>>(
    nativeAdminPath('network-routes') + '/simulate', { node_id: nodeId, target },
  ))
}
