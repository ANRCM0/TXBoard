import { Modal } from '../components/ui/Modal'
import { Search, RefreshCw, Plus, Pencil, Trash2, Network } from 'lucide-react'
import './network-reference.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { DataTable, type Column } from '../components/ui/DataTable'
import { PageHeader } from '../components/ui/PageHeader'
import { Checkbox } from '../components/ui/Field'

type Route = CoreOutput<'network.routes.list'>[number]
type Draft = { id: string; remarks: string; match: string; action: Route['action']; actionValue: string; enabled: boolean }
const blank = (): Draft => ({ id: '', remarks: '', match: '', action: 'direct', actionValue: '', enabled: true })
export function RoutesPage() {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [search, setSearch] = useState('')
  const [draft, setDraft] = useState(blank), [editing, setEditing] = useState(false), [error, setError] = useState('')
  const [sortIds, setSortIds] = useState<string[]>([]), [sorting, setSorting] = useState(false)
  const [nodePage, setNodePage] = useState(1), [nodeId, setNodeId] = useState(''), [target, setTarget] = useState('')
  const [simulation, setSimulation] = useState<CoreOutput<'network.routes.simulate'>>(), [simulating, setSimulating] = useState(false), [simulationError, setSimulationError] = useState(''), [simulationTarget, setSimulationTarget] = useState('')
  const request = useRef<AbortController>(), sequence = useRef(0), mounted = useRef(true)
  const list = useQuery({ queryKey: ['native-admin', 'network-routes'], queryFn: ({ signal }) => admin.read('network.routes.list', {}, signal) })
  const nodes = useQuery({ queryKey: ['native-admin', 'network-route-nodes', nodePage], queryFn: ({ signal }) => admin.read('network.nodes.list', { page: nodePage, per_page: 100 }, signal) })
  const pending = state.pending?.operation.startsWith('network.routes.') ? state.pending : undefined
  const denied = [list.error, state.error].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  const locked = denied || state.busy || !!state.pending || sorting || list.isFetching || list.isError || !list.data
  function cancelSimulation() { sequence.current++; request.current?.abort(); setSimulation(undefined); setSimulating(false); setSimulationError('') }
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; sequence.current++; request.current?.abort() } }, [])
  useEffect(() => { if (denied) { setDraft(blank()); setEditing(false); setSortIds([]); cancelSimulation() } }, [denied])
  useEffect(() => { if (nodes.isError) cancelSimulation() }, [nodes.isError])
  async function refresh() {
    const generation = workflowActions.generation()
    try { await list.refetch({ throwOnError: true }); if (!mounted.current || generation !== workflowActions.generation()) return; workflowActions.confirmReadAuthorization('network.routes.'); workflowActions.clearError(); setError('') } catch { /* Read feedback owns the failure. */ }
  }
  async function perform(command: () => Promise<void>) {
    const generation = workflowActions.generation(); setError('')
    try { await command(); if (!mounted.current || generation !== workflowActions.generation()) return; setEditing(false); setDraft(blank()); setSortIds([]); cancelSimulation(); await refresh() }
    catch (failure) { if (mounted.current && generation === workflowActions.generation()) setError(managementError(failure)) }
  }
  async function save() {
    if (locked) return
    const input: CoreInput<'network.routes.save'> = { ...(draft.id ? { id: draft.id } : {}), remarks: draft.remarks.trim(),
      match: draft.match.split('\n').map(value => value.trim()).filter(Boolean), action: draft.action,
      action_value: ['direct', 'block'].includes(draft.action) ? null : draft.actionValue || null, enabled: draft.enabled }
    await perform(() => workflowActions.run('network.routes.save', input))
  }
  async function saveSort() {
    if (locked || !sortIds.length || sortIds.length > 500) return
    const generation = workflowActions.generation(); setSorting(true); setError('')
    try {
      const fresh = await list.refetch({ throwOnError: true })
      if (!mounted.current || generation !== workflowActions.generation()) return
      const ids = fresh.data?.data.map(row => row.id) ?? []
      if (ids.length !== sortIds.length || new Set(sortIds).size !== sortIds.length || ids.some(id => !sortIds.includes(id))) {
        setSortIds([]); setError('路由集合已变化，请重新读取完整排序。'); return
      }
      await perform(() => workflowActions.run('network.routes.sort', { items: sortIds.map((id, sort) => ({ id, sort })) }))
    } catch (failure) { if (mounted.current && generation === workflowActions.generation()) setError(managementError(failure)) }
    finally { if (mounted.current && generation === workflowActions.generation()) setSorting(false) }
  }
  async function simulate() {
    if (denied || list.isError || nodes.isError || nodes.isFetching || !nodes.data?.data.some(row => row.id === nodeId) || !target.trim() || target.length > 512) return
    cancelSimulation(); const serial = sequence.current, generation = workflowActions.generation(), observed = { node_id: nodeId, target: target.trim() }
    const controller = new AbortController(); request.current = controller; setSimulating(true)
    const timer = setTimeout(() => { controller.abort(); if (mounted.current && serial === sequence.current && generation === workflowActions.generation()) { setSimulating(false); setSimulationError('模拟读取已超时，请重试。') } }, 15000)
    try {
      const result = await admin.read('network.routes.simulate', observed, controller.signal)
      if (!mounted.current || controller.signal.aborted || serial !== sequence.current || generation !== workflowActions.generation()) return
      if (result.data.node.id !== observed.node_id) throw new Error('SIMULATION_BINDING_CHANGED')
      setSimulationTarget(observed.target); setSimulation(result.data)
    } catch (failure) { if (mounted.current && !controller.signal.aborted && serial === sequence.current && generation === workflowActions.generation()) setSimulationError(managementError(failure)) }
    finally { clearTimeout(timer); if (mounted.current && serial === sequence.current && generation === workflowActions.generation()) setSimulating(false) }
  }
  function move(index: number, step: number) { setSortIds(old => { const next = [...old]; [next[index], next[index + step]] = [next[index + step]!, next[index]!]; return next }) }
  const rows = denied || list.isError ? [] : list.data?.data ?? []
  const columns: Column<Route>[] = [
    { key: 'remarks', header: '路由', render: row => <div className="native-row-identity"><strong>{row.remarks || '未命名路由'}</strong><small>#{row.id}</small></div> },
    { key: 'match', header: '匹配', render: row => <div className="network-route-patterns" tabIndex={0} role="region" aria-label={'路由 ' + row.id + ' 匹配规则'}>{row.match.map((pattern, index) => <code key={index}>{pattern}</code>)}</div> },
    { key: 'action', header: '动作', render: row => <div className="network-match-condition"><span className="network-tag is-info">{row.action}</span>{row.action_value ? <code>{row.action_value}</code> : null}</div> },
    { key: 'enabled', header: '状态', render: row => <span className={'network-tag ' + (row.enabled ? 'is-success' : '')}>{row.enabled ? '启用' : '停用'}</span> },
    { key: 'nodes', header: '节点', render: row => <span className="network-count">{row.server_count}</span> },
    { key: 'actions', header: '操作', render: row => <><button className="button" disabled={locked} onClick={() => { setDraft({ id: row.id, remarks: row.remarks, match: row.match.join('\n'), action: row.action, actionValue: row.action_value ?? '', enabled: row.enabled }); setEditing(true) }} title="编辑" aria-label="编辑"><Pencil size={15} aria-hidden="true" /><span className="network-action-label">编辑</span></button><button className="button" disabled={locked} onClick={() => { if (window.confirm('确定删除此路由？有关联节点时服务端可能拒绝。')) void perform(() => workflowActions.run('network.routes.delete', { route_id: row.id })) }} title="删除" aria-label="删除" data-danger="true"><Trash2 size={15} aria-hidden="true" /><span className="network-action-label">删除</span></button></> },
  ]
  return <div className="native-management-page network-reference-page network-routes-page">
    <PageHeader title="路由管理" description="维护完整路由配置，并只读模拟指定节点的目标匹配。" action={<><button className="button" disabled={state.busy || list.isFetching} onClick={() => void refresh()}><RefreshCw size={15} aria-hidden="true" />刷新路由</button><button className="button" data-primary="true" disabled={locked} onClick={() => { setDraft(blank()); setEditing(true) }}><Plus size={15} aria-hidden="true" />新增路由</button></>} />
    {pending ? <section className="list-card network-recovery-card" role="status"><h2>路由操作等待确认</h2><button className="button" disabled={denied || state.busy || list.isFetching || list.isError} onClick={() => void perform(() => workflowActions.run(pending.operation, pending.input))}>确认原路由操作</button></section> : null}
    {!editing && (error || state.error) ? <p role="alert">{error || managementError(state.error)}</p> : null}{denied ? <p role="alert">无法确认路由访问权限，已隐藏配置。</p> : null}
    <section className="list-card native-read-list"><div className="network-filterbar"><label className="native-list-search"><Search size={16} aria-hidden="true" /><input aria-label="搜索路由" placeholder="搜索路由备注或匹配规则" value={search} onChange={event => setSearch(event.target.value)} /></label><span className="network-toolbar-hint">按配置顺序匹配 · 排序始终包含完整路由</span>{search ? <button type="button" className="button network-clear-filter" onClick={() => setSearch('')}>清除搜索</button> : null}</div><ManagementFeedback error={list.error} loading={list.isFetching} onRetry={() => void refresh()} />{!denied && !list.isError && list.data ? <><p className="native-scroll-hint">左右滑动表格查看完整信息</p><DataTable rows={rows.filter(row => [row.remarks, ...row.match].some(value => value.toLowerCase().includes(search.trim().toLowerCase())))} columns={columns} rowKey={row => row.id} label="路由列表" /><button className="button" disabled={locked || !rows.length || rows.length > 500} onClick={() => setSortIds(rows.map(row => row.id))}>编辑完整路由排序</button>{rows.length > 500 ? <p>当前排序接口最多接受 500 条；不能以子集替代完整排序。</p> : null}</> : null}</section>
    {sortIds.length && !denied ? <section className="list-card network-sort-card"><div className="network-section-title"><h2>完整路由排序</h2></div><p className="network-help">调整完整列表中的顺序，保存后按从上到下的顺序匹配。</p><ol className="network-sort-list">{sortIds.map((id, index) => <li key={id}><span className="network-sort-identity"><small>{index + 1}</small><strong>{rows.find(row => row.id === id)?.remarks || '#' + id}</strong><code>#{id}</code></span><div className="network-sort-actions"><button className="button" disabled={locked || index === 0} onClick={() => move(index, -1)}>上移</button><button className="button" disabled={locked || index === sortIds.length - 1} onClick={() => move(index, 1)}>下移</button></div></li>)}</ol><div className="network-sort-footer"><button className="button" disabled={locked} onClick={() => void saveSort()}>保存完整路由排序</button><button className="button" onClick={() => setSortIds([])}>取消排序</button></div></section> : null}
    <Modal open={editing && !denied} title={draft.id ? '编辑路由' : '新增路由'} subtitle="保存会更新当前配置；多人协作时请先刷新并核对。" className="network-reference-page network-editor-modal" onClose={() => { if (!state.busy) setEditing(false) }}><div className="network-editor-body">{error || state.error ? <p role="alert">{error || managementError(state.error)}</p> : null}<form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}>
      <label>备注<input data-autofocus placeholder="例如：流媒体直连" required maxLength={255} value={draft.remarks} disabled={locked} onChange={event => setDraft({ ...draft, remarks: event.target.value })} /></label><label>匹配规则（每行一项，最多 200 项）<textarea aria-label="路由匹配规则" placeholder="每行输入一条匹配规则" rows={6} required value={draft.match} disabled={locked} onChange={event => setDraft({ ...draft, match: event.target.value })} /></label><label>动作<select value={draft.action} disabled={locked} onChange={event => setDraft({ ...draft, action: event.target.value as Route['action'] })}>{['direct', 'block', 'dns', 'proxy'].map(value => <option key={value}>{value}</option>)}</select></label><label>动作参数<input placeholder="DNS 地址或代理参数" required={['dns', 'proxy'].includes(draft.action)} maxLength={255} value={draft.actionValue} disabled={locked} onChange={event => setDraft({ ...draft, actionValue: event.target.value })} /></label><Checkbox label="启用" checked={draft.enabled} disabled={locked} onChange={event => setDraft({ ...draft, enabled: event.target.checked })} /><div className="network-form-actions"><button className="button" disabled={locked}>保存路由</button><button className="button" type="button" onClick={() => setEditing(false)}>取消</button>
    </div></form></div></Modal>
    {!denied ? <section className="list-card network-simulation-card"><div className="network-section-title"><Network size={18} /><h2>只读路由模拟</h2><span className="network-tag is-info">只读</span></div><p className="network-help">选择已读取的节点并输入目标，检查当前规则的匹配顺序和最终动作。</p><ManagementFeedback error={nodes.error} loading={nodes.isFetching} onRetry={() => void nodes.refetch()} /><form className="native-content-form network-simulation-form" onSubmit={event => { event.preventDefault(); void simulate() }}><label>已读取节点<select value={nodeId} disabled={nodes.isFetching || nodes.isError} onChange={event => { cancelSimulation(); setNodeId(event.target.value) }}><option value="">选择当前页节点</option>{!nodes.isError ? nodes.data?.data.map(row => <option key={row.id} value={row.id}>{row.name} · #{row.id}</option>) : null}</select></label><div className="network-node-pagination"><button type="button" className="button" disabled={nodePage === 1 || nodes.isFetching} onClick={() => { cancelSimulation(); setNodeId(''); setNodePage(value => value - 1) }}>上一页节点</button><button type="button" className="button" disabled={!nodes.data || nodes.isFetching || nodePage >= nodes.data.meta.last_page} onClick={() => { cancelSimulation(); setNodeId(''); setNodePage(value => value + 1) }}>下一页节点</button></div><label className="network-form-field-wide">目标<input placeholder="例如：example.com" required maxLength={512} value={target} onChange={event => { cancelSimulation(); setTarget(event.target.value) }} /></label><div className="network-simulation-actions"><button className="button" data-primary="true" disabled={simulating || nodes.isFetching || nodes.isError || !nodeId || !target.trim()}>运行只读模拟</button>{simulating ? <button className="button" type="button" onClick={cancelSimulation}>取消模拟</button> : null}</div></form>
      {simulationError ? <p role="alert">{simulationError}</p> : null}{simulation && !nodes.isError && !list.isError ? <div className="network-simulation-result" role="status"><p>{simulation.node.name} · 请求目标 {simulationTarget} · 归一化目标 {simulation.target}</p><p>{simulation.authoritative ? '当前配置匹配结果' : '非权威参考结果'} · {simulation.warning}</p><p>{simulation.match ? simulation.match.action + ' · ' + (simulation.match.action_value ?? '') + ' · ' + simulation.match.pattern : '未匹配路由'}</p><p>已评估 {simulation.evaluated_routes.length} 条路由；未解析 {simulation.unresolved_patterns.length} 项。</p><ol aria-label="路由模拟评估">{simulation.evaluated_routes.map(row => <li key={row.id}>{row.remarks} · {row.matched ? '命中' : '未命中'} · {row.action} · {row.matched_pattern}</li>)}</ol>{simulation.unresolved_patterns.length ? <ul aria-label="未解析路由规则">{simulation.unresolved_patterns.map((pattern, index) => <li key={index}>{pattern}</li>)}</ul> : null}</div> : null}
    </section> : null}
  </div>
}
