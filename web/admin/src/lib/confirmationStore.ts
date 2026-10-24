export type ConfirmRequest = {
  title: string
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
  action: () => void
  cancelAction?: () => void
  signal?: AbortSignal
}

// Session cleanup and editor ownership share one store without loading dialog UI.
let pending: ConfirmRequest | null = null
let releaseAbort: (() => void) | undefined
const listeners = new Set<() => void>()
function publish() { listeners.forEach(listener => listener()) }

export const confirmationSnapshot = () => pending
export function subscribeConfirmation(listener: () => void) {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

export function requestConfirm(request: ConfirmRequest) {
  dismissConfirm()
  if (request.signal?.aborted) { request.cancelAction?.(); return }
  pending = request
  const abort = () => { if (pending === request) dismissConfirm() }
  request.signal?.addEventListener('abort', abort, { once: true })
  releaseAbort = () => request.signal?.removeEventListener('abort', abort)
  publish()
}

function takePending() {
  const request = pending
  pending = null
  releaseAbort?.(); releaseAbort = undefined
  publish()
  return request
}

export function dismissConfirm() { takePending()?.cancelAction?.() }
export function cancelConfirmation(request: ConfirmRequest) {
  if (pending === request) dismissConfirm()
}
export function acceptConfirmation(request: ConfirmRequest) {
  if (pending === request) takePending()?.action()
}
