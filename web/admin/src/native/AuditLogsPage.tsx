import { RefreshCw, Search, RotateCcw } from 'lucide-react'
import './observation-reference.css'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { ReadList } from './ReadList'
import { ManagementFeedback } from './Feedback'
import { validId, unixDate } from './readPresentation'
import { PageHeader } from '../components/ui/PageHeader'
import { ReadOnlyNotice } from '../components/ui/ReadOnlyNotice'
import type { Column } from '../components/ui/DataTable'

type AuditLog = CoreOutput<'audit.logs.list'>[number]
const blankFilters = () => ({ action: '', admin_id: '', keyword: '' })
export function AuditLogsPage() {
  const session = useAdminSession()
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState(blankFilters), [filters, setFilters] = useState(blankFilters)
  const [error, setError] = useState('')
  const input: CoreInput<'audit.logs.list'> = { page, per_page: 20,
    ...(filters.action ? { action: filters.action } : {}), ...(filters.admin_id ? { admin_id: filters.admin_id } : {}),
    ...(filters.keyword ? { keyword: filters.keyword } : {}) }
  const query = useQuery({ queryKey: ['native-admin', 'audit-logs', input], enabled: session,
    queryFn: ({ signal }) => admin.read('audit.logs.list', input, signal) })
  const ready = session && !!query.data && !query.isError
  const columns: Column<AuditLog>[] = [
    { key: 'id', header: '记录', render: row => <span className="observation-mono">#{row.id}</span> },
    { key: 'admin', header: '管理员', render: row => <div className="native-row-identity"><strong>{row.admin?.email ?? '管理员 #' + row.admin_id}</strong><small>#{row.admin_id}</small></div> },
    { key: 'action', header: '操作', render: row => <span className="observation-tag">{row.action}</span> },
    { key: 'request', header: '请求', render: row => <div className="native-row-identity"><strong><span className="observation-tag">{row.method}</span></strong><details className="observation-request"><summary className="observation-mono">{row.uri}</summary><p className="observation-mono">{row.method} {row.uri}</p><p>来源 IP：{row.ip} · {unixDate(row.created_at)}</p></details></div> },
    { key: 'ip', header: '来源 IP', render: row => row.ip },
    { key: 'time', header: '时间', render: row => <span className="observation-date">{unixDate(row.created_at)}</span> },
  ]
  return <div className="native-management-page observation-page observation-audit-page">
    <PageHeader title="管理审计" description="追踪管理员的操作与访问记录" action={<button className="button" disabled={!session || query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />刷新</button>} />
    <ReadOnlyNotice title="审计记录只读">记录用于事后核对 · 不提供修改或删除入口 · 请求内容与来源信息来自 Core 审计存储</ReadOnlyNotice>
    {error ? <p role="alert">{error}</p> : null}
    <section className="list-card"><div className="observation-card-heading"><div><h2>筛选记录</h2><p>按操作、管理员或请求内容定位</p></div></div>
      <form className="native-filters observation-audit-filters" onSubmit={event => { event.preventDefault(); if (draft.admin_id && !validId(draft.admin_id)) { setError('管理员 ID 必须是有效的十进制字符串。'); return } setError(''); setPage(1); setFilters({ ...draft }) }}>
        <label>操作<input placeholder="操作名称" maxLength={80} value={draft.action} onChange={event => setDraft({ ...draft, action: event.target.value })} /></label>
        <label>管理员 ID<input placeholder="输入管理员 ID" inputMode="numeric" value={draft.admin_id} onChange={event => setDraft({ ...draft, admin_id: event.target.value })} /></label>
        <label>关键字<input placeholder="搜索请求内容" maxLength={120} value={draft.keyword} onChange={event => setDraft({ ...draft, keyword: event.target.value })} /></label>
        <button className="button" disabled={!session}><Search size={15} />查询</button><button type="button" className="button observation-reset" onClick={() => { setDraft(blankFilters()); setFilters(blankFilters()); setPage(1); setError('') }}><RotateCcw size={15} />重置</button>
      </form>
      {filters.action || filters.admin_id || filters.keyword ? <div className="observation-filter-summary" aria-label="已应用筛选">{filters.action ? <span>操作：{filters.action}</span> : null}{filters.admin_id ? <span>管理员：#{filters.admin_id}</span> : null}{filters.keyword ? <span>关键字：{filters.keyword}</span> : null}</div> : null}
      <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void query.refetch()} />
    </section>
    {ready ? <ReadList title="管理审计记录" rows={query.data!.data} columns={columns} page={page}
      meta={query.data!.meta} ready={ready} loading={query.isFetching}
      onRetry={() => void query.refetch()} onPage={setPage}><div className="observation-card-heading"><div><h2>审计记录</h2><p>每页 20 条{filters.action || filters.admin_id || filters.keyword ? ' · 已应用筛选条件' : ' · 全部记录'}</p></div><span className="observation-tag">共 {query.data!.meta.total} 条</span></div></ReadList> : null}
  </div>
}
