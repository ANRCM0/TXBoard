import { t, useLocale } from '../lib/i18n'
import type { ReactNode } from 'react'
import { TablePagination } from '../components/ui/TablePagination'
import { DataTable, type Column, type TableSelection } from '../components/ui/DataTable'
import { ColumnSettings, useColumnVisibility } from '../components/ui/ColumnSettings'
import { TableSkeleton } from '../components/ui/Skeleton'
import { ManagementFeedback } from './Feedback'

export function ReadList<T extends { id: string }>({ title, rows, columns, meta, page, ready,
  loading, error, onRetry, onPage, children, emptyState, selection, columnSettingsKey = title, stickyHeader = true, stickyFirstColumn = true, perPage, onPageSize, maxPageSize, mobilePrimaryKey }: {
  title: string; rows: T[]; columns: Column<T>[]; page: number; ready: boolean
  meta?: { total: number; last_page: number }; loading: boolean; error?: unknown
  onRetry: () => void; onPage: (page: number) => void; children?: ReactNode
  selection?: TableSelection<T>; columnSettingsKey?: string; stickyHeader?: boolean; stickyFirstColumn?: boolean
  perPage?: number; onPageSize?: (size: number) => void; maxPageSize?: number
  mobilePrimaryKey?: string
  emptyState?: ReactNode
}) {
  useLocale()
  const { hidden, onChange } = useColumnVisibility(columnSettingsKey, columns)
  const visibleColumns = columns.filter(column => !hidden.has(column.key))
  const visibleMeta = ready && !error ? meta : undefined
  return <section className="list-card native-read-list" aria-label={t(title)} aria-busy={loading}>
    {children}
    {columnSettingsKey ? <div className="ui-table-settings">
      <ColumnSettings columns={columns} hidden={hidden} onChange={onChange} />
    </div> : null}
    {!ready && loading
      ? <TableSkeleton rows={5} columns={visibleColumns.length + (selection ? 1 : 0)} label={t('正在加载{title}', { title: t(title) })} />
      : <ManagementFeedback error={error} loading={loading} onRetry={onRetry} />}
    {ready && !error && rows.length > 0 ? <p className="native-scroll-hint">{t("左右滑动表格查看完整信息")}</p> : null}
    {ready && !error ? <DataTable rows={rows} columns={visibleColumns} rowKey={row => row.id} label={title}
      selection={selection} stickyHeader={stickyHeader} stickyFirstColumn={stickyFirstColumn} mobilePrimaryKey={mobilePrimaryKey}
      stickyLastColumn={visibleColumns.length > 1 && ['actions', 'action', 'details'].includes(visibleColumns.at(-1)?.key ?? '')} empty={t("没有符合条件的数据")} emptyState={emptyState} /> : null}
    <TablePagination label={t(title)} page={page} meta={visibleMeta} loading={loading || !ready || !!error} onPage={onPage}
      perPage={perPage} onPageSize={onPageSize} maxPageSize={maxPageSize} selectedCount={selection && visibleMeta ? rows.filter(row => selection.selected.has(selection.getKey(row))).length : undefined} />
  </section>
}

export function ReadFields({ fields }: { fields: ReadonlyArray<readonly [string, ReactNode]> }) {
  useLocale()
  return <dl className="native-read-fields">{fields.map(([label, value]) =>
    <div key={label}><dt>{t(label)}</dt><dd>{value ?? t("未记录")}</dd></div>)}</dl>
}
