import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import {
  deletePluginCrudRecord,
  fetchPluginCrudList,
  resolvePluginCrudApiPath,
  savePluginCrudRecord,
  type PluginAdminCrudColumn,
  type PluginAdminCrudSchema,
  type PluginItem,
} from '../../api/plugin'
import { JsonEditor } from '../ui/JsonEditor'
import { Modal } from '../ui/Modal'
import {
  defaultPluginValues,
  normalizePluginFields,
  PluginFieldEditor,
} from './PluginFieldEditor'
import { requestConfirm } from '../ui/ConfirmDialog'

export function PluginCrudPanel({
  plugin,
  subpath,
  schema,
}: {
  plugin: PluginItem
  subpath: string
  schema: PluginAdminCrudSchema
}) {
  const idField = schema.id_field || 'id'
  const listPath = resolvePluginCrudApiPath(plugin.code, subpath, 'list', schema)
  const savePath = resolvePluginCrudApiPath(plugin.code, subpath, 'save', schema)
  const deletePath = resolvePluginCrudApiPath(plugin.code, subpath, 'delete', schema)

  const fields = useMemo(() => normalizePluginFields(schema.form), [schema.form])
  const typed = fields.some(item => !item.field.hidden)

  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [sortField, setSortField] = useState<string | undefined>()
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc' | undefined>()
  const [editorOpen, setEditorOpen] = useState(false)
  const [draft, setDraft] = useState<Record<string, unknown>>(() => defaultPluginValues(fields))

  const query = useQuery({
    queryKey: ['pluginCrud', plugin.code, subpath, page, pageSize, appliedSearch, sortField, sortOrder],
    queryFn: () => fetchPluginCrudList(listPath!, {
      current: page,
      pageSize,
      search: appliedSearch || undefined,
      sort_field: sortField,
      sort_order: sortOrder,
    }),
    enabled: Boolean(listPath),
  })

  const save = useMutation({
    mutationFn: () => savePluginCrudRecord(savePath!, draft),
    onSuccess: () => {
      toast.success('保存成功')
      setEditorOpen(false)
      query.refetch()
    },
  })

  const remove = useMutation({
    mutationFn: (payload: Record<string, unknown>) => deletePluginCrudRecord(deletePath!, payload),
    onSuccess: () => {
      toast.success('删除成功')
      query.refetch()
    },
  })

  const rows = query.data?.data || []
  const columns = schema.columns?.length
    ? schema.columns
    : fields.map(({ key, field }) => ({
        key,
        title: field.label || key,
        type: 'string',
      } as PluginAdminCrudColumn))

  const searchable = columns.some(column => column.searchable)

  function beginCreate() {
    setDraft(defaultPluginValues(fields))
    setEditorOpen(true)
  }

  function beginEdit(row: Record<string, unknown>) {
    setDraft({ ...defaultPluginValues(fields), ...row })
    setEditorOpen(true)
  }

  function validate() {
    const missing = fields.filter(({ key, field }) =>
      field.required && !field.hidden && (draft[key] == null || draft[key] === ''),
    )
    if (missing.length) {
      toast.error(`请填写：${missing.map(item => item.field.label || item.key).join('、')}`)
      return false
    }
    return true
  }

  function toggleSort(column: PluginAdminCrudColumn) {
    if (!column.sortable) return
    if (sortField !== column.key) {
      setSortField(column.key)
      setSortOrder('asc')
    } else if (sortOrder === 'asc') {
      setSortOrder('desc')
    } else if (sortOrder === 'desc') {
      setSortField(undefined)
      setSortOrder(undefined)
    } else {
      setSortOrder('asc')
    }
    setPage(1)
  }

  if (!listPath && !savePath) {
    return <div className="card">
      <div className="plugin-panel-head">
        <div><h3>{schema.title || subpath}</h3><p>{schema.description || '该 CRUD Schema 未声明 API。'}</p></div>
      </div>
      <JsonEditor value={schema as Record<string, unknown>} onChange={() => {}}/>
    </div>
  }

  if (!listPath && savePath) {
    return <div className="card">
      <div className="plugin-panel-head">
        <div><h3>{schema.title || subpath}</h3><p>{schema.description || '插件设置页面'}</p></div>
      </div>
      <PluginEditorBody typed={typed} fields={fields} draft={draft} idField={idField} onChange={setDraft}/>
      <div className="card-actions">
        <button className="button primary" disabled={save.isPending} onClick={() => validate() && save.mutate()}>
          {save.isPending ? '保存中…' : '保存'}
        </button>
      </div>
    </div>
  }

  return <>
    <div className="card plugin-crud-card">
      <div className="plugin-panel-head">
        <div><h3>{schema.title || subpath}</h3><p>{schema.description || '插件数据管理'}</p></div>
        {schema.actions?.create && savePath && <button className="button primary" onClick={beginCreate}><Plus size={15}/>新建</button>}
      </div>

      {searchable && <div className="plugin-crud-toolbar">
        <div className="order-search">
          <input
            value={search}
            onChange={event => setSearch(event.target.value)}
            onKeyDown={event => event.key === 'Enter' && (setAppliedSearch(search), setPage(1))}
            placeholder="搜索记录…"
          />
          <button className="button" onClick={() => { setAppliedSearch(search); setPage(1) }}><Search size={15}/>搜索</button>
        </div>
        {appliedSearch && <button className="button" onClick={() => { setSearch(''); setAppliedSearch(''); setPage(1) }}>清除</button>}
      </div>}

      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              {columns.map(column => <th
                key={column.key}
                style={column.width ? { width: column.width } : undefined}
                className={column.sortable ? 'sortable-head' : undefined}
                onClick={() => toggleSort(column)}
              >
                {column.title || column.key}
                {sortField === column.key && <span>{sortOrder === 'asc' ? ' ↑' : ' ↓'}</span>}
              </th>)}
              {(schema.actions?.edit || schema.actions?.delete) && <th>操作</th>}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => <tr key={String(row[idField] ?? index)}>
              {columns.map(column => <td key={column.key}>{renderCell(column, row[column.key])}</td>)}
              {(schema.actions?.edit || schema.actions?.delete) && <td>
                <div className="actions">
                  {schema.actions?.edit && savePath && <button className="icon-button" title="编辑" onClick={() => beginEdit(row)}><Pencil size={15}/></button>}
                  {schema.actions?.delete && deletePath && <button
                    className="icon-button danger"
                    title="删除"
                    onClick={() => {
                      const id = row[idField]
                      if (id == null) return
                      requestConfirm({ title: '删除记录', message: '确认删除这条记录？', danger: true, confirmLabel: '删除', action: () => remove.mutate({ [idField]: id }) })
                    }}
                  ><Trash2 size={15}/></button>}
                </div>
              </td>}
            </tr>)}
            {!rows.length && <tr><td colSpan={columns.length + 1} className="empty-cell">{query.isLoading ? '加载中…' : '暂无记录'}</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="pagination-bar">
        <span className="pagination-meta">共 {query.data?.total || 0} 条 · 第 {query.data?.current_page || page} / {query.data?.last_page || 1} 页</span>
        <div className="pagination-actions">
          <select value={pageSize} onChange={event => { setPageSize(Number(event.target.value)); setPage(1) }}>
            <option value={10}>10 / 页</option>
            <option value={20}>20 / 页</option>
            <option value={50}>50 / 页</option>
          </select>
          <button className="icon-button" disabled={page <= 1} onClick={() => setPage(value => Math.max(1, value - 1))}><ChevronLeft size={16}/></button>
          <button className="icon-button" disabled={page >= Number(query.data?.last_page || 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16}/></button>
        </div>
      </div>
    </div>

    <Modal open={editorOpen} title={draft[idField] == null || draft[idField] === '' ? '新建记录' : '编辑记录'} onClose={() => setEditorOpen(false)}>
      <PluginEditorBody typed={typed} fields={fields} draft={draft} idField={idField} onChange={setDraft}/>
      <div className="card-actions">
        <button className="button" onClick={() => setEditorOpen(false)}>取消</button>
        <button className="button primary" disabled={save.isPending} onClick={() => validate() && save.mutate()}>
          {save.isPending ? '保存中…' : '保存'}
        </button>
      </div>
    </Modal>
  </>
}

function PluginEditorBody({
  typed,
  fields,
  draft,
  idField,
  onChange,
}: {
  typed: boolean
  fields: ReturnType<typeof normalizePluginFields>
  draft: Record<string, unknown>
  idField: string
  onChange: (value: Record<string, unknown>) => void
}) {
  return typed
    ? <PluginFieldEditor
      fields={fields}
      values={draft}
      idField={idField}
      onChange={(key, value) => onChange({ ...draft, [key]: value })}
    />
    : <JsonEditor value={draft} onChange={onChange}/>
}

function renderCell(column: PluginAdminCrudColumn, value: unknown) {
  if (column.type === 'boolean') return <span className={value ? 'status ok' : 'status off'}>{value ? '是' : '否'}</span>
  if (column.type === 'datetime') return <span className="muted">{formatTime(value)}</span>
  if (column.type === 'tag') {
    const option = column.options?.find(item => String(item.value) === String(value))
    return <span className="badge">{option?.label ?? String(value ?? '-')}</span>
  }
  if (column.type === 'number') return <span className="tabular">{value == null ? '-' : String(value)}</span>
  if (value && typeof value === 'object') {
    try { return <code>{JSON.stringify(value)}</code> } catch { return '[object]' }
  }
  return value == null ? '-' : String(value)
}

function formatTime(value: unknown) {
  const n = Number(value)
  if (Number.isFinite(n) && n > 0) return new Date(n < 10_000_000_000 ? n * 1000 : n).toLocaleString()
  if (typeof value === 'string' && value) {
    const parsed = Date.parse(value)
    if (Number.isFinite(parsed)) return new Date(parsed).toLocaleString()
  }
  return '-'
}
