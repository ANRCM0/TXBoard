import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { ReadList } from './ReadList'
import { ManagementFeedback } from './Feedback'
import { validId, unixDate } from './readPresentation'
import { PageHeader } from '../components/ui/PageHeader'
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
    { key: 'id', header: '记录', render: row => '#' + row.id },
    { key: 'admin', header: '管理员', render: row => <div className="native-row-identity"><strong>{row.admin?.email ?? '管理员 #' + row.admin_id}</strong><small>#{row.admin_id}</small></div> },
    { key: 'action', header: '操作', render: row => row.action },
    { key: 'request', header: '请求', render: row => row.method + ' ' + row.uri },
    { key: 'ip', header: '来源 IP', render: row => row.ip },
    { key: 'time', header: '时间', render: row => unixDate(row.created_at) },
  ]
  return <div className="native-management-page">
    <PageHeader title="管理审计" description="按操作、管理员和关键字查询服务端审计记录。" action={<button className="button" disabled={!session || query.isFetching} onClick={() => void query.refetch()}>刷新审计记录</button>} />
    {error ? <p role="alert">{error}</p> : null}
    <section className="list-card">
      <form className="native-filters" onSubmit={event => { event.preventDefault(); if (draft.admin_id && !validId(draft.admin_id)) { setError('管理员 ID 必须是有效的十进制字符串。'); return } setError(''); setPage(1); setFilters({ ...draft }) }}>
        <label>操作<input maxLength={80} value={draft.action} onChange={event => setDraft({ ...draft, action: event.target.value })} /></label>
        <label>管理员 ID<input inputMode="numeric" value={draft.admin_id} onChange={event => setDraft({ ...draft, admin_id: event.target.value })} /></label>
        <label>关键字<input maxLength={120} value={draft.keyword} onChange={event => setDraft({ ...draft, keyword: event.target.value })} /></label>
        <button className="button" disabled={!session}>筛选审计记录</button>
      </form>
      <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void query.refetch()} />
    </section>
    {ready ? <ReadList title="管理审计记录" rows={query.data!.data} columns={columns} page={page}
      meta={query.data!.meta} ready={ready} loading={query.isFetching}
      onRetry={() => void query.refetch()} onPage={setPage} /> : null}
  </div>
}
