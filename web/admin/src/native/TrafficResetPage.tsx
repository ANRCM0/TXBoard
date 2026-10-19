import './settings-reference.css'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { admin, workflowActions } from './session'
import { PageHeader } from '../components/ui/PageHeader'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'

export function TrafficResetPage() {
  const [input, setInput] = useState(''), [userId, setUserId] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'traffic-reset-account', userId],
    queryFn: ({ signal }) => admin.read('identity.accounts.get', { user_id: userId }, signal), enabled: !!userId })
  const management = useNetworkManagement('subscription.lifecycle.reset', [query.error], () => query.refetch({ throwOnError: true }))
  const account = !management.denied && !query.isError && input.trim() === userId ? query.data?.data : undefined
  const subscription = account?.subscription.authorized ? account.subscription : undefined
  const locked = !subscription || query.isFetching || management.state.busy || !!management.state.pending
  async function reset() {
    if (locked || !subscription || !window.confirm('确定重置用户 ' + account!.email + ' 的订阅流量周期？本次将使用订阅版本 ' + subscription.subscription_version + '，旧用量记录会保留')) return
    await management.perform(() => workflowActions.run('subscription.lifecycle.reset', { subscription_id: subscription.subscription_id, expected_version: subscription.subscription_version }))
  }
  const matchingPending = management.pending?.operation === 'subscription.lifecycle.reset' && subscription?.subscription_id === management.pending.input.subscription_id
  return <div className="native-management-page settings-reference">
    <PageHeader title="订阅流量重置" description="查找用户订阅，核对当前用量后开启新的流量周期" />
    <form className="list-card native-content-form settings-lookup" onSubmit={event => { event.preventDefault(); if (input.trim() === userId) void management.refresh(); else setUserId(input.trim()) }}><div><h2>查找用户</h2><p>输入用户 ID 读取当前订阅</p></div><label>用户 ID<input placeholder="输入用户 ID" required pattern="[1-9][0-9]{0,18}" maxLength={19} value={input} disabled={management.state.busy} onChange={event => setInput(event.target.value)} /></label><button className="button" disabled={management.state.busy || query.isFetching || !input.trim()}>读取当前订阅</button></form>
    <NetworkRecovery management={management} blocked={!matchingPending || query.isFetching || query.isError} />
    {management.pending ? <p>重新读取原用户并匹配订阅后，才能确认原重置操作</p> : null}
    {userId ? <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void management.refresh()} notFoundMessage="用户不存在或已被删除" /> : null}
    {account ? <section className="list-card settings-section"><header className="settings-section-heading"><div><h2>{account.email}</h2><p>订阅流量与周期</p></div><span className="settings-status">{subscription ? '已读取订阅' : '无可用订阅'}</span></header>{subscription ? <><div className="settings-usage-grid">{[['总额度', subscription.quota_bytes], ['已使用', subscription.consumed_bytes], ['剩余流量', subscription.remaining_bytes]].map(([label, value]) => <div key={label}><small>{label}</small><strong>{String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}<span>字节</span></strong></div>)}</div><details className="settings-help"><summary>查看订阅标识与原始用量</summary><ReadFields fields={[
      ['用户 ID', account.id], ['订阅 ID', subscription.subscription_id], ['订阅版本', subscription.subscription_version], ['当前流量周期', subscription.usage_period_id], ['额度字节', subscription.quota_bytes], ['已用字节', subscription.consumed_bytes], ['剩余字节', subscription.remaining_bytes],
    ]} /></details><footer className="settings-form-actions"><span>重置会开启新周期，历史用量仍保留</span><button className="button settings-danger-button" disabled={locked} onClick={() => void reset()}>重置订阅流量周期</button></footer></> : <p>当前没有可授权的原生订阅：{account.subscription.reason}</p>}</section> : null}
  </div>
}
