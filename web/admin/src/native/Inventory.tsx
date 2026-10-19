import { useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { RefreshCw, Search } from 'lucide-react'
import { ManagementFeedback } from './Feedback'
import { DataTable, type Column } from '../components/ui/DataTable'
import { PageHeader } from '../components/ui/PageHeader'

type Page<T> = { data: T[]; meta: { page: number; per_page: number; total: number; last_page: number } }

export function Inventory<T extends { id: string }>({ operation, title, columns, matches, load }: {
  operation: string; title: string; columns: Column<T>[]
  matches: (row: T, search: string) => boolean
  load: (page: number, signal: AbortSignal) => Promise<Page<T>>
}) {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const query = useQuery({
    queryKey: ['native-admin', operation, page],
    queryFn: ({ signal }) => load(page, signal),
  })
  // Never retain a successful page after a later permission or path failure.
  const data = query.isError ? undefined : query.data
  const rows = data?.data ?? []
  const filtered = rows.filter(row => matches(row, search.trim().toLowerCase()))
  const meta = data?.meta
  return <div className="native-management-page">
    <PageHeader title={title} description="查看当前资源清单。资源编辑将在后续开放。"
      action={<button className="button" disabled={query.isFetching} onClick={() => void query.refetch()}>
        <RefreshCw size={15} />刷新
      </button>} />
    <div className="list-card">
      <div className="native-list-toolbar">
        <label className="native-list-search"><Search size={16} />
          <input aria-label="搜索当前页" placeholder="搜索当前页" value={search}
            onChange={event => setSearch(event.target.value)} />
        </label>
        <span>{meta ? '共 ' + meta.total + ' 项 · 第 ' + meta.page + ' / ' + meta.last_page + ' 页' : '等待资源清单'}</span>
      </div>
      <ManagementFeedback error={query.isError ? query.error : undefined} loading={query.isFetching}
        onRetry={() => void query.refetch()} />
      {!query.isError && data ? <DataTable rows={filtered} columns={columns} label={title}
        rowKey={row => row.id} empty={search ? '当前页没有匹配项' : '暂无数据'} /> : null}
      <div className="native-pagination" aria-label="资源分页">
        <button className="button" disabled={query.isFetching || page <= 1}
          onClick={() => { setPage(value => value - 1); setSearch('') }}>上一页</button>
        <span aria-live="polite">第 {page} 页</span>
        <button className="button" disabled={query.isFetching || !meta || page >= meta.last_page}
          onClick={() => { setPage(value => value + 1); setSearch('') }}>下一页</button>
      </div>
    </div>
  </div>
}

export const dash = (value: ReactNode) => value == null || value === '' ? '—' : value
export function formatTimestamp(value: number | null): string {
  if (value == null) return '未上报'
  const date = new Date(value * 1000)
  return Number.isNaN(date.getTime()) ? '未知时间' : date.toLocaleString('zh-CN')
}
