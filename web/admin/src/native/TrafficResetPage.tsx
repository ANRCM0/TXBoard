import { t, useLocale, formatNumber } from '../lib/i18n'
import './settings-reference.css'
import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { admin, workflowActions } from './session'
import { PageHeader } from '../components/ui/PageHeader'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { QuotaBar } from '../components/ui/QuotaBar'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import { ResetHistoryPanel } from './ResetHistoryPanel'

export function TrafficResetPage() {
  useLocale()
  const client = useQueryClient()
  const [input, setInput] = useState(''), [userId, setUserId] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'traffic-reset-account', userId],
    queryFn: ({ signal }) => admin.read('identity.accounts.get', { user_id: userId }, signal), enabled: !!userId })
  const management = useNetworkManagement('subscription.lifecycle.reset', [query.error], async () => {
    await query.refetch({ throwOnError: true })
    await client.invalidateQueries({ queryKey: ['native-admin', 'reset-history'] })
  })
  const account = !management.denied && !query.isError && input.trim() === userId ? query.data?.data : undefined
  const subscription = account?.subscription.authorized ? account.subscription : undefined
  const locked = !subscription || query.isFetching || management.state.busy || !!management.state.pending
  async function reset() {
    if (locked || !subscription || !window.confirm(t('确定重置用户 {email} 的订阅流量周期？本次将使用订阅版本 {version}，旧用量记录会保留', { email: account!.email, version: subscription.subscription_version }))) return
    await management.perform(() => workflowActions.run('subscription.lifecycle.reset', { subscription_id: subscription.subscription_id, expected_version: subscription.subscription_version }))
  }
  const matchingPending = management.pending?.operation === 'subscription.lifecycle.reset' && subscription?.subscription_id === management.pending.input.subscription_id
  return <div className="native-management-page settings-reference">
    <PageHeader title={t("订阅流量重置")} description={t("查找用户订阅，核对当前用量后开启新的流量周期")} />
    <form className="list-card native-content-form settings-lookup" onSubmit={event => { event.preventDefault(); if (input.trim() === userId) void management.refresh(); else setUserId(input.trim()) }}><div><h2>{t("查找用户")}</h2><p>{t("输入用户 ID 读取当前订阅")}</p></div><label>{t("用户 ID")}<input placeholder={t("输入用户 ID")} required pattern="[1-9][0-9]{0,18}" maxLength={19} value={input} disabled={management.state.busy} onChange={event => setInput(event.target.value)} /></label><button className="button" disabled={management.state.busy || query.isFetching || !input.trim()}>{t("读取当前订阅")}</button></form>
    <NetworkRecovery management={management} blocked={!matchingPending || query.isFetching || query.isError} />
    {management.pending ? <p>{t("重新读取原用户并匹配订阅后，才能确认原重置操作")}</p> : null}
    {userId ? <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void management.refresh()} notFoundMessage={t("用户不存在或已被删除")} /> : null}
    {account ? <section className="list-card settings-section"><header className="settings-section-heading"><div><h2>{account.email}</h2><p>{t("订阅流量与周期")}</p></div><span className="settings-status">{subscription ? t("已读取订阅") : t("无可用订阅")}</span></header>{subscription ? <><QuotaBar used={subscription.consumed_bytes} total={subscription.quota_bytes} label={t("订阅流量")} /><div className="settings-usage-grid">{[[t("总额度"), subscription.quota_bytes], [t("已使用"), subscription.consumed_bytes], [t("剩余流量"), subscription.remaining_bytes]].map(([label, value]) => <div key={label}><small>{label}</small><strong>{formatNumber(BigInt(value))}<span>{t("字节")}</span></strong></div>)}</div><details className="settings-help"><summary>{t("查看订阅标识与原始用量")}</summary><ReadFields fields={[
      [t("用户 ID"), account.id], [t("订阅 ID"), subscription.subscription_id], [t("订阅版本"), subscription.subscription_version], [t("当前流量周期"), subscription.usage_period_id], [t("额度字节"), subscription.quota_bytes], [t("已用字节"), subscription.consumed_bytes], [t("剩余字节"), subscription.remaining_bytes],
    ]} /></details><footer className="settings-form-actions"><span>{t("重置会开启新周期，历史用量仍保留")}</span><button className="button settings-danger-button" disabled={locked} onClick={() => void reset()}>{t("重置订阅流量周期")}</button></footer></> : <p>{t("当前没有可授权的原生订阅：")}{account.subscription.reason}</p>}</section> : null}
    <ResetHistoryPanel />
  </div>
}
