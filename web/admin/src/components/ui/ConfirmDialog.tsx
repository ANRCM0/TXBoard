import { t, useLocale } from '../../lib/i18n'
import { useEffect, useReducer } from 'react'
import { Modal } from './Modal'

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

// Module-level store, mirroring the dialog stack in lib/useDialog: any page can
// ask for confirmation without threading dialog state through props.
let pending: ConfirmRequest | null = null
let releaseAbort: (() => void) | undefined
const listeners = new Set<() => void>()

function publish() {
  listeners.forEach(listener => listener())
}

/** Ask for confirmation before running a destructive or stateful action. */
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

/** Close any open confirmation without running its action. */
export function dismissConfirm() {
  takePending()?.cancelAction?.()
}

function ConfirmDialog({ request }: { request: ConfirmRequest | null }) {
  useLocale()
  if (!request) return null

  const cancel = () => { if (pending === request) dismissConfirm() }

  return <Modal
    open
    size="compact"
    className="admin-confirm-dialog"
    title={request.title}
    onClose={cancel}
    footer={<div className="modal-actions">
      <button type="button" className="button" onClick={cancel}>{request.cancelLabel || t("取消")}</button>
      <button
        type="button"
        className={'button ' + (request.danger ? 'danger' : 'primary')}
        onClick={() => {
          if (pending === request) takePending()?.action()
        }}
      >{request.confirmLabel || t("确认")}</button>
    </div>}
  >
    <p className="confirm-dialog-message">{request.message || t("确认执行该操作？")}</p>
  </Modal>
}

/** Mount once in the admin shell; renders whatever requestConfirm() asked for. */
export function ConfirmDialogHost() {
  useLocale()
  const [, notify] = useReducer(count => count + 1, 0)
  useEffect(() => {
    listeners.add(notify)
    return () => { listeners.delete(notify) }
  }, [])

  return <ConfirmDialog request={pending}/>
}
