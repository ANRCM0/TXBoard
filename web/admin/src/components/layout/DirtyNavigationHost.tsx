import { useEffect, useSyncExternalStore } from 'react'
import { useBlocker } from 'react-router-dom'
import { dirtyDrafts } from '../../lib/useDirtyDraft'
import { t, useLocale } from '../../lib/i18n'
import { Modal } from '../ui/Modal'
import { dismissConfirm } from '../ui/ConfirmDialog'

export function DirtyNavigationHost() {
  useLocale()
  const dirty = useSyncExternalStore(dirtyDrafts.subscribe, dirtyDrafts.snapshot)
  useEffect(() => {
    if (!dirty) return
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', prevent)
    return () => window.removeEventListener('beforeunload', prevent)
  }, [dirty])
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirtyDrafts.snapshot()
    && (currentLocation.pathname !== nextLocation.pathname || currentLocation.search !== nextLocation.search || currentLocation.hash !== nextLocation.hash))
  useEffect(() => {
    // Revoked permissions and session changes clear drafts without trapping navigation.
    if (!dirty && blocker.state === 'blocked') { dismissConfirm(); blocker.proceed() }
  }, [dirty, blocker])
  if (blocker.state !== 'blocked' || !dirty) return null
  const stay = () => blocker.reset()
  return <Modal open title={t('放弃未保存的修改？')} onClose={stay} footer={<div className="modal-actions">
    <button className="button" onClick={stay}>{t('继续编辑')}</button>
    <button className="button danger" onClick={() => { dismissConfirm(); blocker.proceed() }}>{t('放弃修改')}</button>
  </div>}><p>{t('离开当前页面将丢弃未保存的修改。已经提交的原操作仍可恢复。')}</p></Modal>
}
