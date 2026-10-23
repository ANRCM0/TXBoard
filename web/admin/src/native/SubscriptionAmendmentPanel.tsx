import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { CoreInput, CoreOutput } from '@txboard/core-sdk'
import { DetailSection } from '../components/ui/DetailSection'
import { Field, Input } from '../components/ui/Field'
import { Modal } from '../components/ui/Modal'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import { ReadFields } from './ReadList'
import { unixDate } from './readPresentation'
import { AmendmentPlanSelector } from './AmendmentPlanSelector'
import { useAdminConfirmation } from './useAdminConfirmation'

type Amendment = NonNullable<CoreOutput<'subscription.admin.amendment.get'>['amendment']>
type Changes = CoreInput<'subscription.admin.amendment.apply'>['changes']
type Draft = { quota: string; speed: string; devices: string; expiry: string; reason: string;
  plan?: { binding: NonNullable<Changes['plan_revision']>; name: string; quota: string; speed: string; devices: string } }
const amendmentFieldLabels: Record<string, string> = { plan_revision: '管理员权益套餐修订', quota_bytes: '配额', expires_at: '到期时间', speed_limit_mbps: '限速', device_limit: '设备数' }
const maxInteger = 9223372036854775807n
const utcInput = (seconds: string | null) => {
  const date = seconds === null ? null : new Date(Number(seconds) * 1000)
  return date && Number.isFinite(date.getTime()) ? date.toISOString().slice(0, 19) : ''
}
const initial = (value: Amendment): Draft => ({ quota: value.quota_bytes, speed: value.speed_limit_mbps ?? '', devices: value.device_limit ?? '', expiry: utcInput(value.expires_at), reason: '' })

export function amendmentChanges(base: Amendment, draft: Draft): Changes {
  const integer = (value: string, max: bigint) => /^(0|[1-9][0-9]*)$/.test(value) && BigInt(value) <= max
  if (!integer(draft.quota, maxInteger) || BigInt(draft.quota) < BigInt(base.consumed_bytes)) throw new Error("配额必须是精确整数字节，且不能低于已用及已接受待结算流量。")
  for (const value of [draft.speed, draft.devices]) if (value !== '' && !integer(value, 4294967295n)) throw new Error("限速和设备数必须是 0–4294967295 的整数；留空使用原生未限定值。")
  const changes: Changes = {}
  if (draft.plan) changes.plan_revision = draft.plan.binding
  if (draft.quota !== (draft.plan?.quota ?? base.quota_bytes)) changes.quota_bytes = draft.quota
  if ((draft.speed || null) !== (draft.plan ? draft.plan.speed || null : base.speed_limit_mbps)) changes.speed_limit_mbps = draft.speed || null
  if ((draft.devices || null) !== (draft.plan ? draft.plan.devices || null : base.device_limit)) changes.device_limit = draft.devices || null
  if (draft.expiry !== utcInput(base.expires_at)) {
    const seconds = draft.expiry ? Date.parse(draft.expiry + 'Z') / 1000 : null
    if (seconds !== null && (!Number.isSafeInteger(seconds) || seconds <= Date.now() / 1000)) throw new Error("请选择未来的 UTC 到期时间，或留空设为长期有效。")
    changes.expires_at = seconds === null ? null : String(seconds)
  }
  if (!Object.keys(changes).length) throw new Error("请至少修改一项订阅条款。")
  if (!/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/.test(draft.reason)) throw new Error("请填写原因编号：字母、数字及 _ . : -，最多 64 字符。")
  return changes
}

