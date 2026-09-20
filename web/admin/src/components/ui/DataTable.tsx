import type { ReactNode } from 'react'

export type Column<T> = { key: string; header: string; render: (row: T) => ReactNode; width?: string }

export function DataTable<T>({ rows, columns, empty = '暂无数据' }: { rows: T[]; columns: Column<T>[]; empty?: string }) {
  return <div className="table-wrap"><table className="data-table"><thead><tr>{columns.map(c => <th key={c.key} style={{width:c.width}}>{c.header}</th>)}</tr></thead>
    <tbody>{rows.length ? rows.map((row, i) => <tr key={i}>{columns.map(c => <td key={c.key}>{c.render(row)}</td>)}</tr>) : <tr><td className="empty-cell" colSpan={columns.length}>{empty}</td></tr>}</tbody>
  </table></div>
}
