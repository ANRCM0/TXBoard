import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { CoreInput, CoreOutput } from '@txboard/core-sdk'
import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { DetailSection } from '../components/ui/DetailSection'
import { Field, Input, Select } from '../components/ui/Field'
import { Modal } from '../components/ui/Modal'
import { ReadFields } from './ReadList'
import { ManagementFeedback, managementError } from './Feedback'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import { admin, workflowActions } from './session'
import { unixDate } from './readPresentation'
import { useAdminConfirmation } from './useAdminConfirmation'

type Policy = CoreOutput<'billing.admin-customer-policy.get'>
type Update = CoreInput<'billing.admin-customer-policy.update'>
type Draft = { discount: string; mode: Policy['commission_mode']; rate: string; reason: string }
const percent = (value: number) => (value / 100).toFixed(2)
const draftFor = (value: Policy): Draft => ({ discount: percent(value.discount_bps), mode: value.commission_mode, rate: value.commission_rate_bps === null ? '' : percent(value.commission_rate_bps), reason: '' })
function basisPoints(value: string): number {
  if (!/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/.test(value)) throw new Error('请输入 0 到 100 的百分比，最多两位小数。')
  const [whole, fraction = ''] = value.split('.'), parsed = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  if (parsed > 10000) throw new Error('请输入 0 到 100 的百分比，最多两位小数。')
  return parsed
}
export function customerPolicyInput(base: Policy, draft: Draft): Update {
  const discount = basisPoints(draft.discount), rate = draft.rate.trim() === '' ? null : basisPoints(draft.rate), changes: Update['changes'] = {}
  if (discount !== base.discount_bps) changes.discount_bps = discount
  if (draft.mode !== base.commission_mode) changes.commission_mode = draft.mode
  if (rate !== base.commission_rate_bps) changes.commission_rate_bps = rate
  if (!Object.keys(changes).length) throw new Error('请至少修改一项个人购买或佣金政策。')
  if (!/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/.test(draft.reason)) throw new Error('请填写原因编号：字母、数字及 _ . : -，最多 64 字符。')
  return { user_id: base.user_id, expected_policy_revision: base.policy_revision, changes, reason: draft.reason }
}
function modeLabel(mode: Policy['commission_mode']): string { return t(mode === 'inherit' ? '跟随系统（循环）' : mode === 'first_paid' ? '仅首次付费' : '循环返佣') }

