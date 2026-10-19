import { useEffect, useRef, type ReactNode } from 'react'
import { Inbox } from 'lucide-react'
import { QueryFeedback } from './QueryFeedback'

export type Column<T> = { key: string; header: string; render: (row: T) => ReactNode; width?: string }

export type TableSelection<T> = {
  selected: ReadonlySet<string>
  onChange: (next: Set<string>) => void
  getKey: (row: T) => string
  getLabel?: (row: T) => string
  disabled?: boolean
}

export function DataTable<T>({ rows, columns, empty = '暂无数据', emptyState, loading, error, onRetry, rowKey, label = '数据列表', selection, className = '' }: {
  rows: T[]; columns: Column<T>[]; empty?: string; emptyState?: ReactNode; loading?: boolean; error?: boolean
  onRetry?: () => void; rowKey?: (row: T) => string | number; label?: string
  selection?: TableSelection<T>; className?: string
}) {
  const hasRows = rows.length > 0
  const showCustomEmpty = !hasRows && !loading && !error && emptyState != null
  const showTable = hasRows || (!loading && !error && !showCustomEmpty)
  const headerCheckbox = useRef<HTMLInputElement>(null)
  const selectableRows = selection ? rows : []
  const selectedCount = selection ? selectableRows.filter(row => selection.selected.has(selection.getKey(row))).length : 0
  const allSelected = selectedCount > 0 && selectedCount === selectableRows.length
  const someSelected = selectedCount > 0 && !allSelected

  useEffect(() => {
    if (headerCheckbox.current) headerCheckbox.current.indeterminate = someSelected
  }, [someSelected])

  function toggleAll() {
    if (!selection) return
    const next = new Set(selection.selected)
    if (allSelected) selectableRows.forEach(row => next.delete(selection.getKey(row)))
    else selectableRows.forEach(row => next.add(selection.getKey(row)))
    selection.onChange(next)
  }

  function toggleRow(row: T) {
    if (!selection) return
    const key = selection.getKey(row)
    const next = new Set(selection.selected)
    if (next.has(key)) next.delete(key)
    else next.add(key)
    selection.onChange(next)
  }

  const selectionDisabled = selection?.disabled || loading || error
  return <>
    <QueryFeedback loading={loading} error={error} onRetry={onRetry} />
    {showCustomEmpty ? <div className="table-empty-region">{emptyState}</div> : null}
    {showTable ? <div className="table-wrap" tabIndex={0} role="region" aria-label={label} aria-busy={loading || false}>
      <table className={'data-table ui-data-table ' + className}><thead><tr>
        {selection ? <th scope="col" className="ui-selection-cell">
          <input ref={headerCheckbox} type="checkbox" aria-label="选择当前页全部行"
            checked={allSelected} disabled={selectionDisabled || !hasRows} onChange={toggleAll} />
        </th> : null}
        {columns.map(c => <th scope="col" key={c.key} style={{ width: c.width }}>{c.header}</th>)}
      </tr></thead>
        <tbody>{hasRows ? rows.map((row, i) => {
          const key = rowKey ? rowKey(row) : i
          const selected = selection ? selection.selected.has(selection.getKey(row)) : false
          return <tr key={key} className={selected ? 'is-selected' : undefined}>
            {selection ? <td className="ui-selection-cell">
              <input type="checkbox" checked={selected} disabled={selection.disabled} onChange={() => toggleRow(row)}
                aria-label={'选择 ' + (selection.getLabel?.(row) ?? selection.getKey(row))} />
            </td> : null}
            {columns.map(c => <td key={c.key}>{c.render(row)}</td>)}
          </tr>
        }) :
          <tr><td className="empty-cell" colSpan={columns.length + (selection ? 1 : 0)}><div className="table-empty-content"><Inbox size={32} strokeWidth={1.4} aria-hidden="true" /><span>{empty}</span></div></td></tr>}</tbody>
      </table>
    </div> : null}
  </>
}
