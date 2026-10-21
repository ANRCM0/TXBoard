import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { CopyButton } from '../components/ui/CopyButton'
import { useListPagination } from './useListPagination'
import { Modal } from '../components/ui/Modal'
import { Search, RefreshCw, Plus, Pencil, Trash2, Layers } from 'lucide-react'
import './network-reference.css'
import { useEffect, useId, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'
import { FilterBar } from '../components/ui/FilterBar'
import { FacetedFilter } from '../components/ui/FacetedFilter'

type Group = CoreOutput<'network.groups.list'>[number]
export function GroupsPage() {
  useLocale()
  const formId = useId()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const generation = useSyncExternalStore(workflowActions.subscribe, workflowActions.generation)
  const [draftGeneration, setDraftGeneration] = useState(generation)
  const { page, setPage, perPage, setPerPage } = useListPagination()
  const [id, setId] = useState(''), [name, setName] = useState('')
  const [search, setSearch] = useState(''), [associations, setAssociations] = useState<string[]>([]), [originalName, setOriginalName] = useState('')
  const [editing, setEditing] = useState(false), [error, setError] = useState('')
  const list = useQuery({ queryKey: ['native-admin', 'network-groups', page, perPage],
    queryFn: ({ signal }) => admin.read('network.groups.list', { page, per_page: perPage }, signal) })
  const operationError = !state.errorOperation || state.errorOperation.startsWith('network.groups.') ? state.error : undefined
  const denied = [list.error, operationError].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  const pending = state.pending?.operation.startsWith('network.groups.') ? state.pending : undefined
  const locked = denied || state.busy || !!state.pending || list.isFetching || list.isError || !list.data
  useDirtyDraft(editing && !denied && !state.pending && draftGeneration === generation && name !== originalName)
  useEffect(() => { setEditing(false); setId(''); setName(''); setOriginalName('') }, [generation])
  useEffect(() => { if (denied) { setId(''); setName(''); setOriginalName(''); setEditing(false); setSearch(''); setAssociations([]) } }, [denied])
  function closeEditor() {
    if (state.busy || (!state.pending && name !== originalName && !window.confirm(t("分组名称尚未保存，确定放弃修改？")))) return
    setEditing(false); setId(''); setName(''); setOriginalName(''); setError('')
  }
  function edit(row?: Group) { setDraftGeneration(generation); setId(row?.id ?? ''); setName(row?.name ?? ''); setOriginalName(row?.name ?? ''); setEditing(true); setError('') }
  async function refresh() {
    const generation = workflowActions.generation()
    try {
      await list.refetch({ throwOnError: true })
      if (generation !== workflowActions.generation()) return
      workflowActions.confirmReadAuthorization('network.groups.'); workflowActions.clearError(); setError('')
    } catch { /* Keep permission failures visible until a fresh read succeeds. */ }
  }
  async function perform(command: () => Promise<void>) {
    const generation = workflowActions.generation(); setError('')
    try {
      await command()
      if (generation !== workflowActions.generation()) return
      setEditing(false); setId(''); setName(''); await refresh()
    } catch (failure) { if (generation === workflowActions.generation()) setError(managementError(failure, false)) }
  }
  async function save() {
    if (locked || !name.trim()) return
    await perform(() => workflowActions.run('network.groups.save', { ...(id ? { id } : {}), name: name.trim() }))
  }
  async function remove(row: Group) {
    if (locked || !window.confirm(t("确定删除分组 {name}？有关联资源时服务端会拒绝删除。", { name: row.name }))) return
    await perform(() => workflowActions.run('network.groups.delete', { group_id: row.id }))
  }
  async function recover() {
    if (!pending || state.busy || denied || list.isFetching || list.isError) return
    await perform(() => workflowActions.run(pending.operation, pending.input))
  }
  const columns: Column<Group>[] = [
    { key: 'name', header: t("分组"), hideable: false, render: row => <div className="native-row-identity"><strong>{row.name}</strong><small className="network-copy-value">#{row.id}<CopyButton value={row.id} label={t("复制分组 ID")} /></small></div> },
    { key: 'users', header: t("用户"), render: row => <span className="network-count" title={t("使用此权限分组的用户数")}>{row.users_count}</span> },
    { key: 'plans', header: t("套餐"), render: row => <span className="network-count" title={t("关联此权限分组的套餐数")}>{row.plans_count}</span> },
    { key: 'servers', header: t("节点"), render: row => <span className="network-count" title={t("关联此权限分组的节点数")}>{row.server_count}</span> },
    { key: 'actions', header: t("操作"), pin: 'right', hideable: false, render: row => <div className="network-group-actions"><button className="button" disabled={locked} onClick={() => edit(row)} title={t("编辑")} aria-label={t("编辑")}><Pencil size={15} aria-hidden="true" /><span className="network-action-label">{t("编辑")}</span></button><button className="button" disabled={locked} onClick={() => void remove(row)} title={t("删除")} aria-label={t("删除")} data-danger="true"><Trash2 size={15} aria-hidden="true" /><span className="network-action-label">{t("删除")}</span></button></div> },
  ]
  const allRows = denied || list.isError ? [] : list.data?.data ?? []
  const associationOptions = [
    { value: 'users', label: t("关联用户"), test: (row: Group) => row.users_count > 0 },
    { value: 'plans', label: t("关联套餐"), test: (row: Group) => row.plans_count > 0 },
    { value: 'nodes', label: t("关联节点"), test: (row: Group) => row.server_count > 0 },
    { value: 'unassigned', label: t("没有资源关联"), test: (row: Group) => row.users_count + row.plans_count + row.server_count === 0 },
  ]
  const rows = allRows.filter(row => [row.name, row.id].some(value => value.toLowerCase().includes(search.trim().toLowerCase()))
    && (!associations.length || associationOptions.some(option => associations.includes(option.value) && option.test(row))))
  const applied = [...(search.trim() ? [{ key: 'search', label: t("搜索 {value}", { value: search.trim() }) }] : []),
    ...associationOptions.filter(option => associations.includes(option.value)).map(option => ({ key: option.value, label: option.label }))]
  return <div className="native-management-page network-reference-page network-groups-page">
    <PageHeader title={t("分组管理")} description={t("管理用户、套餐和节点使用的分组。")} action={<><button className="button" disabled={state.busy || list.isFetching} onClick={() => void refresh()}><RefreshCw size={15} aria-hidden="true" />{t("刷新分组")}</button><button className="button" data-primary="true" disabled={locked} onClick={() => edit()}><Plus size={15} aria-hidden="true" />{t("新增分组")}</button></>} />
    {pending ? <section className="list-card network-recovery-card" role="status"><h2>{t("分组操作等待确认")}</h2><p>{t("结果确认前保留原操作，不会自动重新提交。")}</p><button className="button" disabled={state.busy || denied || list.isFetching || list.isError} onClick={() => void recover()}>{t("确认原分组操作")}</button></section> : null}
    {!editing && (error || operationError) ? <p role="alert">{error ? t(error) : managementError(operationError)}</p> : null}
    {denied ? <p role="alert">{t("无法确认分组访问权限，已隐藏数据。")}</p> : null}
    <ReadList<Group> title={"分组列表"} columnSettingsKey="network-groups" stickyHeader stickyFirstColumn mobilePrimaryKey="name" rows={rows} columns={columns} perPage={perPage} onPageSize={size => { setPerPage(size); setAssociations([]) }} page={page} meta={list.data?.meta} ready={!!list.data && !denied} loading={list.isFetching} error={list.error} onRetry={() => void refresh()} onPage={next => { setPage(next); setSearch(''); setAssociations([]) }}>
      <FilterBar applied={applied} onClear={() => { setSearch(''); setAssociations([]) }} onRemove={key => key === 'search' ? setSearch('') : setAssociations(current => current.filter(value => value !== key))} hint={t("搜索与资源关联均筛选当前页；资源计数来自原生所有者，分页总数保持全站口径。")}>
        <label className="native-list-search"><Search size={16} aria-hidden="true" /><input aria-label={t("搜索当前页分组")} placeholder={t("搜索当前页分组名称或 ID")} value={search} onChange={event => setSearch(event.target.value)} /></label>
        <FacetedFilter label={t("资源关联")} value={associations} onChange={setAssociations} disabled={denied || list.isError} options={associationOptions.map(option => ({ ...option, count: allRows.filter(option.test).length }))} />
      </FilterBar>
    </ReadList>
    <Modal open={editing && !denied} title={id ? t("编辑分组") : t("新增分组")} subtitle={t("名称最长 255 个字符，原有资源关联继续保留。")} className="network-reference-page network-editor-modal admin-form-dialog" onClose={closeEditor}
      footer={<><button className="button" type="button" disabled={state.busy} onClick={closeEditor}>{t("取消")}</button><button className="button primary" type="submit" form={formId} disabled={locked || !name.trim()}><Layers size={15} aria-hidden="true" />{t("保存分组")}</button></>}>
      <div className="network-editor-body"><p className="network-editor-note">{t("权限分组用于关联用户、套餐与可访问的节点。删除前请先解除资源关联。")}</p>{error || operationError ? <p role="alert">{error ? t(error) : managementError(operationError)}</p> : null}
      {pending ? <div role="status"><p>{t("保存结果等待确认；将复用原名称和原请求编号。")}</p><button type="button" className="button" disabled={state.busy || denied || list.isFetching || list.isError} onClick={() => void recover()}>{t("确认原分组操作")}</button></div> : null}
      {list.isError ? <p role="alert">{t("分组列表读取失败，请关闭编辑后重新加载。")}</p> : null}
      <form id={formId} className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}><label>{t("分组名称")}<input data-autofocus placeholder={t("例如：香港优选节点")} required maxLength={255} value={name} disabled={locked} onChange={event => setName(event.target.value)} /></label></form></div></Modal>
  </div>
}
