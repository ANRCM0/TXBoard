import { t, useLocale } from '../../lib/i18n'
import { useSyncExternalStore } from 'react'
import { Modal } from './Modal'
import { acceptConfirmation, cancelConfirmation, confirmationSnapshot, subscribeConfirmation, type ConfirmRequest } from '../../lib/confirmationStore'
export { requestConfirm, dismissConfirm, type ConfirmRequest } from '../../lib/confirmationStore'

function ConfirmDialog({ request }: { request: ConfirmRequest | null }) {
  useLocale()
  if (!request) return null

  const cancel = () => cancelConfirmation(request)

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
        onClick={() => acceptConfirmation(request)}
      >{request.confirmLabel || t("确认")}</button>
    </div>}
  >
    <p className="confirm-dialog-message">{request.message || t("确认执行该操作？")}</p>
  </Modal>
}

/** Mount once in the admin shell; renders whatever requestConfirm() asked for. */
export function ConfirmDialogHost() {
  useLocale()
  const pending = useSyncExternalStore(subscribeConfirmation, confirmationSnapshot)
  return <ConfirmDialog request={pending}/>
}
