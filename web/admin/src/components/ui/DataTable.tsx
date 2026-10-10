import type { ReactNode } from 'react'
import { QueryFeedback } from './QueryFeedback'

export type Column<T> = { key: string; header: string; render: (row: T) => ReactNode; width?: string }

export function DataTable<T>({ rows, columns, empty = '暂无数据', loading, error, onRetry, rowKey }: {
  rows: T[]; columns: Column<T>[]; empty?: string; loading?: boolean; error?: boolean
  onRetry?: () => void; rowKey?: (row: T) => string | number
}) {
  return <>
    <QueryFeedback loading={loading} error={error} onRetry={onRetry} />
    <div className="table-wrap" tabIndex={0} role="region" aria-label="数据列表" aria-busy={loading || false}>
      <table className="data-table"><thead><tr>{columns.map(c => <th scope="col" key={c.key} style={{width:c.width}}>{c.header}</th>)}</tr></thead>
        <tbody>{rows.length ? rows.map((row, i) => <tr key={rowKey ? rowKey(row) : i}>{columns.map(c => <td key={c.key}>{c.render(row)}</td>)}</tr>) : !loading && !error ? <tr><td className="empty-cell" colSpan={columns.length}>{empty}</td></tr> : null}</tbody>
      </table>
    </div>
  </>
}
