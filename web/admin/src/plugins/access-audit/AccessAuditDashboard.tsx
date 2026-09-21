import { useMutation, useQuery } from '@tanstack/react-query'
import { Ban, Eraser, RefreshCcw, ShieldCheck, ShieldOff } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import type { PluginRendererProps } from '../registry'
import {
  auditUserAction,
  clearAuditLogs,
  getAuditLogs,
  getAuditNodes,
  getAuditStats,
} from './api'

export function AccessAuditDashboard({ plugin }: PluginRendererProps) {
  const [page, setPage] = useState(1)
  const [keyword, setKeyword] = useState('')
  const [matched, setMatched] = useState('')
  const [email, setEmail] = useState('')
  const [reason, setReason] = useState('')

  const stats = useQuery({ queryKey: ['accessAudit', 'stats'], queryFn: getAuditStats, refetchInterval: 30_000 })
  const nodes = useQuery({ queryKey: ['accessAudit', 'nodes'], queryFn: getAuditNodes, refetchInterval: 30_000 })
  const logs = useQuery({
    queryKey: ['accessAudit', 'logs', page, keyword, matched],
    queryFn: () => getAuditLogs({ page, keyword: keyword || undefined, matched: matched || undefined }),
  })

  const clear = useMutation({
    mutationFn: clearAuditLogs,
    onSuccess: result => {
      toast.success(result.message || `已清空 ${result.deleted} 条访问日志`)
      setPage(1)
      logs.refetch()
      stats.refetch()
    },
  })

  const userAction = useMutation({
    mutationFn: (action: 'ban' | 'unban') => auditUserAction(action, email.trim(), reason.trim()),
    onSuccess: result => {
      toast.success(result.message || '操作成功')
      setReason('')
      stats.refetch()
    },
  })

  const s = stats.data
  const pluginBase = `/plugins/${plugin.code}`

  return <div className="plugin-native-page">
    <div className="plugin-native-actions">
      <Link className="button" to={`${pluginBase}/rules`}>规则管理</Link>
      <Link className="button" to={`${pluginBase}/reports`}>命中记录</Link>
      <Link className="button" to={`${pluginBase}/ban-logs`}>封禁记录</Link>
      <Link className="button" to={`${pluginBase}/settings`}>插件设置</Link>
      <button className="button" onClick={() => { stats.refetch(); nodes.refetch(); logs.refetch() }}>
        <RefreshCcw size={15}/>刷新
      </button>
    </div>

    <div className="plugin-metrics">
      <Metric label="今日访问" value={s?.logs_today} hint={s ? `昨日 ${s.logs_yesterday}` : undefined}/>
      <Metric label="今日命中" value={s?.reports_today} hint={s ? `累计 ${s.reports_total}` : undefined}/>
      <Metric label="今日封禁" value={s?.bans_today} hint={s ? `累计 ${s.bans_total}` : undefined}/>
      <Metric label="在线审计节点" value={s ? `${s.nodes_online}/${s.nodes_total}` : undefined} hint={s ? `启用规则 ${s.rules_enabled}/${s.rules_total}` : undefined}/>
    </div>

    <div className="two-col">
      <section className="card">
        <div className="plugin-panel-head"><div><h3>节点健康</h3><p>只统计曾经上报过审计数据的节点。</p></div></div>
        <div className="table-wrap">
          <table className="data-table">
            <thead><tr><th>节点</th><th>静默</th><th>事件</th><th>命中</th><th>封禁</th></tr></thead>
            <tbody>
              {(nodes.data || []).map(node => <tr key={node.node_id}>
                <td>{node.node_name}</td>
                <td>{node.silent_minutes == null ? '-' : `${node.silent_minutes} 分钟`}</td>
                <td>{node.total_events}</td><td>{node.total_matched}</td><td>{node.total_banned}</td>
              </tr>)}
              {!nodes.data?.length && <tr><td colSpan={5} className="empty-cell">{nodes.isLoading ? '加载中…' : '暂无节点上报'}</td></tr>}
            </tbody>
          </table>
        </div>
      </section>

      <section className="card">
        <div className="plugin-panel-head"><div><h3>手动封禁</h3><p>直接调用 AccessAudit 的封禁/解封能力。</p></div></div>
        <div className="plugin-field-list">
          <label className="field"><span>用户邮箱</span><input value={email} onChange={e => setEmail(e.target.value)} placeholder="user@example.com"/></label>
          <label className="field"><span>原因</span><input value={reason} onChange={e => setReason(e.target.value)} placeholder="可选"/></label>
        </div>
        <div className="card-actions">
          <button className="button danger" disabled={!email.trim() || userAction.isPending} onClick={() => userAction.mutate('ban')}><Ban size={15}/>封禁</button>
          <button className="button" disabled={!email.trim() || userAction.isPending} onClick={() => userAction.mutate('unban')}><ShieldCheck size={15}/>解封</button>
        </div>
      </section>
    </div>

    <section className="card">
      <div className="plugin-panel-head">
        <div><h3>访问日志</h3><p>全量日志仅在节点启用 report_all 时产生；每页由插件后端固定限制。</p></div>
        <button className="button danger" disabled={clear.isPending} onClick={() => {
          if (confirm('确认清空全部访问日志？规则、命中记录与分析聚合不会被删除。')) clear.mutate()
        }}><Eraser size={15}/>{clear.isPending ? '清空中…' : '清空日志'}</button>
      </div>

      <div className="plugin-native-filter">
        <input value={keyword} onChange={e => { setKeyword(e.target.value); setPage(1) }} placeholder="筛选目标域名/IP…"/>
        <select value={matched} onChange={e => { setMatched(e.target.value); setPage(1) }}>
          <option value="">全部</option><option value="1">已命中</option><option value="0">未命中</option>
        </select>
      </div>

      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>节点</th><th>用户</th><th>目标</th><th>目标 IP</th><th>源 IP</th><th>命中</th><th>时间</th></tr></thead>
          <tbody>
            {(logs.data?.list || []).map(row => <tr key={row.id}>
              <td>{row.node_name}</td><td>{row.user_email}</td><td><code>{row.target || '-'}</code></td>
              <td>{row.target_ip || '-'}</td><td>{row.source_ip || '-'}</td>
              <td>{row.matched ? <span className="status warn"><ShieldOff size={13}/>是</span> : <span className="status ok">否</span>}</td>
              <td>{formatTime(row.created_at)}</td>
            </tr>)}
            {!logs.data?.list.length && <tr><td colSpan={7} className="empty-cell">{logs.isLoading ? '加载中…' : '暂无访问日志'}</td></tr>}
          </tbody>
        </table>
      </div>
      <div className="pagination-bar">
        <span className="pagination-meta">共 {logs.data?.total || 0} 条 · 第 {logs.data?.page || page} / {logs.data?.pages || 1} 页</span>
        <div className="pagination-actions">
          <button className="button" disabled={page <= 1} onClick={() => setPage(v => Math.max(1, v - 1))}>上一页</button>
          <button className="button" disabled={page >= (logs.data?.pages || 1)} onClick={() => setPage(v => v + 1)}>下一页</button>
        </div>
      </div>
    </section>
  </div>
}

function Metric({ label, value, hint }: { label: string; value?: string | number; hint?: string }) {
  return <div className="card plugin-metric"><span>{label}</span><strong>{value ?? '—'}</strong>{hint && <small>{hint}</small>}</div>
}

function formatTime(value: number) {
  if (!value) return '-'
  return new Date(value < 10_000_000_000 ? value * 1000 : value).toLocaleString()
}
