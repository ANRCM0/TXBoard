import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'
import type { PluginItem, PluginConfigField } from './plugin'

const base = () => nativeAdminPath('plugins')
const pluginPath = (code: string) => {
  if (!/^[a-z0-9][a-z0-9_-]{0,79}$/.test(code)) throw new Error('Invalid plugin code')
  return base() + '/' + encodeURIComponent(code)
}

export function getPlugins(params: Record<string, unknown> = {}) {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<PluginItem[]>>(
    base(), { params },
  ))
}

export function getPluginConfig(code: string) {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<Record<string, PluginConfigField>>>(
    pluginPath(code) + '/config',
  ))
}

export function updatePluginConfig(code: string, config: Record<string, unknown>) {
  return unwrapNative(nativeApiClient.put<NativeApiEnvelope<{ ok: boolean }>>(
    pluginPath(code) + '/config', { config },
  ))
}

function lifecycle(code: string, action: 'install' | 'uninstall' | 'enable' | 'disable' | 'upgrade') {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    pluginPath(code) + '/actions/' + action, {},
  ))
}

export const installPlugin = (code: string) => lifecycle(code, 'install')
export const uninstallPlugin = (code: string) => lifecycle(code, 'uninstall')
export const enablePlugin = (code: string) => lifecycle(code, 'enable')
export const disablePlugin = (code: string) => lifecycle(code, 'disable')
export const upgradePlugin = (code: string) => lifecycle(code, 'upgrade')

export function deletePlugin(code: string) {
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(
    pluginPath(code),
  ))
}

export function uploadPlugin(file: File) {
  const form = new FormData()
  form.append('file', file)
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    base() + '/upload', form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  ))
}
