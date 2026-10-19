import type { ReactNode } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { DataTable, type Column } from '../components/ui/DataTable'
import { ManagementFeedback } from './Feedback'

export function ReadList<T extends { id: string }>({ title, rows, columns, meta, page, ready,
  loading, error, onRetry, onPage, children }: {
  title: string; rows: T[]; columns: Column<T>[]; page: number; ready: boolean
  meta?: { total: number; last_page: number }; loading: boolean; error?: unknown
  onRetry: () => void; onPage: (page: number) => void; children?: ReactNode
}) {
  const visibleMeta = ready && !error ? meta : undefined
  const lastPage = Math.min(visibleMeta?.last_page ?? page, 100000)
  const pages = [...new Set([1, page - 1, page, page + 1, lastPage])].filter(value => value >= 1 && value <= lastPage)
  return <section className="list-card native-read-list" aria-label={title} aria-busy={loading}>
    {children}
    <ManagementFeedback error={error} loading={loading} onRetry={onRetry} />
    {ready && !error ? <p className="native-scroll-hint">左右滑动表格查看完整信息</p> : null}
    {ready && !error ? <DataTable rows={rows} columns={columns} rowKey={row => row.id} label={title}
      empty="没有符合条件的数据" /> : null}
    <div className="native-pagination" aria-label={title + '分页'}>
      <span aria-live="polite">{visibleMeta ? '共 ' + visibleMeta.total + ' 项 · 第 ' + page + ' 页' : '第 ' + page + ' 页'}</span>
      <nav className="native-page-controls" aria-label="选择页码">
        <button type="button" className="button native-page-arrow" aria-label="上一页" disabled={loading || !ready || !!error || page <= 1} onClick={() => onPage(page - 1)}><ChevronLeft size={16} /></button>
        {pages.map((value, index) => <span className="native-page-item" key={value}>
          {index > 0 && value - pages[index - 1] > 1 ? <span className="native-page-ellipsis">…</span> : null}
          <button type="button" className={'button native-page-number' + (value === page ? ' is-current' : '')} aria-label={'第 ' + value + ' 页'} aria-current={value === page ? 'page' : undefined}
            disabled={loading || !visibleMeta || value === page} onClick={() => onPage(value)}>{value}</button>
        </span>)}
        <button type="button" className="button native-page-arrow" aria-label="下一页" disabled={loading || !visibleMeta || page >= lastPage} onClick={() => onPage(page + 1)}><ChevronRight size={16} /></button>
      </nav>
    </div>
  </section>
}

export function ReadFields({ fields }: { fields: ReadonlyArray<readonly [string, ReactNode]> }) {
  return <dl className="native-read-fields">{fields.map(([label, value]) =>
    <div key={label}><dt>{label}</dt><dd>{value ?? '未记录'}</dd></div>)}</dl>
}