export function CustomerPolicyPanel({ userId, locked, onChanged }: { userId: string; locked: boolean; onChanged: () => void }) {
  const confirm = useAdminConfirmation(() => !locked && !management.denied, userId)
  useLocale()
  const [showHistory, setShowHistory] = useState(false), [cursor, setCursor] = useState<string>()
  const query = useQuery({ queryKey: ['native-admin', 'customer-policy', userId], queryFn: ({ signal }) => admin.read('billing.admin-customer-policy.get', { user_id: userId }, signal) })
  const history = useQuery({ queryKey: ['native-admin', 'customer-policy-history', userId, cursor], enabled: showHistory && !query.isError,
    queryFn: ({ signal }) => admin.read('billing.admin-customer-policy.history', { user_id: userId, limit: 25, ...(cursor ? { before: cursor } : {}) }, signal) })
  const management = useNetworkManagement('billing.admin-customer-policy.', [query.error, history.error], async () => {
    await query.refetch({ throwOnError: true }); if (showHistory || history.isError) await history.refetch({ throwOnError: true }); onChanged()
  }, userId)
  const [editing, setEditing] = useState<{ base: Policy; draft: Draft }>(), [error, setError] = useState('')
  const generation = workflowActions.generation(), mounted = useRef(true), draftGeneration = useRef(generation)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { setEditing(undefined); setError(''); setShowHistory(false); setCursor(undefined) }, [userId, generation, management.denied])
  const current = query.isError || management.denied ? undefined : query.data?.data, blocked = locked || management.state.busy || !!management.state.pending || management.denied
  const dirty = !!editing && JSON.stringify(editing.draft) !== JSON.stringify(draftFor(editing.base))
  useDirtyDraft(dirty && draftGeneration.current === generation && !management.denied && !management.state.pending)
  useEffect(() => { if (!dirty) return; const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }; window.addEventListener('beforeunload', guard); return () => window.removeEventListener('beforeunload', guard) }, [dirty])
  async function close() { if (!management.state.busy && (!dirty || (await confirm(t('关闭会丢弃尚未提交的个人政策修改，确认关闭？'))))) setEditing(undefined) }
  function update<K extends keyof Draft>(field: K, value: Draft[K]) { setEditing(old => old ? { ...old, draft: { ...old.draft, [field]: value } } : old) }
  async function submit(event: FormEvent) {
    event.preventDefault(); if (!editing || blocked) return; setError('')
    try {
      const input = customerPolicyInput(editing.base, editing.draft)
      if (!(await confirm(t('确认修改未来订单的个人购买和佣金政策？既有订单、奖励和退款保持原快照。')))) return
      await workflowActions.run('billing.admin-customer-policy.update', input)
      if (!mounted.current || generation !== workflowActions.generation()) return
      setEditing(undefined); await management.refresh()
    } catch (failure) {
      if (!mounted.current || generation !== workflowActions.generation()) return
      setError(failure instanceof Error && !('status' in failure) ? failure.message : managementError(failure, false))
      if (workflowActions.snapshot().pending) setEditing(undefined)
    }
  }
  return <DetailSection label={t('个人购买与佣金政策')} title={t('个人购买与佣金政策')} description={t('只影响之后创建的原生订单；已有购买和奖励按原快照执行')} className="reference-detail-card"
    actions={current?.editable ? <button className="button" disabled={blocked || query.isFetching} onClick={() => { draftGeneration.current = generation; setError(''); setEditing({ base: current, draft: draftFor(current) }) }}>{t('修改个人政策')}</button> : undefined}>
    <NetworkRecovery management={management} blocked={locked} /><ManagementFeedback error={query.isError ? query.error : management.denied ? history.error : undefined} loading={query.isFetching || history.isFetching} onRetry={() => void management.refresh()} />
    {current ? <ReadFields fields={[[t('个人折扣'), percent(current.discount_bps) + '%'], [t('作为推荐人的返佣类型'), modeLabel(current.commission_mode)],
      [t('作为推荐人的佣金比例'), current.commission_rate_bps === null ? t('跟随系统') : percent(current.commission_rate_bps) + '%'], [t('政策版本'), current.policy_version]]} /> : null}
    {current ? <p>{t('折扣表示从套餐原价中减去的比例；与优惠券按原价合计封顶，升级先扣个人折扣再使用原订阅信用。首次付费按原生资金成功分配的顺序认定，退款不会转移首次资格。')}</p> : null}
    {!management.denied ? <button className="button" onClick={() => { setShowHistory(value => !value); setCursor(undefined) }}>{t(showHistory ? '收起政策历史' : '查看政策历史')}</button> : null}
    {showHistory && !management.denied ? <div><ManagementFeedback error={history.isError ? history.error : undefined} loading={history.isFetching} onRetry={() => void management.refresh()} />
      {!history.isError ? history.data?.data.items.map(item => <div className="reference-detail-card" key={item.version}><strong>{t('政策版本')} {item.version}</strong><p>{item.reason} · {t('操作人')} #{item.actor_id} · {unixDate(Number(item.created_at))}</p><p>{t('个人折扣')} {percent(item.policy.discount_bps)}% · {modeLabel(item.policy.commission_mode)} · {item.policy.commission_rate_bps === null ? t('跟随系统') : percent(item.policy.commission_rate_bps) + '%'}</p></div>) : null}
      {!history.isFetching && !history.isError && !history.data?.data.items.length ? <p>{t('暂无个人政策变更记录。')}</p> : null}
      {history.data?.data.next_cursor ? <button className="button" disabled={history.isFetching} onClick={() => setCursor(history.data!.data.next_cursor!)}>{t('更早政策记录')}</button> : cursor ? <button className="button" onClick={() => setCursor(undefined)}>{t('返回最新政策记录')}</button> : null}</div> : null}
    {editing && !management.denied ? <Modal open title={t('修改个人政策')} subtitle={t('金额按整数最小单位运算；百分比精确到 0.01%')} onClose={close}><form className="native-content-form" onSubmit={submit}>
      <Field label={t('个人折扣（%）')} hint={t('0 表示无折扣，100 表示免除套餐原价')} required><Input inputMode="decimal" disabled={blocked} value={editing.draft.discount} onChange={event => update('discount', event.target.value)} /></Field>
      <Field label={t('作为推荐人的返佣类型')}><Select disabled={blocked} value={editing.draft.mode} onChange={event => update('mode', event.target.value as Draft['mode'])}><option value="inherit">{t('跟随系统（循环）')}</option><option value="recurring">{t('循环返佣')}</option><option value="first_paid">{t('仅首次付费')}</option></Select></Field>
      <Field label={t('作为推荐人的佣金比例（%）')} hint={t('留空跟随系统；0 表示不产生佣金，系统关闭返佣时个人政策不会启用返佣')}><Input inputMode="decimal" disabled={blocked} value={editing.draft.rate} onChange={event => update('rate', event.target.value)} /></Field>
      <Field label={t('政策变更原因编号')} required><Input maxLength={64} disabled={blocked} value={editing.draft.reason} onChange={event => update('reason', event.target.value)} /></Field>
      {error ? <><p role="alert">{t(error)}</p><button type="button" className="button" disabled={management.state.busy} onClick={async () => { if (!dirty || (await confirm(t('重新读取会丢弃个人政策草稿，确认继续？')))) { setEditing(undefined); void management.refresh() } }}>{t('重新读取个人政策')}</button></> : null}
      <div className="network-form-actions"><button className="button primary" disabled={blocked}>{t('保存个人政策')}</button><button type="button" className="button" disabled={management.state.busy} onClick={close}>{t('取消')}</button></div>
    </form></Modal> : null}
  </DetailSection>
}
