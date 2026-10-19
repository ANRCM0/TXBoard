import { ShieldCheck, AlertCircle, RefreshCw } from 'lucide-react'
import './detail-reference.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { isoDate, orderStatus } from './readPresentation'
import { requestConfirm } from '../components/ui/ConfirmDialog'

const isOrderCommand = (operation: string) => operation === 'commerce.admin-orders.close' || operation === 'commerce.admin-orders.retry'
async function refreshOrders(tradeNo: string, invalidate: ReturnType<typeof useQueryClient>['invalidateQueries']) {
  await Promise.all([
    invalidate({ queryKey: ['native-admin', 'admin-orders'] }),
    invalidate({ queryKey: ['native-admin', 'order-detail', tradeNo] }),
    invalidate({ queryKey: ['native-admin', 'order-audit', tradeNo] }),
  ])
}

export function OrderCommandRecovery({ onDenied }: { onDenied?: (error: unknown) => void }) {
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [error, setError] = useState(''), active = useRef(true)
  useEffect(() => { active.current = true; return () => { active.current = false } }, [])
  const action = state.pending
  if (!action || !isOrderCommand(action.operation)) return null
  async function recover() {
    const pending = workflowActions.snapshot().pending
    if (!pending || (pending.operation !== 'commerce.admin-orders.close' && pending.operation !== 'commerce.admin-orders.retry')
      || workflowActions.snapshot().busy) return
    const generation = workflowActions.generation()
    setError('')
    try {
      await workflowActions.run(pending.operation, pending.input)
      if (!active.current || workflowActions.generation() !== generation) return
      await refreshOrders((pending.input as { trade_no: string }).trade_no, client.invalidateQueries.bind(client))
    } catch (failure) {
      if (!active.current || workflowActions.generation() !== generation) return
      setError(managementError(failure))
      if (failure instanceof CoreAdminApiError && [401, 403].includes(failure.status)) onDenied?.(failure)
    }
  }
  return <section role="status" className="list-card reference-order-recovery">
    <h3>订单操作等待确认</h3><p>订单 {(action.input as { trade_no: string }).trade_no} 的操作结果尚未确认，请继续确认原操作。</p>
    <button className="button" disabled={state.busy} onClick={() => void recover()}>确认原订单操作结果</button>
    {error ? <p role="alert">{error}</p> : null}
  </section>
}

