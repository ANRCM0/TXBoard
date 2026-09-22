import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, RefreshCcw, Search } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import {
  getTrafficResetLogs,
  getTrafficResetStats,
  resetUserTraffic,
} from '../../api/traffic-reset'
import { PageHeader } from '../../components/ui/PageHeader'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { getUsers, type AdminUser } from '../../api/user-admin'

export function TrafficResetPage() {
  const qc = useQueryClient()
  const [tab, setTab] = useState<'logs' | 'stats'>('logs')
  const [page, setPage] = useState(1)
  const [email, setEmail] = useState('')
  const [appliedEmail, setAppliedEmail] = useState('')
  const [source, setSource] = useState('')
  const [days, setDays] = useState(30)
  const [resetLookup, setResetLookup] = useState('')
  const [appliedResetLookup, setAppliedResetLookup] = useState('')
  const [selectedResetUser, setSelectedResetUser] = useState<AdminUser | null>(null)
  const [resetReason, setResetReason] = useState('')

  const logsQuery = useQuery({
    queryKey: ['trafficResetLogs', page, appliedEmail, source],
    queryFn: () => getTrafficResetLogs({
      page,
      per_page: 20,
      ...(appliedEmail.trim() ? { user_email: appliedEmail.trim() } : {}),
      ...(source ? { trigger_source: source } : {}),
    }),
    enabled: tab === 'logs',
  })

  const statsQuery = useQuery({
    queryKey: ['trafficResetStats', days],
    queryFn: () => getTrafficResetStats(days),
    enabled: tab === 'stats',
  })

  const resetUserQuery = useQuery({
    queryKey: ['trafficResetUserLookup', appliedResetLookup],
    queryFn: () => {
      const keyword = appliedResetLookup.trim()
      const numeric = /^\d+$/.test(keyword)
      return getUsers({
        current: 1,
        pageSize: 8,
        filter: numeric
          ? [{ id: 'id', value: `eq:${keyword}` }]
          : [{ id: 'email', value: keyword }],
        sort: [{ id: 'id', desc: true }],
      })
    },
    enabled: Boolean(appliedResetLookup.trim()),
  })

  const resetMutation = useMutation({
    mutationFn: () => resetUserTraffic(selectedResetUser!.id, resetReason),
    onSuccess: () => {
      toast.success('用户流量已重置')
      setResetReason('')
      setSelectedResetUser(null)
      qc.invalidateQueries({ queryKey: ['trafficResetLogs'] })
      qc.invalidateQueries({ queryKey: ['trafficResetStats'] })
      qc.invalidateQueries({ queryKey: ['adminUsers'] })
      qc.invalidateQueries({ queryKey: ['trafficResetUserLookup'] })
    },
  })

  const logs = logsQuery.data?.data || []
  const stats = statsQuery.data || {}
  const resetCandidates = resetUserQuery.data?.data || []

  function applyResetLookup() {
    const keyword = resetLookup.trim()
    setSelectedResetUser(null)
    setAppliedResetLookup(keyword)
  }

  return <>
    <PageHeader title="流量重置" description="查看重置日志、来源统计，并支持管理员手动重置用户流量。"/>

    <section className="card manual-reset-card">
      <div className="manual-reset-head">
        <div><strong>手动重置用户流量</strong><p>后端会检查该用户当前是否允许执行重置。</p></div>
        <RefreshCcw size={20}/>
      </div>
      <div className="manual-reset-form">
        <div className="manual-reset-user-picker">
          <span className="manual-reset-label">用户</span>
          <div className="order-search">
            <input
              value={resetLookup}
              onChange={e => setResetLookup(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && applyResetLookup()}
              placeholder="输入邮箱或 UID…"
            />
            <button className="button" type="button" onClick={applyResetLookup}><Search size={15}/>查找</button>
          </div>
          {appliedResetLookup ? (
            <div className="manual-reset-user-results">
              <QueryFeedback loading={resetUserQuery.isFetching} error={resetUserQuery.isError} onRetry={() => resetUserQuery.refetch()} />
              {!resetUserQuery.isFetching && !resetUserQuery.isError && resetCandidates.map(user => (
                <button
                  type="button"
                  key={user.id}
                  className={selectedResetUser?.id === user.id ? 'manual-reset-user-option selected' : 'manual-reset-user-option'}
                  onClick={() => setSelectedResetUser(user)}
                >
                  <strong>{user.email}</strong>
                  <small>
                    UID #{user.id} · {user.plan?.name || '无套餐'} · 已用 {trafficUsed(user)} / {trafficTotal(user)}
                  </small>
                </button>
              ))}
              {!resetUserQuery.isFetching && !resetUserQuery.isError && !resetCandidates.length ? (
                <span className="muted">未找到匹配用户。</span>
              ) : null}
            </div>
          ) : null}
        </div>
        <label className="field"><span>原因</span><input value={resetReason} onChange={e => setResetReason(e.target.value)} placeholder="管理员手动重置"/></label>
        <button
          className="button primary"
          disabled={resetMutation.isPending || !selectedResetUser}
          onClick={() => {
            if (!selectedResetUser) return
            requestConfirm({
              title: '重置流量',
              message: `确认重置 ${selectedResetUser.email}（UID #${selectedResetUser.id}）的已用流量？当前已用 ${trafficUsed(selectedResetUser)}，执行后不可恢复。`,
              danger: true,
              confirmLabel: '执行重置',
              action: () => resetMutation.mutate(),
            })
          }}
        >{resetMutation.isPending ? '重置中…' : selectedResetUser ? `重置 ${selectedResetUser.email}` : '请先选择用户'}</button>
      </div>
    </section>

    <div className="tabs traffic-reset-tabs">
      <button className={tab === 'logs' ? 'tab active' : 'tab'} onClick={() => setTab('logs')}>重置日志</button>
      <button className={tab === 'stats' ? 'tab active' : 'tab'} onClick={() => setTab('stats')}>统计</button>
    </div>

    {tab === 'logs' ? <>
      <div className="traffic-reset-toolbar">
        <div className="order-search">
          <input value={email} onChange={e => setEmail(e.target.value)} onKeyDown={e => e.key === 'Enter' && (setAppliedEmail(email), setPage(1))} placeholder="搜索用户邮箱…"/>
          <button className="button" onClick={() => { setAppliedEmail(email); setPage(1) }}><Search size={15}/>搜索</button>
        </div>
        <select value={source} onChange={e => { setSource(e.target.value); setPage(1) }}>
          <option value="">全部来源</option>
          <option value="manual">手动</option>
          <option value="auto">自动</option>
          <option value="cron">定时任务</option>
          <option value="order">订单</option>
          <option value="gift_card">礼品卡</option>
        </select>
        {(appliedEmail || source) && <button className="button" onClick={() => { setEmail(''); setAppliedEmail(''); setSource(''); setPage(1) }}>清除</button>}
      </div>

      <div className="card traffic-reset-table-card">
        <QueryFeedback loading={logsQuery.isFetching} error={logsQuery.isError} onRetry={() => logsQuery.refetch()} />
        <div className="table-wrap"><table className="data-table">
          <thead><tr><th>ID</th><th>用户</th><th>重置类型</th><th>来源</th><th>清除流量</th><th>重置后</th><th>时间</th><th>原因</th></tr></thead>
          <tbody>
            {logs.map(row => <tr key={row.id}>
              <td>{row.id}</td>
              <td><strong>{row.user_email || ('User #' + (row.user_id || '-'))}</strong></td>
              <td><span className="badge">{row.reset_type_name || row.reset_type || '-'}</span></td>
              <td>{row.trigger_source_name || row.trigger_source || '-'}</td>
              <td>{row.old_traffic?.formatted || '-'}</td>
              <td>{row.new_traffic?.formatted || '0 B'}</td>
              <td>{formatTime(row.reset_time)}</td>
              <td>{String(row.metadata?.reason || '-')}</td>
            </tr>)}
            {!logs.length && !logsQuery.isError && <tr><td colSpan={8} className="empty-cell">{logsQuery.isLoading ? '加载中…' : '暂无重置记录'}</td></tr>}
          </tbody>
        </table></div>
        <div className="pagination-bar">
          <span className="pagination-meta">共 {logsQuery.data?.total || 0} 条 · 第 {logsQuery.data?.current_page || page} / {logsQuery.data?.last_page || 1} 页</span>
          <div className="pagination-actions">
            <button className="icon-button" disabled={page <= 1} onClick={() => setPage(v => Math.max(1, v - 1))}><ChevronLeft size={16}/></button>
            <button className="icon-button" disabled={page >= Number(logsQuery.data?.last_page || 1)} onClick={() => setPage(v => v + 1)}><ChevronRight size={16}/></button>
          </div>
        </div>
      </div>
    </> : <>
      <div className="traffic-stats-range">
        <span>统计范围</span>
        <select value={days} onChange={e => setDays(Number(e.target.value))}>
          <option value={7}>最近 7 天</option>
          <option value={30}>最近 30 天</option>
          <option value={90}>最近 90 天</option>
          <option value={365}>最近 365 天</option>
        </select>
      </div>
      <QueryFeedback loading={statsQuery.isFetching} error={statsQuery.isError} onRetry={() => statsQuery.refetch()} />
      <div className="stats-cards traffic-stats-cards">
        <Stat label="总重置次数" value={stats.total_resets}/>
        <Stat label="管理员手动" value={stats.manual_resets}/>
        <Stat label="定时任务" value={stats.cron_resets}/>
        <Stat label="自动重置" value={stats.auto_resets}/>
        <Stat label="订单触发" value={stats.order_resets}/>
        <Stat label="礼品卡触发" value={stats.gift_card_resets}/>
      </div>
    </>}
  </>
}

function trafficUsed(user: AdminUser) {
  return formatBytes(Number(user.total_used ?? ((user.u || 0) + (user.d || 0))))
}

function trafficTotal(user: AdminUser) {
  return formatBytes(Number(user.transfer_enable || 0))
}

function formatBytes(value: number) {
  if (!Number.isFinite(value) || value <= 0) return '0 B'
  const gb = value / 1073741824
  return gb >= 1024 ? `${(gb / 1024).toFixed(2)} TB` : `${gb.toFixed(gb >= 10 ? 1 : 2)} GB`
}

function Stat({ label, value }: { label: string; value?: number }) {
  return <div className="stat-card"><span>{label}</span><strong>{value === undefined ? '—' : Number(value).toLocaleString()}</strong></div>
}

function formatTime(value: unknown) {
  if (!value) return '-'
  if (typeof value === 'number') return new Date(value < 10_000_000_000 ? value * 1000 : value).toLocaleString()
  const parsed = Date.parse(String(value))
  return Number.isFinite(parsed) ? new Date(parsed).toLocaleString() : String(value)
}
