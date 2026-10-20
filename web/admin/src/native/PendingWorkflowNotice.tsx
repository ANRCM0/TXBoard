import { useSyncExternalStore } from 'react'
import { Link } from 'react-router-dom'
import { attachmentTransfers, workflowActions } from './session'
import { workflowDestination } from './routes'

export function PendingWorkflowNotice() {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const attachments = useSyncExternalStore(attachmentTransfers.subscribe, attachmentTransfers.snapshot)
  const destination = state.pending && workflowDestination(state.pending.operation, state.pending.input)
  return <>
    {state.pending?.unknown && destination ? <section className="native-pending-notice" role="status" aria-label={t('管理操作待确认')}>
      <strong>{t('{page}有一项操作结果待确认', { page: t(destination.label) })}</strong>
      <p>{t('请回到原操作页面确认结果，确认前暂不能发起其他管理操作。')}</p>
      <Link to={destination.path}>{t('前往{page}确认原操作', { page: t(destination.label) })}</Link>
    </section> : null}
    {attachments.pending ? <section className="native-pending-notice" role="status" aria-label={t('附件操作待确认')}>
      <p>{t('附件操作结果待确认，请返回工单管理继续处理。')}</p>
      <Link to="/user/ticket">{t('前往工单管理确认附件')}</Link>
    </section> : null}
  </>
}
import { t, useLocale } from '../lib/i18n'
