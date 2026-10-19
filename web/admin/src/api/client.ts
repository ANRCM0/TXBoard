import axios from 'axios'

/** Dormant original pages must not silently fall back to Core or plugin HTTP routes. */
const blockedAdapter = async (): Promise<never> => { throw new Error('MANAGEMENT_PAGE_NOT_ADAPTED') }
export const nativeApiClient = axios.create({ adapter: blockedAdapter })
export const pluginApiClient = axios.create({ adapter: blockedAdapter })
export function nativeAdminPath(_operation: string): string { throw new Error('MANAGEMENT_PAGE_NOT_ADAPTED') }
export function setAdminSecurePath(_path: string): never { throw new Error('Use the management SDK session') }
export function clearAdminSecurePath(): void {}
export const getResolvedApiPrefixes = () => ({ native: '', plugin: '' })
export type NativeApiEnvelope<T> = {
  data: T; meta?: { page: number; per_page: number; total: number; last_page: number }; request_id: string
}
export async function unwrapNative<T>(promise: Promise<{ data: NativeApiEnvelope<T> }>): Promise<T> {
  return (await promise).data.data
}
