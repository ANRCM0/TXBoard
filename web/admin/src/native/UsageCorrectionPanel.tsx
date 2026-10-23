import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { CoreInput, CoreOutput } from '@txboard/core-sdk'
import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { DetailSection } from '../components/ui/DetailSection'
import { Field, Input } from '../components/ui/Field'
import { Modal } from '../components/ui/Modal'
import { ManagementFeedback, managementError } from './Feedback'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import { admin, workflowActions } from './session'
import { unixDate } from './readPresentation'
import { useAdminConfirmation } from './useAdminConfirmation'

type Entry = CoreOutput<'usage.admin-settled-entries.list'>['items'][number]
type Correction = CoreInput<'usage.admin-corrections.create'>
type Draft = { up: string; down: string; reason: string }
const draftFor = (entry: Entry): Draft => ({ up: entry.billed_up_bytes, down: entry.billed_down_bytes, reason: '' })
export function usageCorrectionInput(userId: string, entry: Entry, draft: Draft): Correction {
  for (const value of [draft.up, draft.down]) if (!/^(?:0|[1-9][0-9]{0,18})$/.test(value) || BigInt(value) > 9223372036854775807n) throw new Error('请输入非负的精确字节整数。')
  if (BigInt(draft.up) + BigInt(draft.down) > 9223372036854775807n) throw new Error('修正后的合计字节数超出上限。')
  if (draft.up === entry.billed_up_bytes && draft.down === entry.billed_down_bytes) throw new Error('请修改这条已结算记录的计费上行或下行字节数。')
  if (!/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/.test(draft.reason)) throw new Error('请填写原因编号：字母、数字及 _ . : -，最多 64 字符。')
  return { user_id: userId, entry_id: entry.entry_id, expected_entry_revision: entry.entry_revision, expected_period_version: entry.period_version,
    billed_up_bytes: draft.up, billed_down_bytes: draft.down, reason: draft.reason }
}