export function OrderAdministration({ tradeNo, status, onDenied }: {
  tradeNo: string; status: number; onDenied: (error: unknown) => void
}) {
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [reason, setReason] = useState('admin_reviewed'), [cursor, setCursor] = useState('0')
  const [error, setError] = useState(''), [notice, setNotice] = useState('')
  const active = useRef(true)
  useEffect(() => { active.current = true; return () => { active.current = false } }, [])
  const audit = useQuery({ queryKey: ['native-admin', 'order-audit', tradeNo, cursor],
    queryFn: ({ signal }) => admin.read('commerce.admin-orders.audit.list', { trade_no: tradeNo, after_id: cursor, limit: 20 }, signal) })
  useEffect(() => {
    if (audit.error instanceof CoreAdminApiError && [401, 403, 404].includes(audit.error.status)) onDenied(audit.error)
  }, [audit.error, onDenied])
  const locked = state.busy || !!state.pending || audit.isFetching || audit.isError || !audit.data
  const validReason = /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/.test(reason)
  async function execute(action: 'close' | 'retry') {
    if (!active.current || locked || !validReason || workflowActions.snapshot().busy || workflowActions.snapshot().pending) return
    const generation = workflowActions.generation()
    setError(''); setNotice('')
    try {
      if (action === 'close') await workflowActions.run('commerce.admin-orders.close', { trade_no: tradeNo, expected_status: 0, reason })
      else await workflowActions.run('commerce.admin-orders.retry', { trade_no: tradeNo, expected_status: 1, reason })
      if (!active.current || workflowActions.generation() !== generation) return
      setNotice(action === 'close' ? '订单关闭请求已确认。' : '原订单恢复请求已受理，请通过状态时间线确认履约结果。')
      setCursor('0')
      await refreshOrders(tradeNo, client.invalidateQueries.bind(client))
    } catch (failure) {
      if (!active.current || workflowActions.generation() !== generation) return
      setError(failure instanceof CoreAdminApiError && failure.code === 'ADMIN_ORDER_PAYMENT_UNCONFIRMED'
        ? '此订单存在未确认的支付尝试，请先确认支付结果。' : managementError(failure))
      if (failure instanceof CoreAdminApiError && [401, 403, 404].includes(failure.status)) onDenied(failure)
      else if (failure instanceof CoreAdminApiError && failure.status === 409 && !failure.needsStateConfirmation) {
        await refreshOrders(tradeNo, client.invalidateQueries.bind(client))
      }
    }
  }
  function confirmClose() {
    if (locked || !validReason) return
    requestConfirm({ title: '关闭待支付订单', message: `确认关闭订单 ${tradeNo}？如有钱包预留，将按原账本释放。`,
      confirmLabel: '确认关闭订单', danger: true, action: () => { void execute('close') } })
  }
  return <section className="reference-order-administration" aria-label="订单管理操作">
    <div className="reference-detail-section-title"><ShieldCheck size={17} /><h3>订单管理操作</h3></div><OrderCommandRecovery onDenied={onDenied} />
    {(status === 0 || status === 1) ? <div className="reference-order-command">
      <label>操作原因标识<input placeholder="例如：admin_reviewed" aria-label="订单操作原因标识" value={reason} maxLength={64} disabled={locked} aria-invalid={!validReason}
        onChange={event => setReason(event.target.value)} autoComplete="off" /></label>
      {status === 0 ? <button className="button" disabled={locked || !validReason} data-danger="true" onClick={confirmClose}>关闭待支付订单</button>
        : <button className="button" disabled={locked || !validReason} onClick={() => void execute('retry')}><RefreshCw size={14} />恢复原订单履约</button>}
      <p className="reference-command-help"><AlertCircle size={14} /><span>{status === 0 ? '支付结果未确认时不能关闭订单。' : '恢复沿用原订单及付款事实，受理后请查看履约结果。'}</span></p><details className="reference-detail-help"><summary>如何填写操作原因？</summary><p>使用简短的原因标识，便于后续检索。支持字母、数字及下划线、点、冒号、短横线，最长 64 个字符。</p></details>
    </div> : null}
    {error ? <p role="alert">{error}</p> : null}{notice ? <p className="commerce-confirmed-notice" role="status">{notice}</p> : null}
    <div className="reference-detail-section-title"><ShieldCheck size={16} /><h3>管理员操作审计</h3></div><details className="reference-detail-help"><summary>关于管理员操作审计</summary><p>记录管理员、操作原因以及订单状态变化。恢复请求受理后，请继续通过状态时间线确认实际履约结果。</p></details>
    {audit.isError ? <p role="alert">{managementError(audit.error)}</p> : null}
    {audit.isFetching ? <p role="status">正在读取操作审计…</p> : null}
    {!audit.isError && audit.data ? <>
      {audit.data.data.length ? <ol className="reference-order-audit-list">{audit.data.data.map(row => <li key={row.id}>
        <div className="commerce-audit-heading"><strong>{row.action === 'close' ? '关闭订单' : '申请恢复履约'}</strong><time dateTime={row.occurred_at}>{isoDate(row.occurred_at)}</time></div><span className="commerce-table-note">管理员 #{row.actor_id}</span>
        <p>{orderStatus(row.from_status)} → {orderStatus(row.to_status)} · 原因：{row.reason}</p>
      </li>)}</ol> : <p>暂无管理员操作记录。</p>}
      <div className="commerce-row-actions commerce-audit-pagination" role="group" aria-label="订单审计翻页"><button className="button" disabled={audit.isFetching} onClick={() => { workflowActions.clearError(); void audit.refetch() }}>重新读取审计</button>
      {audit.data.meta.has_more ? <button className="button" disabled={audit.isFetching}
        onClick={() => setCursor(audit.data!.meta.next_after_id)}>下一页审计</button> : null}
      {cursor !== '0' ? <button className="button" disabled={audit.isFetching} onClick={() => setCursor('0')}>从头读取审计</button> : null}</div>
    </> : null}
  </section>
}
