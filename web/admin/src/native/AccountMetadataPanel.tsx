import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useEffect, useRef, useState, useSyncExternalStore, type FormEvent } from 'react'
import type { CoreOutput } from '@txboard/core-sdk'
import { Link } from 'react-router-dom'
import { DetailSection } from '../components/ui/DetailSection'
import { Modal } from '../components/ui/Modal'
import { Field, Textarea } from '../components/ui/Field'
import { ReadFields } from './ReadList'
import { unixDate } from './readPresentation'
import { managementError } from './Feedback'
import { workflowActions } from './session'

export function AccountMetadataPanel({ user, locked, onChanged }: { user: CoreOutput<'identity.accounts.get'>; locked: boolean; onChanged: () => void }) {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [editing, setEditing] = useState(false), [remarks, setRemarks] = useState(user.remarks ?? ''), [error, setError] = useState('')
  const generation = workflowActions.generation(), mounted = useRef(true)
  const draftScope = useRef('')
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { setEditing(false); setRemarks(user.remarks ?? ''); setError('') }, [user.id, user.metadata_revision, user.remarks, generation])
  const dirty = editing && remarks !== (user.remarks ?? '')
  useDirtyDraft(dirty && !locked && !state.pending && !!user.metadata_revision && !!user.identity_editable
    && draftScope.current === user.id + ':' + user.metadata_revision + ':' + generation)
  useEffect(() => {
    if (!dirty) return
    const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', guard); return () => window.removeEventListener('beforeunload', guard)
  }, [dirty])
  function close() { if (!state.busy && (!dirty || window.confirm(t("关闭会丢弃尚未提交的备注，确认关闭？")))) setEditing(false) }
  async function save(event: FormEvent) {
    event.preventDefault(); if (locked || state.busy || state.pending || !user.metadata_revision || !user.identity_editable) return
    setError('')
    try {
      await workflowActions.run('identity.accounts.metadata.update', { user_id: user.id, expected_revision: user.metadata_revision, remarks: remarks.trim() || null })
      if (!mounted.current || generation !== workflowActions.generation()) return
      setEditing(false); onChanged()
    } catch (failure) {
      if (!mounted.current || generation !== workflowActions.generation()) return
      setError(managementError(failure, false)); if (workflowActions.snapshot().pending) setEditing(false)
    }
  }
  return <DetailSection label={t("管理备注与邀请关系")} title={t("管理备注与邀请关系")} description={t("备注独立记录修订；原生邀请关系绑定后不可替换")} className="reference-detail-card"
    actions={user.metadata_revision && user.identity_editable ? <button className="button" disabled={locked || !!state.pending} onClick={() => { draftScope.current = user.id + ':' + user.metadata_revision + ':' + generation; setError(''); setRemarks(user.remarks ?? ''); setEditing(true) }}>{t("编辑备注")}</button> : undefined}>
    <ReadFields fields={[
      [t("管理备注"), <span style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{user.remarks || t("暂无备注")}</span>],
      [t("邀请人"), user.referral ? <Link to={'/user/' + user.referral.sponsor_id}>{user.referral.sponsor_email} · #{user.referral.sponsor_id}</Link> : user.referral === null ? t("未绑定原生邀请人") : t("当前服务未提供邀请关系")],
      ...(user.referral ? [[t("绑定时间"), unixDate(user.referral.bound_at)] as const] : []),
    ]} />
    <Modal open={editing && !state.pending} title={t("编辑管理备注")} subtitle={t("仅修改管理备注，不改变邀请绑定、订阅或资金")} onClose={close}>
      <form className="native-content-form" onSubmit={save}><Field label={t("管理备注")} hint={t("最多 2000 字；留空清除当前备注，保留修订记录")}><Textarea value={remarks} maxLength={2000} rows={6} disabled={locked || state.busy} onChange={event => setRemarks(event.target.value)} /></Field>
        {error ? <><p role="alert">{t(error)}</p><button type="button" className="button" disabled={state.busy} onClick={() => { if (!dirty || window.confirm(t("重新读取会丢弃当前备注草稿，确认继续？"))) onChanged() }}>{t("重新读取备注")}</button></> : null}<div className="network-form-actions"><button className="button primary" disabled={locked || state.busy || !!state.pending}>{t("保存备注")}</button><button type="button" className="button" disabled={state.busy} onClick={close}>{t("取消")}</button></div>
      </form>
    </Modal>
  </DetailSection>
}
