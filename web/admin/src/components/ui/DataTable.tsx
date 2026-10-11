import type { ReactNode } from 'react'
import { QueryFeedback } from './QueryFeedback'

export type Column<T> = { key: string; header: string; render: (row: T) => ReactNode; width?: string }

export function DataTable<T>({ rows, columns, empty = '暂无数据', emptyState, loading, error, onRetry, rowKey, label = '数据列表' }: {
  rows: T[]; columns: Column<T>[]; empty?: string; emptyState?: ReactNode; loading?: boolean; error?: boolean
  onRetry?: () => void; rowKey?: (row: T) => string | number; label?: string
}) {
  const hasRows = rows.length > 0
  const showCustomEmpty = !hasRows && !loading && !error && emptyState != null
  const showTable = hasRows || (!loading && !error && !showCustomEmpty)
  return <>
    <QueryFeedback loading={loading} error={error} onRetry={onRetry} />
    {showCustomEmpty ? <div className="table-empty-region">{emptyState}</div> : null}
    {showTable ? <div className="table-wrap" tabIndex={0} role="region" aria-label={label} aria-busy={loading || false}>
      <table className="data-table"><thead><tr>{columns.map(c => <th scope="col" key={c.key} style={{width:c.width}}>{c.header}</th>)}</tr></thead>
        <tbody>{hasRows ? rows.map((row, i) => <tr key={rowKey ? rowKey(row) : i}>{columns.map(c => <td key={c.key}>{c.render(row)}</td>)}</tr>) :
          <tr><td className="empty-cell" colSpan={columns.length}>{empty}</td></tr>}</tbody>
      </table>
    </div> : null}
  </>
}