export function UsageCorrectionPanel({ userId, locked, onChanged }: { userId: string; locked: boolean; onChanged: () => void }) {
  const confirm = useAdminConfirmation(() => !locked && !management.denied, userId)
  useLocale()
  const [cursor, setCursor] = useState<string>(), [showHistory, setShowHistory] = useState(false), [historyCursor, setHistoryCursor] = useState<string>()
  const entries = useQuery({ queryKey: ['native-admin', 'settled-usage-entries', userId, cursor],
    queryFn: ({ signal }) => admin.read('usage.admin-settled-entries.list', { user_id: userId, limit: 25, ...(cursor ? { before: cursor } : {}) }, signal) })
  const history = useQuery({ queryKey: ['native-admin', 'usage-correction-history', userId, historyCursor], enabled: showHistory && !entries.isError,
    queryFn: ({ signal }) => admin.read('usage.admin-corrections.list', { user_id: userId, limit: 25, ...(historyCursor ? { before: historyCursor } : {}) }, signal) })
  const management = useNetworkManagement('usage.admin-', [entries.error, history.error], async () => {
    await entries.refetch({ throwOnError: true }); if (showHistory || history.isError) await history.refetch({ throwOnError: true }); onChanged()
  }, userId)
  const [editing, setEditing] = useState<{ entry: Entry; draft: Draft }>(), [error, setError] = useState('')
  const generation = workflowActions.generation(), mounted = useRef(true), draftGeneration = useRef(generation)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { setEditing(undefined); setError(''); setCursor(undefined); setShowHistory(false); setHistoryCursor(undefined) }, [userId, generation, management.denied])
  const current = entries.isError || management.denied ? undefined : entries.data?.data, blocked = locked || management.state.busy || !!management.state.pending || management.denied
  const dirty = !!editing && JSON.stringify(editing.draft) !== JSON.stringify(draftFor(editing.entry))
  useDirtyDraft(dirty && draftGeneration.current === generation && !management.denied && !management.state.pending)
  useEffect(() => { if (!dirty) return; const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }; window.addEventListener('beforeunload', guard); return () => window.removeEventListener('beforeunload', guard) }, [dirty])
  async function close() { if (!management.state.busy && (!dirty || (await confirm(t('关闭会丢弃尚未提交的流量修正，确认关闭？'))))) setEditing(undefined) }
  function update(field: keyof Draft, value: string) { setEditing(old => old ? { ...old, draft: { ...old.draft, [field]: value } } : old) }
  async function submit(event: FormEvent) {
    event.preventDefault(); if (!editing || blocked) return; setError('')
    try {
      const input = usageCorrectionInput(userId, editing.entry, editing.draft)
      if (!(await confirm(t('确认修正这条已结算记录的计费用量？原始节点观测和签名回执将保留，修正会单独留证。')))) return
      await workflowActions.run('usage.admin-corrections.create', input)
      if (!mounted.current || generation !== workflowActions.generation()) return
      setEditing(undefined); await management.refresh()
    } catch (failure) {
      if (!mounted.current || generation !== workflowActions.generation()) return
      setError(failure instanceof Error && !('status' in failure) ? failure.message : managementError(failure, false))
      if (workflowActions.snapshot().pending) setEditing(undefined)
    }
  }
  return <DetailSection label={t('已结算流量修正')} title={t('已结算流量修正')} description={t('逐条修正计费用量，保留原始观测和完整修正记录')} className="reference-detail-card">
    <NetworkRecovery management={management} blocked={locked} /><ManagementFeedback error={entries.isError ? entries.error : management.denied ? history.error : undefined} loading={entries.isFetching || history.isFetching} onRetry={() => void management.refresh()} />
    {current?.items.map(item => <div key={item.entry_id} className="reference-detail-card"><strong>{t('结算记录')} #{item.entry_id} · {t('节点')} #{item.node_id}</strong><p>{t('用量周期')} #{item.period_id} · {unixDate(Number(item.ended_at))}</p>
      <p>{t('原始计费上行 / 下行')} {item.original_up_bytes} / {item.original_down_bytes} {t('字节')}</p><p>{t('当前计费上行 / 下行')} {item.billed_up_bytes} / {item.billed_down_bytes} {t('字节')}</p>
      {item.editable ? <button className="button" disabled={blocked || entries.isFetching} onClick={() => { draftGeneration.current = generation; setError(''); setEditing({ entry: item, draft: draftFor(item) }) }}>{t('修正这条计费用量')}</button> : null}</div>)}
    {current && current.items.length === 0 ? <p>{t('暂无可修正的原生已结算用量。')}</p> : null}
    {current?.next_cursor ? <button className="button" disabled={entries.isFetching} onClick={() => setCursor(current.next_cursor!)}>{t('更早结算记录')}</button> : cursor ? <button className="button" onClick={() => setCursor(undefined)}>{t('返回最新结算记录')}</button> : null}
    {!management.denied ? <button className="button" onClick={() => { setShowHistory(value => !value); setHistoryCursor(undefined) }}>{t(showHistory ? '收起流量修正记录' : '查看流量修正记录')}</button> : null}
    {showHistory && !management.denied ? <div><ManagementFeedback error={history.isError ? history.error : undefined} loading={history.isFetching} onRetry={() => void management.refresh()} />
      {!history.isError ? history.data?.data.items.map(item => <div className="reference-detail-card" key={item.correction_id}><strong>{t('修正记录')} #{item.correction_id} · {t('结算记录')} #{item.entry_id}</strong><p>{item.reason} · {t('操作人')} #{item.actor_id} · {unixDate(Number(item.occurred_at))}</p><p>{item.before_up_bytes} / {item.before_down_bytes} → {item.billed_up_bytes} / {item.billed_down_bytes} {t('字节')}</p></div>) : null}
      {!history.isFetching && !history.isError && !history.data?.data.items.length ? <p>{t('暂无流量修正记录。')}</p> : null}
      {history.data?.data.next_cursor ? <button className="button" disabled={history.isFetching} onClick={() => setHistoryCursor(history.data!.data.next_cursor!)}>{t('更早流量修正记录')}</button> : historyCursor ? <button className="button" onClick={() => setHistoryCursor(undefined)}>{t('返回最新流量修正记录')}</button> : null}</div> : null}
    {editing && !management.denied ? <Modal open title={t('修正已结算计费用量')} subtitle={t('按单条结算记录填写修正后的计费字节数，预留用量保持原证据')} onClose={close}><form className="native-content-form" onSubmit={submit}>
      <p>{t('结算记录')} #{editing.entry.entry_id} · {t('用量周期')} #{editing.entry.period_id}</p>
      <Field label={t('修正后计费上行（字节）')} required><Input inputMode="numeric" value={editing.draft.up} disabled={blocked} onChange={event => update('up', event.target.value)} /></Field>
      <Field label={t('修正后计费下行（字节）')} required><Input inputMode="numeric" value={editing.draft.down} disabled={blocked} onChange={event => update('down', event.target.value)} /></Field>
      <Field label={t('流量修正原因编号')} required><Input maxLength={64} value={editing.draft.reason} disabled={blocked} onChange={event => update('reason', event.target.value)} /></Field>
      {error ? <><p role="alert">{t(error)}</p><button type="button" className="button" disabled={management.state.busy} onClick={async () => { if (!dirty || (await confirm(t('重新读取会丢弃流量修正草稿，确认继续？')))) { setEditing(undefined); void management.refresh() } }}>{t('重新读取已结算用量')}</button></> : null}
      <div className="network-form-actions"><button className="button primary" disabled={blocked}>{t('保存流量修正')}</button><button type="button" className="button" disabled={management.state.busy} onClick={close}>{t('取消')}</button></div>
    </form></Modal> : null}
  </DetailSection>
}
