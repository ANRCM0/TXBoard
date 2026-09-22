import { useEffect, useReducer } from 'react'
import { Modal } from './Modal'

export type ConfirmRequest = {
  title: string
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
  action: () => void
}

// Module-level store, mirroring the dialog stack in lib/useDialog: any page can
// ask for confirmation without threading dialog state through props.
let pending: ConfirmRequest | null = null
const listeners = new Set<() => void>()

function publish() {
  listeners.forEach(listener => listener())
}

/** Ask for confirmation before running a destructive or stateful action. */
export function requestConfirm(request: ConfirmRequest) {
  pending = request
  publish()
}

/** Close any open confirmation without running its action. */
export function dismissConfirm() {
  pending = null
  publish()
}

function dismiss() {
  dismissConfirm()
}

function ConfirmDialog({ request, onClose }: { request: ConfirmRequest | null; onClose: () => void }) {
  if (!request) return null
  return <Modal
    open
    title={request.title}
    onClose={onClose}
    footer={<div className="modal-actions">
      <button type="button" className="button" onClick={onClose}>{request.cancelLabel || '取消'}</button>
      <button
        type="button"
        className={'button ' + (request.danger ? 'danger' : 'primary')}
        onClick={() => {
          onClose()
          request.action()
        }}
      >{request.confirmLabel || '确认'}</button>
    </div>}
  >
    <p className="confirm-dialog-message">{request.message || '确认执行该操作？'}</p>
  </Modal>
}

/** Mount once in the admin shell; renders whatever requestConfirm() asked for. */
export function ConfirmDialogHost() {
  const [, notify] = useReducer(count => count + 1, 0)
  useEffect(() => {
    listeners.add(notify)
    return () => { listeners.delete(notify) }
  }, [])

  return <ConfirmDialog request={pending} onClose={dismiss}/>
}