import { RefreshCw, X } from 'lucide-react'
import './observation-reference.css'
import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { ReadOnlyNotice } from '../components/ui/ReadOnlyNotice'
import { StatusBadge } from '../components/ui/StatusBadge'
import { DataTable, type Column } from '../components/ui/DataTable'

type Failure = CoreOutput<'platform.queue.failures.list'>[number]
const statuses = { unavailable: '不可用', not_applicable: '不适用', inactive: '未运行', paused: '已暂停', running: '运行中' }
export function QueuePage() {
  const session = useAdminSession(), [selected, setSelected] = useState(''), [autoRefresh, setAutoRefresh] = useState(false)
  const snapshot = useQuery({ queryKey: ['native-admin', 'queue-snapshot'], enabled: session,
    queryFn: ({ signal }) => admin.read('platform.queue.snapshot.get', {}, signal) })
  const failures = useQuery({ queryKey: ['native-admin', 'queue-failures'], enabled: session,
    queryFn: ({ signal }) => admin.read('platform.queue.failures.list', { limit: 30 }, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'queue-failure', selected], enabled: session && !!selected,
    queryFn: ({ signal }) => admin.read('platform.queue.failures.get', { failure_id: selected }, signal) })
  const denied = [snapshot.error, failures.error, detail.error].some(error => error instanceof CoreAdminApiError && [401, 403].includes(error.status))
  const visible = session && !denied
  const current = visible && !snapshot.isError ? snapshot.data?.data : undefined
  const failure = visible && !detail.isError ? detail.data?.data : undefined
  async function refresh() { await Promise.all([snapshot.refetch(), failures.refetch(), ...(selected ? [detail.refetch()] : [])]) }
  const refreshRef = useRef(refresh)
  refreshRef.current = refresh
  useEffect(() => {
    if (!autoRefresh || !session) return
    const timer = window.setInterval(() => { void refreshRef.current() }, 30_000)
    return () => window.clearInterval(timer)
  }, [autoRefresh, session])
  const columns: Column<Failure>[] = [
    { key: 'id', header: '失败记录', render: row => <button className="native-read-link" onClick={() => setSelected(row.id)}>#{row.id}</button> },
    { key: 'connection', header: '连接', render: row => row.connection },
    { key: 'queue', header: '队列', render: row => <span className="observation-tag">{row.queue}</span> },
    { key: 'job', header: '任务', render: row => <span className="observation-mono">{row.job}</span> },
    { key: 'failed_at', header: '失败时间', render: row => <span className="observation-date">{row.failed_at}</span> },
  ]
  return <div className="native-management-page observation-page observation-queue-page">
    <PageHeader title="队列监控" description="关注任务积压与失败情况" action={<>
      <button type="button" className="button" aria-pressed={autoRefresh} disabled={!session} onClick={() => setAutoRefresh(value => !value)}>{autoRefresh ? '停止自动刷新' : '自动刷新'}</button>
      <button className="button" disabled={!session || snapshot.isFetching || failures.isFetching} onClick={() => void refresh()}><RefreshCw size={15} />刷新</button>
    </>} />
    <ReadOnlyNotice title="队列状态只读">快照和失败记录用于运行观察 · 不展示任务载荷或原始异常 · 不提供重试和清理入口</ReadOnlyNotice>
    {denied ? <p role="alert">无法确认队列读取权限，已隐藏快照和失败记录。</p> : null}
    <section className="list-card"><div className="observation-card-heading"><div><h2>运行概况</h2><p>{current ? '更新于 ' + current.observed_at : '队列与任务处理状态'}</p></div>{current ? <StatusBadge tone={current.status === 'running' ? 'success' : 'warning'}>{statuses[current.status]}</StatusBadge> : null}</div><ManagementFeedback loading={snapshot.isFetching} error={snapshot.error} onRetry={() => void refresh()} />
      {current ? <><div className="observation-summary">{[['待处理任务', current.pending_jobs], ['每分钟任务', current.jobs_per_minute], ['等待时间 / 秒', current.wait_seconds], ['近七日失败', current.failed_last_7_days]].map(([label, value]) => <div className="observation-stat" key={label}><span>{label}</span><strong>{value ?? '—'}</strong>{value === null ? <small>当前未提供此指标</small> : null}</div>)}</div><details className="observation-help"><summary>更多运行信息</summary><ReadFields fields={[
        ['状态', statuses[current.status]], ['连接', current.connection], ['进程数', current.processes], ['最近任务', current.recent_jobs], ['每分钟任务', current.jobs_per_minute],
        ['待处理任务', current.pending_jobs], ['等待秒数', current.wait_seconds], ['最长等待队列', current.longest_wait_queue], ['近七日失败', current.failed_last_7_days], ['观测时间', current.observed_at],
        ['失败记录可用', current.failed_jobs_available ? '是' : '否'],
      ]} /></details></> : null}
    </section>
    <section className="list-card native-read-list"><div className="observation-card-heading"><div><h2>失败任务</h2><p>最近 30 条记录</p></div><StatusBadge tone={visible && !failures.isError && failures.data?.data.length ? 'warning' : 'neutral'} dot={false}>{visible && !failures.isError ? failures.data?.data.length ?? '—' : '—'} 条</StatusBadge></div><ManagementFeedback loading={failures.isFetching} error={failures.error} onRetry={() => void refresh()} />
      {visible && failures.data && !failures.isError ? <><p className="native-scroll-hint">左右滑动表格查看完整信息</p><DataTable label="队列失败记录" rows={failures.data.data} columns={columns} rowKey={row => row.id} empty="没有失败记录" /></> : null}
    </section>
    {selected && visible ? <section className="list-card observation-detail" aria-label="失败记录详情"><div className="observation-card-heading"><div><h2>失败任务 #{selected}</h2><p>核对连接、队列与任务标识</p></div><button className="button" aria-label="关闭失败详情" onClick={() => setSelected('')}><X size={15} />关闭</button></div><ManagementFeedback loading={detail.isFetching} error={detail.error} onRetry={() => void refresh()} />
      {failure ? <ReadFields fields={[
        ['记录 ID', failure.id], ['连接', failure.connection], ['队列', failure.queue], ['任务', failure.job], ['失败时间', failure.failed_at],
      ]} /> : null}
    </section> : null}
  </div>
}
