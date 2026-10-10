import { useQuery } from '@tanstack/react-query'
import { AlertCircle, CheckCircle2, ChevronDown, ChevronUp, Cpu, Eye, RefreshCw, Timer, XCircle } from 'lucide-react'
import { useState } from 'react'
import { getQueueFailure, getQueueFailures, getQueueSnapshot, type QueueSnapshot } from '../../api/queueMonitor'
import { Modal } from '../ui/Modal'
import { QueryFeedback } from '../ui/QueryFeedback'

const statuses: Record<QueueSnapshot['status'], { label: string; description: string; tone: string }> = {
  running: { label: '运行正常', description: 'Horizon 队列正在运行', tone: 'ok' },
  paused: { label: '已暂停', description: 'Horizon 已暂停处理任务', tone: 'warn' },
  inactive: { label: '未运行', description: '未检测到 Horizon 心跳', tone: 'error' },
  unavailable: { label: '状态未知', description: '无法读取 Horizon 指标', tone: 'warn' },
  not_applicable: { label: '同步模式', description: '当前未启用异步队列', tone: 'neutral' },
}

function showNumber(value: number | null | undefined, suffix = ''): string {
  return value === null || value === undefined ? '—' : String(value) + suffix
}

export function QueueDashboardSections() {
  const [showErrors, setShowErrors] = useState(false)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const snapshot = useQuery({
    queryKey: ['adminQueueSnapshot'], queryFn: getQueueSnapshot,
    refetchInterval: 30_000, retry: 1,
  })
  const failures = useQuery({
    queryKey: ['adminQueueFailures'], queryFn: getQueueFailures,
    enabled: showErrors, refetchInterval: 60_000, retry: 1,
  })
  const detail = useQuery({
    queryKey: ['adminQueueFailure', selectedId],
    queryFn: () => getQueueFailure(selectedId!),
    enabled: selectedId !== null, retry: 1,
  })

  const data = snapshot.data
  const state = statuses[data?.status || 'unavailable']
  const isAvailable = Boolean(data && data.status !== 'unavailable' && data.status !== 'not_applicable')

  return (
    <div className="dashboard-queue-grid">
      <section className="admin-dashboard-card dashboard-queue-card">
        <div className="admin-dashboard-card-head">
          <div className="dashboard-queue-heading">
            <h2><Timer size={19} aria-hidden="true"/>队列状态</h2>
            <p>Horizon 实时运行状态</p>
          </div>
          <button type="button" className="dashboard-queue-icon-button" aria-label="刷新队列状态"
            title="刷新队列状态" disabled={snapshot.isFetching} onClick={() => void snapshot.refetch()}>
            <RefreshCw size={17} className={snapshot.isFetching ? 'loading-spinner' : ''}/>
          </button>
        </div>
        <QueryFeedback loading={snapshot.isLoading} error={snapshot.isError} onRetry={() => void snapshot.refetch()}/>
        {!snapshot.isError && data ? <>
          <div className={'dashboard-queue-health tone-' + state.tone}>
            <div className="dashboard-queue-health-top">
              <span className="dashboard-queue-health-title">
                {data.status === 'running' ? <CheckCircle2 size={21} aria-hidden="true"/> :
                  data.status === 'inactive' ? <XCircle size={21} aria-hidden="true"/> :
                  <AlertCircle size={21} aria-hidden="true"/>}
                {state.label}
              </span>
              <span className="dashboard-queue-status-tag">{data.connection}</span>
            </div>
            <p>{data.message || state.description}</p>
            <small>{isAvailable && data.wait_seconds !== null
              ? '最长预计排队：' + data.wait_seconds + ' 秒'
              : '当前等待时间：' + (data.status === 'inactive' ? '不可用' : '暂无估算')}</small>
          </div>
          <div className="dashboard-queue-metrics">
            <div><span>近1小时任务记录</span><strong>{showNumber(data.recent_jobs)}</strong><small>Horizon 保留的近期任务</small></div>
            <div><span>每分钟处理量</span><strong>{showNumber(data.jobs_per_minute)}</strong><small>Horizon 统计值</small></div>
            <div><span>待执行任务</span><strong>{showNumber(data.pending_jobs)}</strong><small>当前等待处理</small></div>
            <div><span>活跃进程</span><strong>{showNumber(data.processes)}</strong><small>队列 Worker 数量</small></div>
          </div>
        </> : null}
      </section>

      <section className="admin-dashboard-card dashboard-queue-card">
        <div className="admin-dashboard-card-head">
          <div className="dashboard-queue-heading">
            <h2><Cpu size={19} aria-hidden="true"/>作业详情</h2>
            <p>近期失败记录与运行指标</p>
          </div>
          <button type="button" className="dashboard-queue-icon-button" aria-label="刷新作业统计"
            disabled={snapshot.isFetching} onClick={() => { void snapshot.refetch(); if (showErrors) void failures.refetch() }}>
            <RefreshCw size={17} className={snapshot.isFetching ? 'loading-spinner' : ''}/>
          </button>
        </div>
        <QueryFeedback loading={snapshot.isLoading} error={snapshot.isError} onRetry={() => void snapshot.refetch()}/>
        <div className="dashboard-queue-metrics">
          <div>
            <span>近7天报错数量</span>
            <strong className={data?.failed_last_7_days ? 'dashboard-queue-error-count' : ''}>
              {showNumber(data?.failed_last_7_days)}
            </strong>
            <small>{data?.failed_jobs_available === false ? '失败任务数据库不可用' : '统计过去 7 天'}</small>
          </div>
          <div>
            <span>最长预计等待</span>
            <strong>{showNumber(data?.wait_seconds, ' 秒')}</strong>
            <small title={data?.longest_wait_queue || ''}>{data?.longest_wait_queue || '暂无队列估算'}</small>
          </div>
        </div>
        <div className="dashboard-queue-footer">
          <span>失败任务可查看异常原因与堆栈（敏感信息已遮盖）</span>
          <button type="button" className="dashboard-queue-view-errors"
            aria-expanded={showErrors} onClick={() => setShowErrors(v => !v)}>
            <Eye size={16}/> {showErrors ? '收起报错' : '查看报错'} {showErrors ? <ChevronUp size={15}/> : <ChevronDown size={15}/>}
          </button>
        </div>
        {showErrors ? <div className="dashboard-queue-failures">
          <QueryFeedback loading={failures.isLoading} error={failures.isError} onRetry={() => void failures.refetch()}/>
          {!failures.isError && failures.data?.length === 0 ? <p className="dashboard-queue-empty">暂无失败任务记录</p> : null}
          {failures.data?.map(failure => (
            <button type="button" className="dashboard-queue-failure-row" key={failure.id}
              onClick={() => setSelectedId(failure.id)}>
              <span className="dashboard-queue-failure-text">
                <strong>{failure.job}</strong>
                <small>{failure.message}</small>
                <small>{failure.queue} · {failure.failed_at}</small>
              </span>
              <Eye size={16} aria-hidden="true"/>
            </button>
          ))}
        </div> : null}
      </section>

      <Modal open={selectedId !== null} title="失败作业详情" subtitle={detail.data ? detail.data.queue + ' · ' + detail.data.failed_at : '加载异常详情'} wide onClose={() => setSelectedId(null)}>
        <QueryFeedback loading={detail.isLoading} error={detail.isError} onRetry={() => void detail.refetch()}/>
        {detail.data && !detail.isError ? <>
          <dl className="dashboard-queue-detail-meta">
            <div><dt>任务</dt><dd>{detail.data.job}</dd></div>
            <div><dt>队列</dt><dd>{detail.data.connection}:{detail.data.queue}</dd></div>
            <div><dt>失败时间</dt><dd>{detail.data.failed_at}</dd></div>
          </dl>
          <pre className="dashboard-queue-exception">{detail.data.exception}</pre>
        </> : null}
      </Modal>
    </div>
  )
}
