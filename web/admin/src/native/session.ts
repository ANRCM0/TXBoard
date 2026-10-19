import { useSyncExternalStore } from 'react'
import { createAdminApi } from './api'
import { queryClient } from './queryClient'
import { dismissConfirm } from '../components/ui/ConfirmDialog'
import { createWorkflowActions } from './workflowActions'
import { createAttachmentTransfer } from '@txboard/shared'
import { CoreAdminApiError } from './api'

let revision = 0
const listeners = new Set<() => void>()
const revokeKeys = new Map<string, string>()
function changed() {
  revision++
  revokeKeys.clear()
  workflowActions.reset()
  attachmentTransfers.reset()
  dismissConfirm()
  queryClient.clear()
  for (const listener of listeners) listener()
}
export const admin = createAdminApi({ onSessionEnded: changed })
export const workflowActions = createWorkflowActions((operation, input, key) => admin.write(operation, input, key))
export const attachmentTransfers = createAttachmentTransfer({
  async authorize(ticketId, signal) { await admin.read('support.admin-tickets.get', { ticket_id: ticketId }, signal) },
  async write(command, key, signal) {
    switch (command.verb) {
      case 'begin': return (await admin.write('support.admin-tickets.attachments.begin', command.input, key, signal)).data
      case 'append': return (await admin.write('support.admin-tickets.attachments.append', command.input, key, signal)).data
      case 'finish': return (await admin.write('support.admin-tickets.attachments.finish', command.input, key, signal)).data
      case 'cancel': return (await admin.write('support.admin-tickets.attachments.cancel', command.input, key, signal)).data
    }
  },
  async get(id, signal) { return (await admin.read('support.admin-tickets.attachments.get', { attachment_id: id }, signal)).data },
  async chunk(id, index, signal) { return (await admin.read('support.admin-tickets.attachments.download', { attachment_id: id, chunk_index: index }, signal)).data },
  classify(error) {
    if (!(error instanceof CoreAdminApiError)) return 'unknown'
    if ([401, 403].includes(error.status)) return error.needsStateConfirmation ? 'denied-unknown' : 'denied'
    return error.needsStateConfirmation ? 'unknown' : 'rejected'
  },
})
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
