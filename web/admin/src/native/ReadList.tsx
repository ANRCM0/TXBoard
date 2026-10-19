import type { ReactNode } from 'react'
import { DataTable, type Column } from '../components/ui/DataTable'
import { ManagementFeedback } from './Feedback'

export function ReadList<T extends { id: string }>({ title, rows, columns, meta, page, ready,
  loading, error, onRetry, onPage, children }: {
  title: string; rows: T[]; columns: Column<T>[]; page: number; ready: boolean
  meta?: { total: number; last_page: number }; loading: boolean; error?: unknown
  onRetry: () => void; onPage: (page: number) => void; children: ReactNode
}) {
  return <section className="list-card native-read-list" aria-label={title}>
    {children}
    <ManagementFeedback error={error} loading={loading} onRetry={onRetry} />
    {ready && !error ? <p className="native-scroll-hint">左右滑动表格查看完整信息</p> : null}
    {ready && !error ? <DataTable rows={rows} columns={columns} rowKey={row => row.id} label={title}
      empty="没有符合条件的数据" /> : null}
    <div className="native-pagination" aria-label={title + '分页'}>
      <span aria-live="polite">{meta ? '共 ' + meta.total + ' 项 · 第 ' + page + ' 页' : '第 ' + page + ' 页'}</span>
      <button className="button" disabled={loading || page <= 1} onClick={() => onPage(page - 1)}>上一页</button>
      <button className="button" disabled={loading || !meta || page >= Math.min(meta.last_page, 100000)}
        onClick={() => onPage(page + 1)}>下一页</button>
    </div>
  </section>
}

export function ReadFields({ fields }: { fields: ReadonlyArray<readonly [string, ReactNode]> }) {
  return <dl className="native-read-fields">{fields.map(([label, value]) =>
    <div key={label}><dt>{label}</dt><dd>{value ?? '未记录'}</dd></div>)}</dl>
}
