import { t, useLocale } from '../lib/i18n'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { dashboardDates } from './overviewCharts'
import { DateRangePicker } from '../components/ui/DateRangePicker'
import { DataTable } from '../components/ui/DataTable'
import { ManagementFeedback } from './Feedback'
import { NetworkBytes } from './NetworkValues'

export function ResetHistoryPanel() {
  useLocale()
  const session = useAdminSession(), [dates, setDates] = useState(() => dashboardDates(30))
  const [draftUser, setDraftUser] = useState(''), [userId, setUserId] = useState('')
  const [cursors, setCursors] = useState<string[]>([])
  const before = cursors[cursors.length - 1]
  const history = useQuery({ queryKey: ['native-admin', 'reset-history', dates.start_date, dates.end_date, userId, before], enabled: session,
    queryFn: ({ signal }) => admin.read('subscription.lifecycle.resets.list', { ...dates, ...(userId ? { user_id: userId } : {}), ...(before ? { before_id: before } : {}), limit: 20 }, signal) })
  const denied = history.error instanceof CoreAdminApiError && [401, 403].includes(history.error.status)
  const current = session && !history.isError ? history.data?.data : undefined
  return <section className="list-card native-read-list reset-history" aria-label={t("流量重置历史")}>
    <div className="settings-section-heading"><div><h2>{t("流量重置历史")}</h2><p>{t("原生手动与自动周期重置 · UTC · 已关闭周期用量仍保留")}</p></div><button type="button" className="button" disabled={!session || history.isFetching} onClick={() => void history.refetch()}>{t("刷新历史")}</button></div>
    <div className="reset-history-filters"><DateRangePicker label={t("重置历史日期范围")} value={dates} onApply={value => { setDates(value); setCursors([]) }} onClear={() => { setDates(dashboardDates(30)); setCursors([]) }} />
      <form onSubmit={event => { event.preventDefault(); setUserId(draftUser.trim()); setCursors([]) }}><label>{t("用户 ID")}<input aria-label={t("历史用户 ID")} pattern="[1-9][0-9]{0,18}" maxLength={19} value={draftUser} onChange={event => setDraftUser(event.target.value)} placeholder={t("全部用户")} /></label><button className="button">{t("筛选历史")}</button></form>
    </div>
    <ManagementFeedback loading={history.isFetching} error={history.error} onRetry={() => void history.refetch()} />
    {denied ? <p role="alert">{t("无法确认历史读取权限，已隐藏重置记录。")}</p> : null}
    {current ? <><DataTable label={t("订阅流量重置历史")} rows={current.records} rowKey={row => row.id} mobilePrimaryKey="user" empty={t("此范围没有原生重置记录")} columns={[
      { key: 'user', header: t("用户"), render: row => <Link className="table-link" to={'/user/' + row.user_id}>#{row.user_id}</Link> },
      { key: 'occurred', header: t("重置时间 · UTC"), render: row => new Date(row.occurred_at * 1000).toISOString().replace('T', ' ').slice(0, 19) },
      { key: 'trigger', header: t("触发方式"), render: row => row.trigger === 'scheduled' ? t("自动周期") : t("手动重置") },
      { key: 'period', header: t("周期变化"), render: row => <span>#{row.previous_period_id} → #{row.usage_period_id}</span> },
      { key: 'used', header: t("旧周期计费用量"), render: row => <NetworkBytes value={row.previous_billable_bytes} /> },
      { key: 'quota', header: t("旧周期额度"), render: row => <NetworkBytes value={row.previous_quota_bytes} /> },
    ]} /><div className="native-page-controls"><button className="button" disabled={!cursors.length || history.isFetching} onClick={() => setCursors(value => value.slice(0, -1))}>{t("上一页历史")}</button><span>{t('第 {page} 页 · 每页 20 条', { page: cursors.length + 1 })}</span><button className="button" disabled={!current.next_before_id || history.isFetching} onClick={() => { if (current.next_before_id) setCursors(value => [...value, current.next_before_id!]) }}>{t("下一页历史")}</button></div><p className="settings-category-note">{t("用量为已关闭周期当前确认值；记录编号与原用量不因重置而删除。未记录的历史操作者信息不作推测。")}</p></> : null}
  </section>
}
