import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useListPagination } from './useListPagination'
import { Search, RefreshCw, ArrowUpRight, Eye, EyeOff, Link2, Power, PowerOff, RefreshCcw, Trash2 } from 'lucide-react'
import './network-reference.css'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { NetworkBytes, NetworkEndpoint } from './NetworkValues'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { ManagementFeedback } from './Feedback'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import type { Column } from '../components/ui/DataTable'
import { StatusBadge } from '../components/ui/StatusBadge'
import { BulkActionBar } from '../components/ui/BulkActionBar'
import { FilterBar } from '../components/ui/FilterBar'
import { Modal } from '../components/ui/Modal'
import { Field, Input, Select } from '../components/ui/Field'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { admin, workflowActions } from './session'
import { NodeEditor } from './NodeEditor'

type Node = CoreOutput<'network.nodes.list'>[number]
const columns: Column<Node>[] = [
  { key: 'name', header: '节点', width: '200px', render: node => <div className="nodes-v3-identity">
    <Link to={'/server/node/' + node.id}><strong>{node.name}</strong></Link><small>#{node.id} <span className="network-protocol">{node.type}</span></small>
  </div> },
  { key: 'host', header: '连接地址', width: '155px', render: node =>
    <NetworkEndpoint host={node.host} port={node.port} /> },
  { key: 'machine', header: '所属服务器', width: '150px', render: node => node.machine_id ? <Link className="network-resource-link" to={'/server/machine/' + node.machine_id}>#{node.machine_id}</Link> : <span className="network-muted">{t("未绑定")}</span> },
  { key: 'state', header: '状态', width: '155px', render: node => <div className="network-tags"><StatusBadge tone={node.enabled ? 'success' : 'neutral'}>{node.enabled ? t("已启用") : t("已禁用")}</StatusBadge><span className="reference-tag">{node.show ? t("显示") : t("隐藏")}</span></div> },
  { key: 'traffic', header: '累计上传 / 下载', width: '210px', render: node => <div className="network-traffic-pair"><span><small>{t("上传")}</small><NetworkBytes value={node.u} /></span><span><small>{t("下载")}</small><NetworkBytes value={node.d} /></span></div> },
  { key: 'details', header: '操作', width: '100px', render: node => <Link className="network-icon-link" title={t("查看详情")} aria-label={t("查看 {name} 详情", { name: node.name })} to={'/server/node/' + node.id}><ArrowUpRight size={16} /></Link> },
]

export function NodesPage() {
  useLocale()
  const [params, setParams] = useSearchParams(), navigate = useNavigate()
  const machineFilter = /^[1-9][0-9]{0,18}$/.test(params.get('machine_id') ?? '') ? params.get('machine_id')! : undefined
  const [editorId, setEditorId] = useState<string | null>()
  useEffect(() => { if (params.get('open_create') === '1') setEditorId(null) }, [params])
  const { page, setPage, perPage, setPerPage } = useListPagination()
  const [search, setSearch] = useState(''), [status, setStatus] = useState('')
  const [protocol, setProtocol] = useState(''), [deployment, setDeployment] = useState(''), [visibility, setVisibility] = useState('')
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [bindingOpen, setBindingOpen] = useState(false), [machineId, setMachineId] = useState(''), [bindingError, setBindingError] = useState('')
  const [machinePage, setMachinePage] = useState(1)
  const [sortNodes, setSortNodes] = useState<CoreOutput<'network.nodes.ordering.get'>['nodes']>([])
  const [sortRevision, setSortRevision] = useState(''), [sortIds, setSortIds] = useState<string[]>([])
  const [sortConflict, setSortConflict] = useState(false), [sorting, setSorting] = useState(false)
  const [sortReadError, setSortReadError] = useState<unknown>(), [sortError, setSortError] = useState('')
  const mounted = useRef(false), sortSequence = useRef(0), sortGeneration = useRef(workflowActions.generation())
  const sortBaseline = useRef('')
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; sortSequence.current++ } }, [])
  const query = useQuery({ queryKey: ['native-admin', 'network.nodes.list', page, perPage, machineFilter],
    queryFn: ({ signal }) => admin.read('network.nodes.list', { page, per_page: perPage, ...(machineFilter ? { machine_id: machineFilter } : {}) }, signal) })
  const machines = useQuery({ queryKey: ['native-admin', 'node-binding-machines', machinePage], enabled: bindingOpen,
    queryFn: ({ signal }) => admin.read('network.machines.list', { page: machinePage, per_page: 100 }, signal) })
  const management = useNetworkManagement('network.nodes.', [query.error, machines.error, sortReadError], async () => {
    await query.refetch({ throwOnError: true })
    if (machines.isError) await machines.refetch({ throwOnError: true })
    if (sortReadError || workflowActions.snapshot().pending?.operation === 'network.nodes.ordering.replace') {
      await admin.read('network.nodes.ordering.get', {})
      setSortReadError(undefined)
    }
  })
  useEffect(() => {
    if (management.denied || query.isError) { setSelected(new Set()); setBindingOpen(false); setEditorId(undefined) }
  }, [management.denied, query.isError])
  useEffect(() => { setPage(1); setSelected(new Set()) }, [machineFilter])
  useEffect(() => {
    if (sortGeneration.current !== workflowActions.generation()) {
      sortGeneration.current = workflowActions.generation(); sortSequence.current++
      setSortIds([]); setSortNodes([]); setSortRevision(''); setSortReadError(undefined); setSortError(''); setSorting(false)
    }
    if (management.denied) { sortSequence.current++; setSortIds([]); setSortNodes([]); setSortRevision(''); setSorting(false) }
  }, [management.state, management.denied])
  const rows = management.denied || query.isError ? [] : (query.data?.data ?? []).filter(node =>
    (!status || String(node.enabled) === status)
    && (!protocol || node.type === protocol)
    && (!visibility || String(node.show) === visibility)
    && (!deployment || (deployment === 'standalone' ? node.machine_id === null : node.machine_id === deployment))
    && [node.id, node.name, node.type, node.host, node.machine_id].some(value => String(value ?? '').toLowerCase().includes(search.trim().toLowerCase())))
  const selectedRows = rows.filter(node => selected.has(node.id))
  const mixedMachines = selectedRows.length > 1 && new Set(selectedRows.map(node => node.machine_id)).size > 1
  const locked = management.denied || query.isFetching || query.isError || !query.data
    || management.state.busy || !!management.state.pending || sorting
  useDirtyDraft(!!sortRevision && JSON.stringify(sortIds) !== sortBaseline.current && !management.denied && !management.state.pending && sortGeneration.current === workflowActions.generation())
  const applied = [
    ...(machineFilter ? [{ key: 'machine-filter', label: t("服务器 #{id}（全部分页）", { id: machineFilter }) }] : []),
    ...(search.trim() ? [{ key: 'search', label: t("搜索 {value}", { value: search.trim() }) }] : []),
    ...(status ? [{ key: 'status', label: status === 'true' ? t("已启用") : t("已停用") }] : []),
    ...(protocol ? [{ key: 'protocol', label: t("协议 {value}", { value: protocol }) }] : []),
    ...(deployment ? [{ key: 'deployment', label: deployment === 'standalone' ? t("独立部署") : t("服务器 #{id}", { id: deployment }) }] : []),
    ...(visibility ? [{ key: 'visibility', label: visibility === 'true' ? t("订阅中显示") : t("订阅中隐藏") }] : []),
  ]

  function changeSearch(value: string) { setSearch(value); setSelected(new Set()) }
  function changeStatus(value: string) { setStatus(value); setSelected(new Set()) }
  function clearFilters() { setSearch(''); setStatus(''); setProtocol(''); setDeployment(''); setVisibility(''); setSelected(new Set()); const next = new URLSearchParams(params); next.delete('machine_id'); setParams(next) }
  function closeEditor() { setEditorId(undefined); const next = new URLSearchParams(params); next.delete('open_create'); setParams(next, { replace: true }) }
  function selectedIds() { return selectedRows.map(node => node.id) }

  async function beginSort() {
    if (locked || (sortRevision && !window.confirm(t('重新读取将替换当前排序草稿，确定继续？')))) return
    const generation = workflowActions.generation(), serial = ++sortSequence.current
    const current = () => mounted.current && serial === sortSequence.current && generation === workflowActions.generation()
    setSorting(true); setSortError('')
    try {
      const fresh = await admin.read('network.nodes.ordering.get', {})
      if (!current()) return
      const ids = fresh.data.nodes.map(node => node.id)
      sortBaseline.current = JSON.stringify(ids)
      setSortNodes(fresh.data.nodes); setSortIds(ids); setSortRevision(fresh.data.revision)
      setSortConflict(false); setSortReadError(undefined)
    } catch (failure) {
      if (current()) { setSortReadError(failure); setSortError(failure instanceof CoreAdminApiError && failure.code === 'NETWORK_NODE_SORT_LIMIT'
        ? '完整节点排序最多支持 100 个节点，不能以分页或筛选子集替代。' : '无法读取完整节点排序，请刷新后重试。') }
    } finally { if (current()) setSorting(false) }
  }
  function moveNode(index: number, offset: number) {
    const target = index + offset
    if (locked || sortConflict || target < 0 || target >= sortIds.length) return
    setSortIds(old => { const next = [...old]; [next[index], next[target]] = [next[target]!, next[index]!]; return next })
  }
  async function saveSort() {
    if (locked || sortConflict || !sortRevision || !sortIds.length || sortIds.length > 100) return
    const generation = workflowActions.generation(), serial = ++sortSequence.current
    const current = () => mounted.current && serial === sortSequence.current && generation === workflowActions.generation()
    setSorting(true); setSortError('')
    try {
      const fresh = await admin.read('network.nodes.ordering.get', {})
      if (!current()) return
      const known = fresh.data.nodes.map(node => node.id)
      if (fresh.data.revision !== sortRevision || known.length !== sortIds.length || new Set(sortIds).size !== known.length || known.some(id => !sortIds.includes(id))) {
        setSortConflict(true); setSortError('节点集合或排序已变化，当前排序草稿已保留，请重新读取完整排序。'); return
      }
      await workflowActions.run('network.nodes.ordering.replace', { expected_revision: sortRevision, node_ids: sortIds })
      if (!current()) return
      setSortIds([]); setSortNodes([]); setSortRevision(''); await management.refresh()
    } catch (failure) {
      if (current()) {
        if (failure instanceof CoreAdminApiError && failure.status === 409) setSortConflict(true)
        if (failure instanceof CoreAdminApiError && [401, 403].includes(failure.status)) setSortReadError(failure)
        setSortError('完整节点排序未确认保存。草稿已保留；如原操作等待确认，请使用原请求恢复。')
      }
    } finally { if (current()) setSorting(false) }
  }
  function closeSort() {
    if (locked || !window.confirm(t('确定放弃当前节点排序草稿？'))) return
    setSortIds([]); setSortNodes([]); setSortRevision(''); setSortConflict(false); setSortError('')
  }

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
      title: t("批量删除节点"),
      message: t("将删除当前页选中的 {v0} 个节点，删除后不可恢复", { v0: ids.length }),
      confirmLabel: t("删除节点"),
      danger: true,
      action: () => { void management.perform(() => workflowActions.run('network.nodes.batch-delete', { ids }), () => setSelected(new Set())) },
    })
  }
  function confirmBatchReset() {
    const ids = selectedIds()
    if (!ids.length || locked) return
    requestConfirm({
      title: t("批量重置节点计数"),
      message: t("将重置当前页选中的 {v0} 个节点的上传与下载计数，用户订阅额度不受影响", { v0: ids.length }),
      confirmLabel: t("重置计数"),
      action: () => { void management.perform(() => workflowActions.run('network.nodes.batch-traffic-reset', { ids }), () => setSelected(new Set())) },
    })
  }
  function openBinding() {
    const ids = new Set(selectedRows.map(node => node.machine_id))
    setMachineId(ids.size === 1 ? [...ids][0] ?? '' : '')
    setBindingError('')
    setMachinePage(1)
    setBindingOpen(true)
  }
  async function bindMachine(event: FormEvent) {
    event.preventDefault()
    const ids = selectedIds()
    if (!ids.length || locked || machines.isFetching || machines.isError || !machines.data) return
    setBindingError('')
    await management.perform(async () => {
      await workflowActions.run('network.nodes.batch-update', { ids, machine_id: machineId || null })
    }, () => { setSelected(new Set()); setBindingOpen(false); setMachineId('') })
  }

  return <div className="native-management-page network-reference-page network-nodes-page">
    <PageHeader title={t("节点管理")} description={t("查看节点，并对当前页选中项执行批量状态、归属和计数操作")} action={<><button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void management.refresh()}><RefreshCw size={15} aria-hidden="true" />{t("刷新节点")}</button><button type="button" className="button" data-primary="true" disabled={locked} onClick={() => setEditorId(null)}>{t("创建节点")}</button></>} />
    <NetworkRecovery management={management} blocked={query.isFetching || query.isError || !query.data || management.state.busy} />
    <section className="list-card network-sort-card">
      <button type="button" className="button" disabled={locked} onClick={() => void beginSort()}>{t(sortRevision ? '重新读取完整节点排序' : '编辑完整节点排序')}</button>
      <p>{t('排序读取所有节点，不受当前页、搜索、服务器筛选影响；最多 100 个节点，保存会验证完整集合修订。')}</p>
      {sortError ? <p role="alert">{t(sortError)}</p> : null}
      {sortRevision && !management.denied ? <><ol className="network-sort-list" aria-label={t('完整节点排序')}>{sortIds.map((id, index) => {
        const node = sortNodes.find(row => row.id === id)
        return <li key={id}><span><small>{index + 1}</small><strong>{node?.name}</strong><code>#{id} · {node?.type}</code></span><div className="network-sort-actions">
          <button type="button" className="button" aria-label={t('上移节点 {id}', { id })} disabled={locked || sortConflict || index === 0} onClick={() => moveNode(index, -1)}>{t('上移')}</button>
          <button type="button" className="button" aria-label={t('下移节点 {id}', { id })} disabled={locked || sortConflict || index === sortIds.length - 1} onClick={() => moveNode(index, 1)}>{t('下移')}</button>
        </div></li>
      })}</ol>{!sortIds.length ? <p>{t('当前没有节点可排序。')}</p> : null}<div className="network-sort-footer">
        <button type="button" className="button" disabled={locked || sortConflict || !sortIds.length} onClick={() => void saveSort()}>{t('保存完整节点排序')}</button>
        <button type="button" className="button" disabled={locked} onClick={closeSort}>{t('取消排序')}</button>
      </div></> : null}
    </section>
    <ReadList<Node> title={"节点列表"} rows={rows} columns={columns.map(column => column.key === 'details' ? { ...column, render: (node: Node) => <div className="native-list-toolbar"><button type="button" className="button" disabled={locked} onClick={() => setEditorId(node.id)}>{t("编辑")}</button><Link className="network-icon-link" title={t("查看详情")} aria-label={t("查看 {name} 详情", { name: node.name })} to={'/server/node/' + node.id}><ArrowUpRight size={16} /></Link></div> } : column)} perPage={perPage} onPageSize={size => { setPerPage(size); setSelected(new Set()); }} page={page} meta={query.data?.meta} ready={!!query.data && !management.denied}
      loading={query.isFetching} error={query.error} onRetry={() => void management.refresh()}
      onPage={next => { setPage(next); setSearch(''); setSelected(new Set()) }}
      columnSettingsKey="nodes" stickyHeader stickyFirstColumn
      selection={{ selected, onChange: setSelected, getKey: node => node.id, getLabel: node => node.name, disabled: locked }}>
      <FilterBar applied={applied} onClear={clearFilters} hint={t("搜索与状态仅筛选当前页 · 批量操作只作用于当前页选中节点")}>
        <label className="native-list-search"><Search size={16} aria-hidden="true" /><span className="network-sr-only">{t("搜索当前页")}</span>
          <Input placeholder={t("搜索当前页名称、ID 或地址")} aria-label={t("搜索当前页")} value={search} onChange={event => changeSearch(event.target.value)} /></label>
        <label>{t("节点状态")}<Select aria-label={t("节点状态")} value={status} onChange={event => changeStatus(event.target.value)}>
          <option value="">{t("全部状态")}</option><option value="true">{t("已启用")}</option><option value="false">{t("已停用")}</option>
        </Select></label>
        <label>{t("协议")}<Select aria-label={t("节点协议")} value={protocol} onChange={event => { setProtocol(event.target.value); setSelected(new Set()) }}><option value="">{t("全部协议")}</option>{[...new Set((management.denied || query.isError ? [] : query.data?.data ?? []).map(node => node.type))].map(value => <option key={value} value={value}>{value}</option>)}</Select></label>
        <label>{t("部署归属")}<Select aria-label={t("部署归属")} value={deployment} onChange={event => { setDeployment(event.target.value); setSelected(new Set()) }}><option value="">{t("全部归属")}</option><option value="standalone">{t("独立部署 / 未绑定")}</option>{[...new Set((management.denied || query.isError ? [] : query.data?.data ?? []).map(node => node.machine_id).filter((value): value is string => !!value))].map(value => <option key={value} value={value}>{t("服务器 #")}{value}</option>)}</Select></label>
        <label>{t("订阅展示")}<Select aria-label={t("订阅展示")} value={visibility} onChange={event => { setVisibility(event.target.value); setSelected(new Set()) }}><option value="">{t("显示与隐藏")}</option><option value="true">{t("显示")}</option><option value="false">{t("隐藏")}</option></Select></label>
      </FilterBar>
      <p className="network-counter-note">{t("累计上传 / 下载为节点原始字节计数；悬停流量可查看精确字节值。")}</p>
      <BulkActionBar count={selectedRows.length} onClear={() => setSelected(new Set())}>
        <button type="button" className="button" disabled={locked} onClick={() => void batchUpdate({ enabled: true })}><Power size={14} aria-hidden="true" />{t("批量启用")}</button>
        <button type="button" className="button" disabled={locked} onClick={() => void batchUpdate({ enabled: false })}><PowerOff size={14} aria-hidden="true" />{t("批量禁用")}</button>
        <button type="button" className="button" disabled={locked} onClick={() => void batchUpdate({ show: false })}><EyeOff size={14} aria-hidden="true" />{t("批量隐藏")}</button>
        <button type="button" className="button" disabled={locked} onClick={() => void batchUpdate({ show: true })}><Eye size={14} aria-hidden="true" />{t("批量显示")}</button>
        <button type="button" className="button" disabled={locked} onClick={openBinding}><Link2 size={14} aria-hidden="true" />{t("绑定服务器")}</button>
        <button type="button" className="button" disabled={locked} onClick={confirmBatchReset}><RefreshCcw size={14} aria-hidden="true" />{t("重置计数")}</button>
        <button type="button" className="button danger" disabled={locked} onClick={confirmBatchDelete}><Trash2 size={14} aria-hidden="true" />{t("批量删除")}</button>
      </BulkActionBar>
    </ReadList>
    {editorId !== undefined && !management.denied ? <NodeEditor key={editorId ?? 'new'} nodeId={editorId ?? undefined} defaultMachineId={machineFilter}
      onClose={closeEditor} onSaved={id => { const created = editorId === null; closeEditor(); setSelected(new Set()); void query.refetch(); if (created && id) navigate('/server/node/' + id) }} /> : null}
    <Modal open={bindingOpen && !management.denied} title={t("批量绑定服务器")} subtitle={t("作用于当前页选中的 {v0} 个节点", { v0: selectedRows.length })}
      onClose={() => { if (!management.state.busy) { setBindingOpen(false); setBindingError('') } }}>
      <form className="native-content-form" onSubmit={event => void bindMachine(event)}>
        <ManagementFeedback error={machines.isError ? machines.error : undefined} loading={machines.isFetching} onRetry={() => void machines.refetch()} />
        <Field label={t("服务器")} error={bindingError ? t(bindingError) : management.error ? t(management.error) : undefined}
          hint={mixedMachines ? t("选中节点归属不一致 · 保存会统一绑定到所选服务器") : t("留空解除绑定 · 只更新选中节点的服务器归属")}>
          <Select data-autofocus value={machineId} disabled={locked || machines.isFetching || machines.isError || !machines.data}
            onChange={event => setMachineId(event.target.value)}>
            <option value="">{t("不绑定 / 解除绑定")}</option>
            {!machines.isError && machineId && !machines.data?.data.some(machine => machine.id === machineId)
              ? <option value={machineId}>{t("当前服务器 #")}{machineId}</option> : null}
            {!machines.isError && machines.data?.data.map(machine => <option key={machine.id} value={machine.id}>{machine.name} · #{machine.id}</option>)}
          </Select>
        </Field>
        <div className="network-node-pagination">
          <span>{t("服务器第{page} / {total}页 · 每页最多 100 台", { page: machinePage, total: machines.isError ? '?' : machines.data?.meta.last_page ?? '?' })}</span>
          <button type="button" className="button" disabled={locked || machines.isFetching || machinePage === 1} onClick={() => setMachinePage(value => value - 1)}>{t("上一页服务器")}</button>
          <button type="button" className="button" disabled={locked || machines.isFetching || machines.isError || !machines.data || machinePage >= machines.data.meta.last_page} onClick={() => setMachinePage(value => value + 1)}>{t("下一页服务器")}</button>
        </div>
        <div className="network-form-actions">
          <button className="button primary" disabled={locked || machines.isFetching || machines.isError || !machines.data || !selectedRows.length}>{t("保存服务器归属")}</button>
          <button type="button" className="button" disabled={management.state.busy} onClick={() => { setBindingOpen(false); setBindingError('') }}>{t("取消")}</button>
        </div>
      </form>
    </Modal>
  </div>
}
