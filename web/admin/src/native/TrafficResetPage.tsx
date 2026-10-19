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
    if (locked || !subscription || !window.confirm('确定重置用户 ' + account!.email + ' 的订阅流量周期？本次将使用订阅版本 ' + subscription.subscription_version + '，旧用量记录会保留。')) return
    await management.perform(() => workflowActions.run('subscription.lifecycle.reset', { subscription_id: subscription.subscription_id, expected_version: subscription.subscription_version }))
  }
  const matchingPending = management.pending?.operation === 'subscription.lifecycle.reset' && subscription?.subscription_id === management.pending.input.subscription_id
  return <div className="native-management-page">
    <PageHeader title="订阅流量重置" description="读取用户当前原生订阅，核对额度后开启新的流量周期。" />
    <form className="list-card native-content-form" onSubmit={event => { event.preventDefault(); if (input.trim() === userId) void management.refresh(); else setUserId(input.trim()) }}><label>用户 ID<input required pattern="[1-9][0-9]{0,18}" maxLength={19} value={input} disabled={management.state.busy} onChange={event => setInput(event.target.value)} /></label><button className="button" disabled={management.state.busy || query.isFetching || !input.trim()}>读取当前订阅</button></form>
    <NetworkRecovery management={management} blocked={!matchingPending || query.isFetching || query.isError} />
    {management.pending ? <p>重新读取原用户并匹配订阅后，才能确认原重置操作。</p> : null}
    {userId ? <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void management.refresh()} notFoundMessage="用户不存在或已被删除。" /> : null}
    {account ? <section className="list-card"><h2>{account.email}</h2>{subscription ? <><ReadFields fields={[
      ['用户 ID', account.id], ['订阅 ID', subscription.subscription_id], ['订阅版本', subscription.subscription_version], ['当前流量周期', subscription.usage_period_id], ['额度字节', subscription.quota_bytes], ['已用字节', subscription.consumed_bytes], ['剩余字节', subscription.remaining_bytes],
    ]} /><button className="button" disabled={locked} onClick={() => void reset()}>重置订阅流量周期</button></> : <p>当前没有可授权的原生订阅：{account.subscription.reason}</p>}</section> : null}
  </div>
}