export function SubscriptionAmendmentPanel({ userId, locked, onChanged }: { userId: string; locked: boolean; onChanged: () => void }) {
  const confirm = useAdminConfirmation(() => !locked && !management.denied, userId)
  useLocale()
  const snapshot = useQuery({ queryKey: ['native-admin', 'subscription-amendment', userId],
    queryFn: ({ signal }) => admin.read('subscription.admin.amendment.get', { user_id: userId }, signal) })
  const [showHistory, setShowHistory] = useState(false), [cursor, setCursor] = useState<string>()
  const history = useQuery({ queryKey: ['native-admin', 'subscription-amendment-history', userId, cursor], enabled: showHistory && !snapshot.isError,
    queryFn: ({ signal }) => admin.read('subscription.admin.amendment.history', { user_id: userId, limit: 25, ...(cursor ? { before: cursor } : {}) }, signal) })
  const management = useNetworkManagement('subscription.admin.amendment.', [snapshot.error, history.error], async () => {
    await snapshot.refetch({ throwOnError: true }); if (showHistory || history.isError) await history.refetch({ throwOnError: true }); onChanged()
  }, userId)
  const [editing, setEditing] = useState<{ base: Amendment; draft: Draft }>(), [error, setError] = useState(''), [selectingPlan, setSelectingPlan] = useState(false)
  const generation = workflowActions.generation(), mounted = useRef(true), draftGeneration = useRef(generation)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { setEditing(undefined); setError(''); setShowHistory(false); setCursor(undefined); setSelectingPlan(false) }, [userId, generation, management.denied])
  useEffect(() => { if (!editing) setSelectingPlan(false) }, [!!editing])
  const current = snapshot.isError || management.denied ? undefined : snapshot.data?.data.amendment
  const dirty = !!editing && JSON.stringify(editing.draft) !== JSON.stringify(initial(editing.base))
  useDirtyDraft(dirty && draftGeneration.current === generation && !management.denied && !management.state.pending)
  const busy = management.state.busy, blocked = locked || busy || !!management.state.pending || management.denied
  useEffect(() => {
    if (!dirty) return
    const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', guard); return () => window.removeEventListener('beforeunload', guard)
  }, [dirty])
  async function close() { if (!busy && (!dirty || (await confirm(t("关闭会丢弃尚未提交的订阅条款，确认关闭？"))))) setEditing(undefined) }
  function update(field: 'quota' | 'speed' | 'devices' | 'expiry' | 'reason', value: string) { setEditing(old => old ? { ...old, draft: { ...old.draft, [field]: value } } : old) }
  async function submit(event: FormEvent) {
    event.preventDefault(); if (!editing || blocked) return
    setError('')
    try {
      const changes = amendmentChanges(editing.base, editing.draft)
      if (changes.plan_revision && !(await confirm(t('确认立即变更管理员订阅权益套餐？不会创建付款、退款或修改原订单；到期时间仅在本次显式修改时变化。')))) return
      await workflowActions.run('subscription.admin.amendment.apply', { user_id: userId, subscription_id: editing.base.subscription_id,
        expected_subscription_version: editing.base.subscription_version, expected_terms_id: editing.base.terms_id,
        expected_period_version: editing.base.period_version, changes, reason: editing.draft.reason })
      if (!mounted.current || generation !== workflowActions.generation()) return
      setEditing(undefined); await management.refresh()
    } catch (failure) {
      if (!mounted.current || generation !== workflowActions.generation()) return
      setError(failure instanceof Error && !('status' in failure) ? failure.message : managementError(failure, false))
      if (workflowActions.snapshot().pending) setEditing(undefined)
    }
  }
  return <DetailSection label={t("订阅条款调整")} title={t("订阅条款调整")} description={t('管理员权益改配与条款调整保留审计；不创建付款、退款或改写原订单')} className="reference-detail-card"
    actions={current?.editable ? <button className="button" disabled={blocked || snapshot.isFetching} onClick={() => { draftGeneration.current = generation; setError(''); setEditing({ base: current, draft: initial(current) }) }}>{t("调整订阅条款")}</button> : undefined}>
    <NetworkRecovery management={management} blocked={locked} />
    <ManagementFeedback error={snapshot.isError ? snapshot.error : undefined} loading={snapshot.isFetching} onRetry={() => void management.refresh()} />
    {!management.denied && current ? <ReadFields fields={[
      [t('当前权益套餐 / 修订'), '#' + current.plan_id + ' / #' + current.plan_revision_id],
      [t("当前状态"), current.status === 'active' ? t("生效中") : t("已暂停")], [t("配额（字节）"), current.quota_bytes], [t("已用及待结算（字节）"), current.consumed_bytes],
      [t("到期时间"), current.expires_at === null ? t("长期有效") : unixDate(current.expires_at)],
      [t("限速"), current.speed_limit_mbps === null ? t("未限定") : current.speed_limit_mbps + ' Mbps'], [t("设备数"), current.device_limit ?? t("未限定")],
    ]} /> : !snapshot.isFetching && !snapshot.isError && !management.denied ? <p>{t("当前没有可调整的原生订阅。")}</p> : null}
    {!management.denied ? <button className="button" disabled={busy} onClick={() => { setShowHistory(value => !value); setCursor(undefined) }}>{showHistory ? t("收起调整记录") : t("查看调整记录")}</button> : null}
    {showHistory && !management.denied ? <div>
      <ManagementFeedback error={history.isError ? history.error : undefined} loading={history.isFetching} onRetry={() => void management.refresh()} />
      {!history.isError ? history.data?.data.items.map(item => <div className="reference-detail-card" key={item.id}><p><strong>{item.reason}</strong> · {t("操作人 #{actor} · {date}", { actor: item.actor_id, date: unixDate(item.result.applied_at) })}</p><p>{t("条款 #{previous} → #{current} · {fields}", { previous: item.result.previous_terms_id, current: item.result.terms_id, fields: Object.keys(item.changes).map(field => t(amendmentFieldLabels[field] ?? field)).join(', ') })}</p></div>) : null}
      {!history.isFetching && !history.isError && history.data?.data.items.length === 0 ? <p>{t("暂无订阅条款调整记录。")}</p> : null}
      {history.data?.data.next_cursor ? <button className="button" disabled={history.isFetching} onClick={() => setCursor(history.data!.data.next_cursor!)}>{t("更早记录")}</button> : cursor ? <button className="button" onClick={() => setCursor(undefined)}>{t("返回最新记录")}</button> : null}
    </div> : null}
    {editing && !management.denied ? <Modal open wide title={t("调整订阅条款")} subtitle={t("只提交发生变化的字段；保留已接受的流量与重置日")} onClose={close}>
      <form className="native-content-form" onSubmit={submit}>
        <button type="button" className="button" disabled={blocked} onClick={() => setSelectingPlan(value => !value)}>{t('管理员权益套餐改配')}</button>
        {selectingPlan ? <AmendmentPlanSelector disabled={blocked} onSelected={publication => {
          const terms = publication.revision.terms, quota = (BigInt(terms.quota_gib) * 1073741824n).toString()
          const speed = terms.speed_limit_mbps === null ? '' : String(terms.speed_limit_mbps), devices = terms.device_limit === null ? '' : String(terms.device_limit)
          setEditing(old => old ? { ...old, draft: { ...old.draft, quota, speed, devices, plan: { binding: { plan_id: publication.plan_id,
            revision_id: publication.revision.revision_id, content_hash: publication.revision.content_hash }, name: terms.name, quota, speed, devices } } } : old)
          setSelectingPlan(false)
        }} /> : null}
        {editing.draft.plan ? <div role="status"><p>{t('已选权益套餐')} {editing.draft.plan.name} · #{editing.draft.plan.binding.plan_id} / #{editing.draft.plan.binding.revision_id}</p><button type="button" className="button" disabled={blocked} onClick={() => setEditing(old => old ? { ...old, draft: { ...old.draft, ...initial(old.base), expiry: old.draft.expiry, reason: old.draft.reason, plan: undefined } } : old)}>{t('保留当前套餐')}</button></div> : null}
        <Field label={t("流量配额（精确字节）")} hint={t("1 GiB = 1073741824 字节；不得低于已用及待结算流量")}><Input inputMode="numeric" value={editing.draft.quota} disabled={blocked} onChange={event => update('quota', event.target.value)} /></Field>
        <Field label={t("到期时间（UTC）")} hint={t("留空为长期有效；快捷项从当前时刻起算")}><Input type="datetime-local" step="1" value={editing.draft.expiry} disabled={blocked} onChange={event => update('expiry', event.target.value)} /></Field>
        <div className="native-list-toolbar">{[7, 30, 90, 365].map(days => <button type="button" className="button" disabled={blocked} key={days} onClick={() => update('expiry', utcInput(String(Math.floor(Date.now() / 1000) + days * 86400)))}>{t("{days} 天后", { days })}</button>)}<button type="button" className="button" disabled={blocked} onClick={() => update('expiry', '')}>{t("长期有效")}</button></div>
        <Field label={t("限速（Mbps）")} hint={t("留空使用原生未限定值；0 保留其原生含义")}><Input inputMode="numeric" value={editing.draft.speed} disabled={blocked} onChange={event => update('speed', event.target.value)} /></Field>
        <Field label={t("设备数")} hint={t("留空使用原生未限定值")}><Input inputMode="numeric" value={editing.draft.devices} disabled={blocked} onChange={event => update('devices', event.target.value)} /></Field>
        <Field label={t("调整原因编号")} required hint={t("例如 support_case_482；将保留在调整记录中")}><Input value={editing.draft.reason} maxLength={64} disabled={blocked} onChange={event => update('reason', event.target.value)} /></Field>
        {error ? <><p role="alert">{t(error)}</p><button type="button" className="button" disabled={busy} onClick={async () => { if (!dirty || (await confirm(t("重新读取会丢弃当前条款草稿，确认继续？")))) { setEditing(undefined); void management.refresh() } }}>{t("重新读取当前条款")}</button></> : null}
        <div className="network-form-actions"><button className="button primary" disabled={blocked}>{t("保存订阅条款")}</button><button type="button" className="button" disabled={busy} onClick={close}>{t("取消")}</button></div>
      </form>
    </Modal> : null}
  </DetailSection>
}
