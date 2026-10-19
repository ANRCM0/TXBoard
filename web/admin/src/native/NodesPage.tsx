import { Search, RefreshCw, ArrowUpRight, EyeOff, Link2, Power, PowerOff, RefreshCcw, Trash2 } from 'lucide-react'
import './network-reference.css'
import type { CoreOutput } from '@txboard/core-sdk'
import { Link } from 'react-router-dom'
import { useState, type FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { dash } from './Inventory'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import type { Column } from '../components/ui/DataTable'
import { StatusBadge } from '../components/ui/StatusBadge'
import { BulkActionBar } from '../components/ui/BulkActionBar'
import { FilterBar } from '../components/ui/FilterBar'
import { Modal } from '../components/ui/Modal'
import { Field, Input, Select } from '../components/ui/Field'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { admin, workflowActions } from './session'
import { validId } from './readPresentation'

type Node = CoreOutput<'network.nodes.list'>[number]
const columns: Column<Node>[] = [
  { key: 'name', header: '节点', render: node => <div className="nodes-v3-identity">
    <Link to={'/server/node/' + node.id}><strong>{node.name}</strong></Link><small>#{node.id} · {node.type}</small>
  </div> },
  { key: 'host', header: '连接地址', render: node =>
    <code>{node.host.includes(':') ? '[' + node.host + ']' : node.host}:{node.port}</code> },
  { key: 'machine', header: '所属服务器', render: node => node.machine_id ? <Link className="network-resource-link" to={'/server/machine/' + node.machine_id}>#{node.machine_id}</Link> : <span className="network-muted">未绑定</span> },
  { key: 'state', header: '状态', render: node => <div className="network-tags"><StatusBadge tone={node.enabled ? 'success' : 'neutral'}>{node.enabled ? '已启用' : '已禁用'}</StatusBadge><span className="reference-tag">{node.show ? '显示' : '隐藏'}</span></div> },
  { key: 'traffic', header: '上传 / 下载字节', render: node => <div className="network-traffic-pair"><span><small>上传</small><code>{dash(node.u)}</code></span><span><small>下载</small><code>{dash(node.d)}</code></span></div> },
  { key: 'details', header: '操作', render: node => <Link className="network-icon-link" title="查看详情" aria-label={'查看 ' + node.name + ' 详情'} to={'/server/node/' + node.id}><ArrowUpRight size={16} /></Link> },
]

export function NodesPage() {
  const [page, setPage] = useState(1), [search, setSearch] = useState(''), [status, setStatus] = useState('')
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [bindingOpen, setBindingOpen] = useState(false), [machineId, setMachineId] = useState(''), [bindingError, setBindingError] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'network.nodes.list', page],
    queryFn: ({ signal }) => admin.read('network.nodes.list', { page, per_page: 20 }, signal) })
  const management = useNetworkManagement('network.nodes.', [query.error], () => query.refetch({ throwOnError: true }))
  const rows = management.denied || query.isError ? [] : (query.data?.data ?? []).filter(node =>
    (!status || String(node.enabled) === status)
    && [node.id, node.name, node.type, node.host, node.machine_id].some(value => String(value ?? '').toLowerCase().includes(search.trim().toLowerCase())))
  const selectedRows = rows.filter(node => selected.has(node.id))
  const locked = management.denied || query.isFetching || query.isError || !query.data
    || management.state.busy || !!management.state.pending
  const applied = [
    ...(search.trim() ? [{ key: 'search', label: '搜索 ' + search.trim() }] : []),
    ...(status ? [{ key: 'status', label: status === 'true' ? '已启用' : '已停用' }] : []),
  ]

  function changeSearch(value: string) { setSearch(value); setSelected(new Set()) }
  function changeStatus(value: string) { setStatus(value); setSelected(new Set()) }
  function clearFilters() { setSearch(''); setStatus(''); setSelected(new Set()) }
  function selectedIds() { return selectedRows.map(node => node.id) }

  async function batchUpdate(patch: { enabled?: boolean; show?: boolean; machine_id?: string | null }) {
    const ids = selectedIds()
    if (!ids.length || locked) return
    await management.perform(() => workflowActions.run('network.nodes.batch-update', { ids, ...patch }), () => setSelected(new Set()))
  }
  async function batchDelete() {
    const ids = selectedIds()
    if (!ids.length || locked) return
    await management.perform(() => workflowActions.run('network.nodes.batch-delete', { ids }), () => setSelected(new Set()))
  }
  async function batchReset() {
    const ids = selectedIds()
    if (!ids.length || locked) return
    await management.perform(() => workflowActions.run('network.nodes.batch-traffic-reset', { ids }), () => setSelected(new Set()))
  }
  function confirmBatchDelete() {
    const ids = selectedIds()
    if (!ids.length || locked) return
    requestConfirm({
      title: '批量删除节点',
      message: `将删除当前页选中的 ${ids.length} 个节点，删除后不可恢复`,
      confirmLabel: '删除节点',
      danger: true,
      action: () => { void management.perform(() => workflowActions.run('network.nodes.batch-delete', { ids }), () => setSelected(new Set())) },
    })
  }
  function confirmBatchReset() {
    const ids = selectedIds()
    if (!ids.length || locked) return
    requestConfirm({
      title: '批量重置节点计数',
      message: `将重置当前页选中的 ${ids.length} 个节点的上传与下载计数，用户订阅额度不受影响`,
      confirmLabel: '重置计数',
      action: () => { void management.perform(() => workflowActions.run('network.nodes.batch-traffic-reset', { ids }), () => setSelected(new Set())) },
    })
  }
  async function bindMachine(event: FormEvent) {
    event.preventDefault()
    const ids = selectedIds()
    if (!ids.length || locked) return
    const value = machineId.trim()
    if (value && !validId(value)) { setBindingError('请输入有效的服务器 ID'); return }
    setBindingError('')
    await management.perform(async () => {
      await workflowActions.run('network.nodes.batch-update', { ids, machine_id: value || null })
    }, () => { setSelected(new Set()); setBindingOpen(false); setMachineId('') })
  }

  return <div className="native-management-page network-reference-page network-nodes-page">
    <PageHeader title="节点管理" description="查看节点，并对当前页选中项执行批量状态、归属和计数操作" action={<button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void management.refresh()}><RefreshCw size={15} aria-hidden="true" />刷新节点</button>} />
    <NetworkRecovery management={management} blocked={query.isFetching || query.isError || !query.data || management.state.busy} />
    <ReadList title="节点列表" rows={rows} columns={columns} page={page} meta={query.data?.meta} ready={!!query.data && !management.denied}
      loading={query.isFetching} error={query.error} onRetry={() => void management.refresh()}
      onPage={next => { setPage(next); setSearch(''); setSelected(new Set()) }}
      selection={{ selected, onChange: setSelected, getKey: node => node.id, getLabel: node => node.name, disabled: locked }}>
      <FilterBar applied={applied} onClear={clearFilters} hint="搜索与状态仅筛选当前页 · 批量操作只作用于当前页选中节点">
        <label className="native-list-search"><Search size={16} aria-hidden="true" /><span className="network-sr-only">搜索当前页</span>
          <Input placeholder="搜索当前页名称、ID 或地址" aria-label="搜索当前页" value={search} onChange={event => changeSearch(event.target.value)} /></label>
        <label>节点状态<Select aria-label="节点状态" value={status} onChange={event => changeStatus(event.target.value)}>
          <option value="">全部状态</option><option value="true">已启用</option><option value="false">已停用</option>
        </Select></label>
      </FilterBar>
      <BulkActionBar count={selectedRows.length} onClear={() => setSelected(new Set())}>
        <button type="button" className="button" disabled={locked} onClick={() => void batchUpdate({ enabled: true })}><Power size={14} aria-hidden="true" />批量启用</button>
        <button type="button" className="button" disabled={locked} onClick={() => void batchUpdate({ enabled: false })}><PowerOff size={14} aria-hidden="true" />批量禁用</button>
        <button type="button" className="button" disabled={locked} onClick={() => void batchUpdate({ show: false })}><EyeOff size={14} aria-hidden="true" />批量隐藏</button>
        <button type="button" className="button" disabled={locked} onClick={() => { setBindingError(''); setBindingOpen(true) }}><Link2 size={14} aria-hidden="true" />绑定服务器</button>
        <button type="button" className="button" disabled={locked} onClick={confirmBatchReset}><RefreshCcw size={14} aria-hidden="true" />重置计数</button>
        <button type="button" className="button danger" disabled={locked} onClick={confirmBatchDelete}><Trash2 size={14} aria-hidden="true" />批量删除</button>
      </BulkActionBar>
    </ReadList>
    <Modal open={bindingOpen && !management.denied} title="批量绑定服务器" subtitle={`作用于当前页选中的 ${selectedRows.length} 个节点`}
      onClose={() => { if (!management.state.busy) { setBindingOpen(false); setBindingError('') } }}>
      <form className="native-content-form" onSubmit={event => void bindMachine(event)}>
        <Field label="服务器 ID" hint="留空解除绑定 · 只更新选中节点的服务器归属" error={bindingError || management.error}>
          <Input data-autofocus placeholder="输入服务器 ID，留空解除绑定" value={machineId} disabled={management.state.busy} onChange={event => setMachineId(event.target.value)} />
        </Field>
        <div className="network-form-actions">
          <button className="button primary" disabled={management.state.busy || !selectedRows.length}>保存服务器归属</button>
          <button type="button" className="button" disabled={management.state.busy} onClick={() => { setBindingOpen(false); setBindingError('') }}>取消</button>
        </div>
      </form>
    </Modal>
  </div>
}
