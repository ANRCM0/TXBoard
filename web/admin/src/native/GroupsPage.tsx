import { Modal } from '../components/ui/Modal'
import { Search, RefreshCw, Plus, Pencil, Trash2, Layers } from 'lucide-react'
import './network-reference.css'
import { useEffect, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'

type Group = CoreOutput<'network.groups.list'>[number]
export function GroupsPage() {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [page, setPage] = useState(1), [id, setId] = useState(''), [name, setName] = useState('')
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState(false), [error, setError] = useState('')
  const list = useQuery({ queryKey: ['native-admin', 'network-groups', page],
    queryFn: ({ signal }) => admin.read('network.groups.list', { page, per_page: 20 }, signal) })
  const denied = [list.error, state.error].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  const pending = state.pending?.operation.startsWith('network.groups.') ? state.pending : undefined
  const locked = denied || state.busy || !!state.pending || list.isFetching || list.isError || !list.data
  useEffect(() => { if (denied) { setId(''); setName(''); setEditing(false) } }, [denied])
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
    } catch (failure) { if (generation === workflowActions.generation()) setError(managementError(failure)) }
  }
  async function save() {
    if (locked || !name.trim()) return
    await perform(() => workflowActions.run('network.groups.save', { ...(id ? { id } : {}), name: name.trim() }))
  }
  async function remove(row: Group) {
    if (locked || !window.confirm('确定删除分组 ' + row.name + '？有关联资源时服务端会拒绝删除。')) return
    await perform(() => workflowActions.run('network.groups.delete', { group_id: row.id }))
  }
  async function recover() {
    if (!pending || state.busy || denied || list.isFetching || list.isError) return
    await perform(() => workflowActions.run(pending.operation, pending.input))
  }
  const columns: Column<Group>[] = [
    { key: 'name', header: '分组', render: row => <div className="native-row-identity"><strong>{row.name}</strong><small>#{row.id}</small></div> },
    { key: 'users', header: '用户', render: row => <span className="network-count">{row.users_count}</span> },
    { key: 'plans', header: '套餐', render: row => <span className="network-count">{row.plans_count}</span> },
    { key: 'servers', header: '节点', render: row => <span className="network-count">{row.server_count}</span> },
    { key: 'actions', header: '操作', render: row => <><button className="button" disabled={locked} onClick={() => { setId(row.id); setName(row.name); setEditing(true) }} title="编辑" aria-label="编辑"><Pencil size={15} aria-hidden="true" /><span className="network-action-label">编辑</span></button><button className="button" disabled={locked} onClick={() => void remove(row)} title="删除" aria-label="删除" data-danger="true"><Trash2 size={15} aria-hidden="true" /><span className="network-action-label">删除</span></button></> },
  ]
  return <div className="native-management-page network-reference-page">
    <PageHeader title="分组管理" description="管理用户、套餐和节点使用的分组。" action={<><button className="button" disabled={state.busy || list.isFetching} onClick={() => void refresh()}><RefreshCw size={15} aria-hidden="true" />刷新分组</button><button className="button" data-primary="true" disabled={locked} onClick={() => { setId(''); setName(''); setEditing(true) }}><Plus size={15} aria-hidden="true" />新增分组</button></>} />
    {pending ? <section className="list-card" role="status"><h2>分组操作等待确认</h2><p>结果确认前保留原操作，不会自动重新提交。</p><button className="button" disabled={state.busy || denied || list.isFetching || list.isError} onClick={() => void recover()}>确认原分组操作</button></section> : null}
    {!editing && (error || state.error) ? <p role="alert">{error || managementError(state.error)}</p> : null}
    {denied ? <p role="alert">无法确认分组访问权限，已隐藏数据。</p> : null}
    <ReadList title="分组列表" rows={denied || list.isError ? [] : (list.data?.data ?? []).filter(row => [row.name, row.id].some(value => value.toLowerCase().includes(search.trim().toLowerCase())))} columns={columns} page={page} meta={list.data?.meta} ready={!!list.data && !denied} loading={list.isFetching} error={list.error} onRetry={() => void refresh()} onPage={next => { setPage(next); setSearch('') }}><div className="network-filterbar"><label className="native-list-search"><Search size={16} /><input aria-label="搜索当前页分组" placeholder="搜索当前页分组名称或 ID" value={search} onChange={event => setSearch(event.target.value)} /></label><span className="network-toolbar-hint">分组关联用户、套餐和节点</span></div></ReadList>
    <Modal open={editing && !denied} title={id ? '编辑分组' : '新增分组'} subtitle="保存会更新当前配置；多人协作时请先刷新并核对。" className="network-reference-page network-editor-modal" onClose={() => { if (!state.busy) setEditing(false) }}><div className="network-editor-body">{error || state.error ? <p role="alert">{error || managementError(state.error)}</p> : null}<form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}><label>分组名称<input data-autofocus placeholder="例如：香港优选节点" required maxLength={255} value={name} disabled={locked} onChange={event => setName(event.target.value)} /></label><div className="network-form-actions"><button className="button" disabled={locked || !name.trim()}><Layers size={15} aria-hidden="true" />保存分组</button><button className="button" type="button" onClick={() => setEditing(false)}>取消</button></div></form></div></Modal>
  </div>
}
