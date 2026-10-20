import { t, useLocale } from '../lib/i18n'
import { RefreshCw } from 'lucide-react'
import './observation-reference.css'
import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { ReadOnlyNotice } from '../components/ui/ReadOnlyNotice'
import { StatusBadge } from '../components/ui/StatusBadge'
import { QueueFailures } from './QueueFailures'

const statuses = { unavailable: '不可用', not_applicable: '不适用', inactive: '未运行', paused: '已暂停', running: '运行中' }
export function QueuePage() {
  useLocale()
  const session = useAdminSession(), [failureDenied, setFailureDenied] = useState(false), [autoRefresh, setAutoRefresh] = useState(false)
  const snapshot = useQuery({ queryKey: ['native-admin', 'queue-snapshot'], enabled: session,
    queryFn: ({ signal }) => admin.read('platform.queue.snapshot.get', {}, signal) })
  const denied = failureDenied || snapshot.error instanceof CoreAdminApiError && [401, 403].includes(snapshot.error.status)
  const visible = session && !denied
  const current = visible && !snapshot.isError ? snapshot.data?.data : undefined
  async function refresh() { await snapshot.refetch() }
  const refreshRef = useRef(refresh)
  refreshRef.current = refresh
  const fetching = useRef(false); fetching.current = snapshot.isFetching
  useEffect(() => {
    if (!autoRefresh || !session) return
    const timer = window.setInterval(() => { if (document.visibilityState === 'visible' && !fetching.current) void refreshRef.current() }, 30_000)
    return () => window.clearInterval(timer)
  }, [autoRefresh, session])
  return <div className="native-management-page observation-page observation-queue-page">
    <PageHeader title={t("队列监控")} description={t("关注任务积压与失败情况")} action={<>
      <button type="button" className="button" aria-pressed={autoRefresh} disabled={!session} onClick={() => setAutoRefresh(value => !value)}>{autoRefresh ? t('停止自动刷新') : t('自动刷新')}</button>
      <button className="button" disabled={!session || snapshot.isFetching} onClick={() => void refresh()}><RefreshCw size={15} />{t("刷新")}</button>
    </>} />
    <ReadOnlyNotice title={t("队列状态只读")}>{t("快照和失败记录用于运行观察 · 不展示任务载荷或原始异常 · 不提供重试和清理入口")}</ReadOnlyNotice>
    {denied ? <p role="alert">{t("无法确认队列读取权限，已隐藏快照和失败记录。")}</p> : null}
    <section className="list-card"><div className="observation-card-heading"><div><h2>{t("运行概况")}</h2><p>{current ? t('更新于 ') + current.observed_at : t('队列与任务处理状态')}</p></div>{current ? <StatusBadge tone={current.status === 'running' ? 'success' : 'warning'}>{t(statuses[current.status])}</StatusBadge> : null}</div><ManagementFeedback loading={snapshot.isFetching} error={snapshot.error} onRetry={() => void refresh()} />
      {current ? <><div className="observation-summary">{[[t('待处理任务'), current.pending_jobs], [t('每分钟任务'), current.jobs_per_minute], [t('等待时间 / 秒'), current.wait_seconds], [t('近七日失败'), current.failed_last_7_days]].map(([label, value]) => <div className="observation-stat" key={label}><span>{label}</span><strong>{value ?? '—'}</strong>{value === null ? <small>{t("当前未提供此指标")}</small> : null}</div>)}</div><details className="observation-help"><summary>{t("更多运行信息")}</summary><ReadFields fields={[
        [t('状态'), t(statuses[current.status])], [t('连接'), current.connection], [t('进程数'), current.processes], [t('最近任务'), current.recent_jobs], [t('每分钟任务'), current.jobs_per_minute],
        [t('待处理任务'), current.pending_jobs], [t('等待秒数'), current.wait_seconds], [t('最长等待队列'), current.longest_wait_queue], [t('近七日失败'), current.failed_last_7_days], [t('观测时间'), current.observed_at],
        [t('失败记录可用'), current.failed_jobs_available ? t('是') : t('否')],
      ]} /></details></> : null}
    </section>
    <section className="list-card"><QueueFailures onDenied={setFailureDenied} /></section>
  </div>
}
