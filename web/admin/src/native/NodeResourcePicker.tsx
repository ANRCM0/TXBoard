import { t, useLocale } from '../lib/i18n'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { admin } from './session'
import { Field, Input } from '../components/ui/Field'
import { ManagementFeedback } from './Feedback'
import { GroupSelector } from './GroupSelector'

type Operation = 'network.groups.list' | 'network.routes.list' | 'network.machines.list' | 'network.nodes.list'
type PickerProps = {
  label: string; operation: Operation; value: string[]; onChange: (ids: string[]) => void; multiple?: boolean
  disabled?: boolean; nodeId?: string; protocol?: string
}
export function NodeResourcePicker(props: PickerProps) {
  useLocale()
  return props.operation === 'network.groups.list' ? <GroupSelector label={props.label} value={props.value} onChange={props.onChange}
    multiple={props.multiple} disabled={props.disabled} /> : <PagedResourcePicker {...props} />
}
function PagedResourcePicker({ label, operation, value, onChange, multiple = false, disabled, nodeId, protocol }: PickerProps) {
  useLocale()
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [search, setSearch] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'node-editor-picker', operation, page], enabled: open,
    queryFn: ({ signal }) => admin.read(operation, { page, per_page: 100 }, signal) })
  const rows = query.isError ? [] : (query.data?.data ?? []).map(row => ({ ...row, label: 'name' in row ? row.name : row.remarks || row.action + ' #' + row.id })).filter(row => row.id !== nodeId
    && (!protocol || !('type' in row) || row.type === protocol && !row.parent_id)
    && [row.id, row.label].some(text => text.toLowerCase().includes(search.trim().toLowerCase())))
  return <div className="node-resource-picker">
    <div className="node-resource-picker-summary"><span>{label}</span><small>{value.length ? value.map(id => '#' + id).join('、') : t("未选择")}</small>
      <button type="button" className="button" disabled={disabled} aria-expanded={open} onClick={() => setOpen(current => !current)}>{open ? t("收起{label}", { label }) : t("选择{label}", { label })}</button></div>
    {open ? <div className="node-resource-picker-panel">
      <Field label={t("搜索本页{label}", { label })}><Input value={search} disabled={disabled} onChange={event => setSearch(event.target.value)} placeholder={t("名称或 ID")} /></Field>
      <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void query.refetch()} />
      {!query.isError ? <div className="node-resource-picker-options">
        {!multiple ? <label><input type="radio" checked={!value.length} disabled={disabled || query.isFetching} onChange={() => onChange([])} />{t("不选择 / 解除关联")}</label> : null}
        {rows.map(row => <label key={row.id}><input type={multiple ? 'checkbox' : 'radio'} name={'node-picker-' + operation} checked={value.includes(row.id)} disabled={disabled || query.isFetching}
          onChange={event => onChange(multiple ? event.target.checked ? [...value, row.id] : value.filter(id => id !== row.id) : [row.id])} />{row.label}<small>#{row.id}</small></label>)}
        {query.data && !rows.length ? <p>{t("本页没有符合条件的资源。")}</p> : null}
      </div> : null}
      <div className="node-resource-picker-pagination"><button type="button" className="button" disabled={disabled || query.isFetching || page <= 1} onClick={() => setPage(current => current - 1)}>{t("上一页{label}", { label })}</button>
        <small>{t("第{page} / {total}页", { page, total: query.isError ? '?' : query.data?.meta.last_page ?? '?' })}</small>
        <button type="button" className="button" disabled={disabled || query.isFetching || query.isError || !query.data || page >= query.data.meta.last_page} onClick={() => setPage(current => current + 1)}>{t("下一页{label}", { label })}</button></div>
    </div> : null}
  </div>
}
