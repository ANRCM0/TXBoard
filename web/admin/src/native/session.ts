import { useSyncExternalStore } from 'react'
import { createAdminApi } from './api'
import { queryClient } from './queryClient'
import { dismissConfirm } from '../components/ui/ConfirmDialog'

let revision = 0
const listeners = new Set<() => void>()
const revokeKeys = new Map<string, string>()
function changed() {
  revision++
  revokeKeys.clear()
  dismissConfirm()
  queryClient.clear()
  for (const listener of listeners) listener()
}
export const admin = createAdminApi({ onSessionEnded: changed })
export function useAdminSession() {
  useSyncExternalStore(listener => { listeners.add(listener); return () => { listeners.delete(listener) } },
    () => revision)
  return admin.hasSession()
}
export async function loginAdmin(input: Parameters<typeof admin.login>[0]) {
  // Clear cached data before admitting a different identity.
  changed()
  await admin.login(input, crypto.randomUUID())
  changed()
}
export const logoutAdmin = () => admin.logout(crypto.randomUUID())
export async function revokeAdminSession(id: string) {
  const key = revokeKeys.get(id) ?? crypto.randomUUID()
  revokeKeys.set(id, key)
  const result = await admin.revokeSession(id, key)
  revokeKeys.delete(id)
  return result
}
