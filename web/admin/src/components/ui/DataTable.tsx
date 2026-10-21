import { t, useLocale } from '../../lib/i18n'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Inbox, Info } from 'lucide-react'
import { QueryFeedback } from './QueryFeedback'
import { Popover } from './Popover'
import './table-details.css'

export type Column<T> = { key: string; header: string; render: (row: T) => ReactNode; width?: string; description?: string; hideable?: boolean; pin?: 'left' | 'right' }

export type TableSelection<T> = {
  selected: ReadonlySet<string>
  onChange: (next: Set<string>) => void
  getKey: (row: T) => string
  getLabel?: (row: T) => string
  disabled?: boolean
}

export function DataTable<T>({ rows, columns, empty = t("暂无数据"), emptyState, loading, error, onRetry, rowKey, label = t("数据列表"), selection, className = '', stickyHeader = false, stickyFirstColumn = false, stickyLastColumn = false, mobilePrimaryKey }: {
  rows: T[]; columns: Column<T>[]; empty?: string; emptyState?: ReactNode; loading?: boolean; error?: boolean
  onRetry?: () => void; rowKey?: (row: T) => string | number; label?: string
  selection?: TableSelection<T>; className?: string; stickyHeader?: boolean; stickyFirstColumn?: boolean; stickyLastColumn?: boolean
  mobilePrimaryKey?: string
}) {
  useLocale()
  const [compact, setCompact] = useState(() => window.matchMedia?.('(max-width: 600px)').matches ?? false)
  useEffect(() => {
    const media = window.matchMedia?.('(max-width: 600px)')
    const update = () => setCompact(media?.matches ?? false)
    update(); media?.addEventListener('change', update)
    return () => media?.removeEventListener('change', update)
  }, [])
  const mobile = !!mobilePrimaryKey && compact
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
  }, [someSelected, mobile])

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
  const pinLeft = stickyFirstColumn || columns.some(column => column.pin === 'left')
  const pinRight = stickyLastColumn || columns.some(column => column.pin === 'right')
  function cellClass(index: number) {
    return [columns[index].pin === 'left' || stickyFirstColumn && index === 0 ? 'ui-sticky-cell' : '', columns[index].pin === 'right' || stickyLastColumn && index === columns.length - 1 ? 'ui-sticky-last' : ''].filter(Boolean).join(' ') || undefined
  }
  const primary = columns.find(column => column.key === mobilePrimaryKey) ?? columns[0]
  const actions = columns.filter(column => column.pin === 'right')
  const fields = columns.filter(column => column !== primary && !actions.includes(column))
  return <>
    <QueryFeedback loading={loading} error={error} onRetry={onRetry} />
    {showCustomEmpty ? <div className="table-empty-region">{emptyState}</div> : null}
    {showTable && mobile ? <div className="ui-mobile-records" role="region" aria-label={t(label)} aria-busy={loading || false}>
      {selection ? <label className="ui-mobile-select-all"><input ref={headerCheckbox} type="checkbox" aria-label={t("选择当前页全部行")} checked={allSelected} disabled={selectionDisabled || !hasRows} onChange={toggleAll} />{t("选择当前页全部行")}</label> : null}
      {hasRows ? rows.map((row, index) => <article className="ui-mobile-record" key={rowKey ? rowKey(row) : index} data-selected={selection?.selected.has(selection.getKey(row)) || undefined}>
        <header>{selection ? <input type="checkbox" checked={selection.selected.has(selection.getKey(row))} disabled={selectionDisabled} onChange={() => toggleRow(row)} aria-label={t("选择 {label}", { label: selection.getLabel?.(row) ?? selection.getKey(row) })} /> : null}<div>{primary?.render(row)}</div></header>
        <dl>{fields.map(column => <div key={column.key}><dt>{t(column.header)}</dt><dd>{column.render(row)}</dd></div>)}</dl>
        {actions.length ? <footer>{actions.map(column => <div key={column.key}>{column.render(row)}</div>)}</footer> : null}
      </article>) : <div className="table-empty-content"><Inbox size={32} aria-hidden="true" /><span>{t(empty)}</span></div>}
    </div> : null}
    {showTable && !mobile ? <div className={'table-wrap' + (stickyHeader ? ' is-sticky' : '')} tabIndex={0} role="region" aria-label={t(label)} aria-busy={loading || false}>
      <table className={'data-table ui-data-table' + (pinLeft ? ' has-sticky-first' : '') + (pinRight ? ' has-sticky-last' : '') + (selection ? ' has-selection' : '') + (className ? ' ' + className : '')}><thead><tr>
        {selection ? <th scope="col" className="ui-selection-cell">
          <input ref={headerCheckbox} type="checkbox" aria-label={t("选择当前页全部行")}
            checked={allSelected} disabled={selectionDisabled || !hasRows} onChange={toggleAll} />
        </th> : null}
        {columns.map((c, index) => <th scope="col" key={c.key} className={cellClass(index)} style={{ width: c.width }}><span className="ui-column-heading">{t(c.header)}{c.description ? <Popover label={t("{label}说明", { label: t(c.header) })} trigger={<Info size={13} aria-hidden="true" />}><p>{t(c.description)}</p></Popover> : null}</span></th>)}
      </tr></thead>
        <tbody>{hasRows ? rows.map((row, i) => {
          const key = rowKey ? rowKey(row) : i
          const selected = selection ? selection.selected.has(selection.getKey(row)) : false
          return <tr key={key} className={selected ? 'is-selected' : undefined}>
            {selection ? <td className="ui-selection-cell">
              <input type="checkbox" checked={selected} disabled={selectionDisabled} onChange={() => toggleRow(row)}
                aria-label={t("选择 {label}", { label: selection.getLabel?.(row) ?? selection.getKey(row) })} />
            </td> : null}
            {columns.map((c, index) => <td key={c.key} className={cellClass(index)} style={{ width: c.width }}>{c.render(row)}</td>)}
          </tr>
        }) :
          <tr><td className="empty-cell" colSpan={columns.length + (selection ? 1 : 0)}><div className="table-empty-content"><Inbox size={32} strokeWidth={1.4} aria-hidden="true" /><span>{t(empty)}</span></div></td></tr>}</tbody>
      </table>
    </div> : null}
  </>
}
