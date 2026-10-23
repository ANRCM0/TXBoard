import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { CoreInput, CoreOutput } from '@txboard/core-sdk'
import { DetailSection } from '../components/ui/DetailSection'
import { Field, Input, Select, Textarea } from '../components/ui/Field'
import { Modal } from '../components/ui/Modal'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import { ReadFields } from './ReadList'
import { minorAmount, unixDate } from './readPresentation'
import { useAdminConfirmation } from './useAdminConfirmation'

type Balances = CoreOutput<'billing.admin-account-balances.get'>
type Adjustment = CoreInput<'billing.admin-account-adjustments.create'>
type Draft = { bucket: Adjustment['bucket']; direction: Adjustment['direction']; amount: string; reason: string; note: string }
const emptyDraft = (): Draft => ({ bucket: 'wallet_available', direction: 'credit', amount: '', reason: '', note: '' })
const currencies = ['CNY', 'USD', 'EUR', 'GBP', 'HKD', 'SGD', 'CAD', 'AUD', 'NZD', 'TWD']

export function fundsAdjustmentInput(base: Balances, draft: Draft): Adjustment {
  if (!/^[1-9][0-9]{0,18}$/.test(draft.amount) || BigInt(draft.amount) > 9223372036854775807n) throw new Error("请输入大于 0 的精确整数金额，单位为最小货币单位。")
  if (!/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/.test(draft.reason)) throw new Error("请填写原因编号：字母、数字及 _ . : -，最多 64 字符。")
  if (draft.bucket === 'commission_available' && base.currency !== 'CNY') throw new Error("当前原生佣金账户只支持 CNY。")
  const available = draft.bucket === 'wallet_available' ? base.wallet_available_minor : base.commission_available_minor
  if (draft.direction === 'debit' && BigInt(draft.amount) > BigInt(available)) throw new Error("扣减金额超过可用余额；冻结余额不能用于本次扣减。")
  return { user_id: base.user_id, currency: base.currency, bucket: draft.bucket, direction: draft.direction, amount_minor: draft.amount,
    expected_ledger_revision: base.ledger_revision, reason: draft.reason, note: draft.note.trim() || null }
}

export function AccountFundsPanel({ userId, locked, onChanged }: { userId: string; locked: boolean; onChanged: () => void }) {
  const confirm = useAdminConfirmation(() => !locked && !management.denied, userId)
  useLocale()
  const [currency, setCurrency] = useState('CNY'), [showHistory, setShowHistory] = useState(false), [cursor, setCursor] = useState<string>()
  const balances = useQuery({ queryKey: ['native-admin', 'account-funds', userId, currency],
    queryFn: ({ signal }) => admin.read('billing.admin-account-balances.get', { user_id: userId, currency }, signal) })
  const history = useQuery({ queryKey: ['native-admin', 'account-funds-history', userId, currency, cursor], enabled: showHistory && !balances.isError,
    queryFn: ({ signal }) => admin.read('billing.admin-account-adjustments.list', { user_id: userId, currency, limit: 25, ...(cursor ? { before: cursor } : {}) }, signal) })
  const management = useNetworkManagement('billing.admin-account-adjustments.', [balances.error, history.error], async () => {
    await balances.refetch({ throwOnError: true }); if (showHistory || history.isError) await history.refetch({ throwOnError: true }); onChanged()
  }, userId + ':' + currency)
  const [editing, setEditing] = useState<{ base: Balances; draft: Draft }>(), [error, setError] = useState('')
  const generation = workflowActions.generation(), mounted = useRef(true)
  const draftScope = useRef('')
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { setEditing(undefined); setError(''); setShowHistory(false); setCursor(undefined) }, [userId, currency, generation, management.denied])
  const current = balances.isError || management.denied ? undefined : balances.data?.data
  const blocked = locked || management.state.busy || !!management.state.pending || management.denied
  const dirty = !!editing && JSON.stringify(editing.draft) !== JSON.stringify(emptyDraft())
  useDirtyDraft(dirty && !locked && !management.denied && !management.state.pending && !!current?.editable
    && draftScope.current === userId + ':' + currency + ':' + generation)
  useEffect(() => {
    if (!dirty) return
    const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', guard); return () => window.removeEventListener('beforeunload', guard)
  }, [dirty])
  async function close() { if (!management.state.busy && (!dirty || (await confirm(t("关闭会丢弃尚未提交的资金调整，确认关闭？"))))) setEditing(undefined) }
  function update<K extends keyof Draft>(field: K, value: Draft[K]) { setEditing(old => old ? { ...old, draft: { ...old.draft, [field]: value } } : old) }
  async function submit(event: FormEvent) {
    event.preventDefault(); if (!editing || blocked) return
    setError('')
    try {
      const input = fundsAdjustmentInput(editing.base, editing.draft)
      const bucket = input.bucket === 'wallet_available' ? t("钱包可用余额") : t("佣金可用余额")
      if (!(await confirm(t("确认{direction}{bucket} {amount}？本次调整会保留资金记录。", { direction: input.direction === 'credit' ? t('增加') : t('扣减'), bucket, amount: minorAmount(input.amount_minor, input.currency) })))) return
      await workflowActions.run('billing.admin-account-adjustments.create', input)
      if (!mounted.current || generation !== workflowActions.generation()) return
      setEditing(undefined); await management.refresh()
    } catch (failure) {
      if (!mounted.current || generation !== workflowActions.generation()) return
      setError(failure instanceof Error && !('status' in failure) ? failure.message : managementError(failure, false))
      if (workflowActions.snapshot().pending) setEditing(undefined)
    }
  }
  return <DetailSection label={t("原生资金账户")} title={t("原生资金账户")} description={t("按币种查看可用与冻结资金；管理调整单独留账")} className="reference-detail-card"
    actions={current?.editable ? <button className="button" disabled={blocked || balances.isFetching} onClick={() => { draftScope.current = userId + ':' + currency + ':' + generation; setError(''); setEditing({ base: current, draft: emptyDraft() }) }}>{t("调整可用资金")}</button> : undefined}>
    <Field label={t("资金币种")}><Select value={currency} disabled={management.state.busy || !!management.state.pending || !!editing} onChange={event => setCurrency(event.target.value)}>{currencies.map(code => <option key={code}>{code}</option>)}</Select></Field>
    <NetworkRecovery management={management} blocked={locked} />
    <ManagementFeedback error={balances.isError ? balances.error : undefined} loading={balances.isFetching} onRetry={() => void management.refresh()} />
    {current ? <ReadFields fields={[
      [t("钱包可用"), minorAmount(current.wallet_available_minor, current.currency)], [t("钱包冻结"), minorAmount(current.wallet_reserved_minor, current.currency)],
      [t("佣金可用"), minorAmount(current.commission_available_minor, current.currency)], [t("佣金冻结"), minorAmount(current.commission_reserved_minor, current.currency)],
    ]} /> : null}
    {!management.denied ? <button className="button" onClick={() => { setShowHistory(value => !value); setCursor(undefined) }}>{showHistory ? t("收起资金调整记录") : t("查看资金调整记录")}</button> : null}
    {showHistory && !management.denied ? <div>
      <ManagementFeedback error={history.isError ? history.error : undefined} loading={history.isFetching} onRetry={() => void management.refresh()} />
      {!history.isError ? history.data?.data.items.map(item => <div className="reference-detail-card" key={item.adjustment_id}><p><strong>{t("{bucket}{direction} {amount}", { bucket: item.bucket === 'wallet_available' ? t("钱包可用") : t("佣金可用"), direction: item.direction === 'credit' ? t("增加") : t("扣减"), amount: minorAmount(item.amount_minor, item.currency) })}</strong></p><p>{t("{reason} · 操作人 #{actor} · {date}", { reason: item.reason, actor: item.actor_id, date: unixDate(item.occurred_at) })}</p><p>{t("{before} → {after} · 记录 #{adjustment} · 凭证 #{journal}", { before: minorAmount(item.before_minor, item.currency), after: minorAmount(item.after_minor, item.currency), adjustment: item.adjustment_id, journal: item.journal_id })}</p>{item.note ? <p style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{item.note}</p> : null}</div>) : null}
      {!history.isFetching && !history.isError && history.data?.data.items.length === 0 ? <p>{t("当前币种暂无资金调整记录。")}</p> : null}
      {history.data?.data.next_cursor ? <button className="button" disabled={history.isFetching} onClick={() => setCursor(history.data!.data.next_cursor!)}>{t("更早资金记录")}</button> : cursor ? <button className="button" onClick={() => setCursor(undefined)}>{t("返回最新资金记录")}</button> : null}
    </div> : null}
    {editing && !management.denied ? <Modal open title={t("调整可用资金")} subtitle={t("增加或扣减指定币种的可用余额，冻结资金保持原用途")} onClose={close}>
      <form className="native-content-form" onSubmit={submit}>
        <Field label={t("资金账户")}><Select value={editing.draft.bucket} disabled={blocked} onChange={event => update('bucket', event.target.value as Draft['bucket'])}><option value="wallet_available">{t("钱包可用余额")}</option><option value="commission_available" disabled={editing.base.currency !== 'CNY'}>{t("佣金可用余额（CNY）")}</option></Select></Field>
        <Field label={t("调整方向")}><Select value={editing.draft.direction} disabled={blocked} onChange={event => update('direction', event.target.value as Draft['direction'])}><option value="credit">{t("增加")}</option><option value="debit">{t("扣减")}</option></Select></Field>
        <Field label={t("调整金额（{currency} 最小单位）", { currency: editing.base.currency })} hint={t("输入正整数，例如 1250 = 12.50；最终确认会显示实际金额")} required><Input inputMode="numeric" value={editing.draft.amount} disabled={blocked} onChange={event => update('amount', event.target.value)} /></Field>
        <Field label={t("资金调整原因编号")} required hint={t("例如 support_case_482；保留在资金记录中")}><Input value={editing.draft.reason} maxLength={64} disabled={blocked} onChange={event => update('reason', event.target.value)} /></Field>
        <Field label={t("资金调整备注")} hint={t("最多 500 字")}><Textarea value={editing.draft.note} maxLength={500} disabled={blocked} onChange={event => update('note', event.target.value)} /></Field>
        {error ? <><p role="alert">{t(error)}</p><button type="button" className="button" disabled={management.state.busy} onClick={async () => { if (!dirty || (await confirm(t("重新读取会丢弃资金调整草稿，确认继续？")))) { setEditing(undefined); void management.refresh() } }}>{t("重新读取资金账户")}</button></> : null}
        <div className="network-form-actions"><button className="button primary" disabled={blocked}>{t("确认资金调整")}</button><button type="button" className="button" disabled={management.state.busy} onClick={close}>{t("取消")}</button></div>
      </form>
    </Modal> : null}
  </DetailSection>
}
