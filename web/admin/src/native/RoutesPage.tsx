import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { Modal } from '../components/ui/Modal'
import { Search, RefreshCw, Plus, Pencil, Trash2, Network, GripVertical } from 'lucide-react'
import './network-reference.css'
import { useEffect, useId, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { DataTable, type Column } from '../components/ui/DataTable'
import { PageHeader } from '../components/ui/PageHeader'
import { Checkbox } from '../components/ui/Field'
import { FilterBar } from '../components/ui/FilterBar'
import { FacetedFilter } from '../components/ui/FacetedFilter'

type Route = CoreOutput<'network.routes.list'>[number]
type Draft = { id: string; remarks: string; match: string; action: Route['action']; actionValue: string; enabled: boolean }
const actionLabels: Record<string, string> = { direct: '直连', block: '阻止访问', dns: '指定 DNS', proxy: '代理转发' }
const actionHints = { direct: '匹配此规则的流量直接连接目标。', block: '匹配此规则的流量将被阻止。', dns: '输入此规则使用的 DNS 地址。', proxy: '输入此规则使用的代理参数。' }
const blank = (): Draft => ({ id: '', remarks: '', match: '', action: 'direct', actionValue: '', enabled: true })
export function RoutesPage() {
  useLocale()
  const formId = useId()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const generation = useSyncExternalStore(workflowActions.subscribe, workflowActions.generation)
  const [draftGeneration, setDraftGeneration] = useState(generation)
  const [search, setSearch] = useState(''), [actions, setActions] = useState<string[]>([]), [statuses, setStatuses] = useState<string[]>([]), [associations, setAssociations] = useState<string[]>([])
  const [draft, setDraft] = useState(blank), [editing, setEditing] = useState(false), [error, setError] = useState('')
  const draggedRoute = useRef(''), baseline = useRef(''), actionDrafts = useRef<Record<string, string>>({})
  const sortBaseline = useRef('')
  const [dropTarget, setDropTarget] = useState('')
  const [sortIds, setSortIds] = useState<string[]>([]), [sorting, setSorting] = useState(false), [sortRevision, setSortRevision] = useState(''), [sortConflict, setSortConflict] = useState(false), [sortReadError, setSortReadError] = useState<unknown>()
  const [nodePage, setNodePage] = useState(1), [nodeId, setNodeId] = useState(''), [target, setTarget] = useState('')
  const [simulation, setSimulation] = useState<CoreOutput<'network.routes.simulate'>>(), [simulating, setSimulating] = useState(false), [simulationError, setSimulationError] = useState(''), [simulationTarget, setSimulationTarget] = useState('')
  const request = useRef<AbortController>(), sequence = useRef(0), mounted = useRef(true)
  const list = useQuery({ queryKey: ['native-admin', 'network-routes'], queryFn: ({ signal }) => admin.read('network.routes.list', {}, signal) })
  const nodes = useQuery({ queryKey: ['native-admin', 'network-route-nodes', nodePage], queryFn: ({ signal }) => admin.read('network.nodes.list', { page: nodePage, per_page: 100 }, signal) })
  const pending = state.pending?.operation.startsWith('network.routes.') ? state.pending : undefined
  const operationError = !state.errorOperation || state.errorOperation.startsWith('network.routes.') ? state.error : undefined
  const denied = [list.error, operationError, sortReadError].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  const locked = denied || state.busy || !!state.pending || sorting || list.isFetching || list.isError || !list.data
  useDirtyDraft(!denied && !state.pending && draftGeneration === generation && (editing && JSON.stringify(draft) !== baseline.current || sortIds.length > 0 && JSON.stringify(sortIds) !== sortBaseline.current))
  function cancelSimulation() { sequence.current++; request.current?.abort(); setSimulation(undefined); setSimulating(false); setSimulationError('') }
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; sequence.current++; request.current?.abort() } }, [])
  useEffect(() => { setDraft(blank()); setEditing(false); setSortIds([]); setSortRevision(''); cancelSimulation() }, [generation])
  useEffect(() => { if (denied) { setDraft(blank()); setEditing(false); setSortIds([]); setSortRevision(''); setSearch(''); setActions([]); setStatuses([]); setAssociations([]); setNodeId(''); setTarget(''); setSimulationTarget(''); cancelSimulation() } }, [denied])
  useEffect(() => { if (nodes.isError) { setNodeId(''); setTarget(''); setSimulationTarget(''); cancelSimulation() } }, [nodes.isError])
  function edit(row?: Route) {
    const next = row ? { id: row.id, remarks: row.remarks, match: row.match.join('\n'), action: row.action, actionValue: row.action_value ?? '', enabled: row.enabled } : blank()
    setDraftGeneration(generation); setDraft(next); baseline.current = JSON.stringify(next); actionDrafts.current = { [next.action]: next.actionValue }; setError(''); setEditing(true)
  }
  function closeEditor() {
    if (state.busy || (!state.pending && JSON.stringify(draft) !== baseline.current && !window.confirm(t("路由配置尚未保存，确定放弃修改？")))) return
    setEditing(false); setDraft(blank()); setError('')
  }
  function changeAction(action: Route['action']) {
    actionDrafts.current[draft.action] = draft.actionValue
    setDraft(current => ({ ...current, action, actionValue: actionDrafts.current[action] ?? '' }))
  }
  async function refresh() {
    const generation = workflowActions.generation()
    try { await list.refetch({ throwOnError: true }); if (!mounted.current || generation !== workflowActions.generation()) return; workflowActions.confirmReadAuthorization('network.routes.'); workflowActions.clearError(); setSortReadError(undefined); setError('') } catch { /* Read feedback owns the failure. */ }
  }
  async function perform(command: () => Promise<void>) {
    const generation = workflowActions.generation(); setError('')
    try { await command(); if (!mounted.current || generation !== workflowActions.generation()) return; setEditing(false); setDraft(blank()); setSortIds([]); setSortRevision(''); setSortConflict(false); cancelSimulation(); await refresh() }
    catch (failure) { if (mounted.current && generation === workflowActions.generation()) setError(managementError(failure, false)) }
  }
  async function save() {
    if (locked) return
    const matches = draft.match.split('\n').map(value => value.trim()).filter(Boolean)
    if (!matches.length || matches.length > 200) { setError('请输入 1 至 200 项匹配规则，每行一项。'); return }
    if (['dns', 'proxy'].includes(draft.action) && !draft.actionValue.trim()) { setError('请填写动作参数。'); return }
    const input: CoreInput<'network.routes.save'> = { ...(draft.id ? { id: draft.id } : {}), remarks: draft.remarks.trim(),
      match: matches, action: draft.action,
      action_value: ['direct', 'block'].includes(draft.action) ? null : draft.actionValue.trim() || null, enabled: draft.enabled }
    await perform(() => workflowActions.run('network.routes.save', input))
  }
  async function beginSort() {
    if (locked || (sortIds.length > 0 && !window.confirm(t("重新读取将替换当前排序草稿，确定继续？")))) return
    const generation = workflowActions.generation(); setSorting(true); setError('')
    try {
      const fresh = await admin.read('network.routes.ordering.get', {})
      if (!mounted.current || generation !== workflowActions.generation()) return
      const known = list.data?.data.map(row => row.id) ?? []
      if (known.length !== fresh.data.route_ids.length || known.some(id => !fresh.data.route_ids.includes(id))) {
        await refresh(); setError('路由集合已变化，已刷新列表；请重新编辑完整排序。'); return
      }
      sortBaseline.current = JSON.stringify(fresh.data.route_ids)
      setDraftGeneration(generation); setSortIds(fresh.data.route_ids); setSortRevision(fresh.data.revision); setSortConflict(false); setSortReadError(undefined)
    } catch (failure) { if (mounted.current && generation === workflowActions.generation()) { setSortReadError(failure); setError(managementError(failure, false)) } }
    finally { if (mounted.current && generation === workflowActions.generation()) setSorting(false) }
  }
  async function saveSort() {
    if (locked || sortConflict || !sortRevision || !sortIds.length || sortIds.length > 500) return
    const generation = workflowActions.generation(); setSorting(true); setError('')
    try {
      const fresh = await admin.read('network.routes.ordering.get', {})
      if (!mounted.current || generation !== workflowActions.generation()) return
      if (fresh.data.revision !== sortRevision || fresh.data.route_ids.length !== sortIds.length
        || new Set(sortIds).size !== sortIds.length || fresh.data.route_ids.some(id => !sortIds.includes(id))) {
        setSortConflict(true); setError('路由集合已变化或排序已被其他操作修改，当前排序草稿已保留。请重新读取完整排序。'); return
      }
      await workflowActions.run('network.routes.ordering.replace', { expected_revision: sortRevision, route_ids: sortIds })
      if (!mounted.current || generation !== workflowActions.generation()) return
      setSortIds([]); setSortRevision(''); setSortConflict(false); cancelSimulation(); await refresh()
    } catch (failure) {
      if (mounted.current && generation === workflowActions.generation()) {
        if (failure instanceof CoreAdminApiError && failure.status === 409) setSortConflict(true)
        if (failure instanceof CoreAdminApiError && [401, 403].includes(failure.status)) setSortReadError(failure)
        setError(managementError(failure, false))
      }
    } finally { if (mounted.current && generation === workflowActions.generation()) setSorting(false) }
  }
  async function simulate() {
    if (denied || list.isError || nodes.isError || nodes.isFetching || !nodes.data?.data.some(row => row.id === nodeId) || !target.trim() || target.length > 512) return
    cancelSimulation(); const serial = sequence.current, generation = workflowActions.generation(), observed = { node_id: nodeId, target: target.trim() }
    const controller = new AbortController(); request.current = controller; setSimulating(true)
    const timer = setTimeout(() => { controller.abort(); if (mounted.current && serial === sequence.current && generation === workflowActions.generation()) { setSimulating(false); setSimulationError("模拟读取已超时，请重试。") } }, 15000)
    try {
      const result = await admin.read('network.routes.simulate', observed, controller.signal)
      if (!mounted.current || controller.signal.aborted || serial !== sequence.current || generation !== workflowActions.generation()) return
      if (result.data.node.id !== observed.node_id) throw new Error('SIMULATION_BINDING_CHANGED')
      setSimulationTarget(observed.target); setSimulation(result.data)
    } catch (failure) { if (mounted.current && !controller.signal.aborted && serial === sequence.current && generation === workflowActions.generation()) { if (failure instanceof CoreAdminApiError && [401, 403].includes(failure.status)) setSortReadError(failure); setSimulationError(managementError(failure, false)) } }
    finally { clearTimeout(timer); if (mounted.current && serial === sequence.current && generation === workflowActions.generation()) setSimulating(false) }
  }
  function move(index: number, to: number) {
    if (locked || sortConflict || index < 0 || to < 0 || index >= sortIds.length || to >= sortIds.length || index === to) return
    setSortIds(old => { const next = [...old], [id] = next.splice(index, 1); next.splice(to, 0, id!); return next })
  }
  const rows = denied || list.isError ? [] : list.data?.data ?? []
  const filtered = rows.filter(row => (!actions.length || actions.includes(row.action))
    && (!statuses.length || statuses.includes(String(row.enabled)))
    && (!associations.length || associations.includes(row.server_count > 0 ? 'assigned' : 'unassigned'))
    && [row.id, row.remarks, row.action_value ?? '', ...row.match].some(value => value.toLowerCase().includes(search.trim().toLowerCase())))
  const applied = [...(search.trim() ? [{ key: 'search', label: t("搜索 {value}", { value: search.trim() }) }] : []),
    ...actions.map(value => ({ key: 'action:' + value, label: t(actionLabels[value as Route['action']]) })),
    ...statuses.map(value => ({ key: 'status:' + value, label: value === 'true' ? t("启用") : t("停用") })),
    ...associations.map(value => ({ key: 'association:' + value, label: value === 'assigned' ? t("已关联节点") : t("未关联节点") }))]
  function clearFilters() { setSearch(''); setActions([]); setStatuses([]); setAssociations([]) }
  function removeFilter(key: string) {
    if (key === 'search') setSearch('')
    if (key.startsWith('action:')) setActions(current => current.filter(value => value !== key.slice(7)))
    if (key.startsWith('status:')) setStatuses(current => current.filter(value => value !== key.slice(7)))
    if (key.startsWith('association:')) setAssociations(current => current.filter(value => value !== key.slice(12)))
  }
  const columns: Column<Route>[] = [
    { key: 'remarks', header: t("路由"), hideable: false, render: row => <div className="native-row-identity"><strong>{row.remarks || t("未命名路由")}</strong><small>#{row.id}</small></div> },
    { key: 'match', header: t("匹配"), render: row => <div className="network-route-pattern-preview" role="region" aria-label={t("路由 {id} 匹配规则", { id: row.id })}><small className="network-muted">{t("{count}项匹配规则", { count: row.match.length })}</small>{row.match.slice(0, 3).map((pattern, index) => <code key={index}>{pattern}</code>)}
      {row.match.length > 3 ? <details><summary>{t("展开其余{count}项", { count: row.match.length - 3 })}</summary>{row.match.slice(3).map((pattern, index) => <code key={index}>{pattern}</code>)}</details> : null}</div> },
    { key: 'action', header: t("动作"), render: row => <div className="network-match-condition"><span className="network-tag is-info">{t(actionLabels[row.action])}</span>{row.action_value ? <code>{row.action_value}</code> : null}</div> },
    { key: 'enabled', header: t("状态"), render: row => <span className={'network-tag ' + (row.enabled ? 'is-success' : '')}>{row.enabled ? t("启用") : t("停用")}</span> },
    { key: 'nodes', header: t("节点"), render: row => <span className="network-count">{row.server_count}</span> },
    { key: 'actions', header: t("操作"), pin: 'right', hideable: false, render: row => <div className="network-route-actions"><button className="button" disabled={locked} onClick={() => edit(row)} title={t("编辑")} aria-label={t("编辑")}><Pencil size={15} aria-hidden="true" /><span className="network-action-label">{t("编辑")}</span></button><button className="button" disabled={locked} onClick={() => { if (window.confirm(t("确定删除路由 {name}？有关联节点时服务端会拒绝。", { name: row.remarks }))) void perform(() => workflowActions.run('network.routes.delete', { route_id: row.id })) }} title={t("删除")} aria-label={t("删除")} data-danger="true"><Trash2 size={15} aria-hidden="true" /><span className="network-action-label">{t("删除")}</span></button></div> },
  ]
  return <div className="native-management-page network-reference-page network-routes-page">
    <PageHeader title={t("路由管理")} description={t("维护完整路由配置，并只读模拟指定节点的目标匹配。")} action={<><button className="button" disabled={state.busy || list.isFetching} onClick={() => void refresh()}><RefreshCw size={15} aria-hidden="true" />{t("刷新路由")}</button><button className="button" data-primary="true" disabled={locked} onClick={() => edit()}><Plus size={15} aria-hidden="true" />{t("新增路由")}</button></>} />
    {pending ? <section className="list-card network-recovery-card" role="status"><h2>{t("路由操作等待确认")}</h2><button className="button" disabled={denied || state.busy || list.isFetching || list.isError} onClick={() => void perform(() => workflowActions.run(pending.operation, pending.input))}>{t("确认原路由操作")}</button></section> : null}
    {!editing && (error || operationError) ? <p role="alert">{error ? t(error) : managementError(operationError)}</p> : null}{denied ? <p role="alert">{t("无法确认路由访问权限，已隐藏配置。")}</p> : null}
    <section className="list-card native-read-list">
      <FilterBar applied={applied} onClear={clearFilters} onRemove={removeFilter} hint={t("筛选完整路由集合，按配置顺序匹配；排序始终包含全部路由，并由服务端校验修订。")}>
        <label className="native-list-search"><Search size={16} aria-hidden="true" /><input aria-label={t("搜索路由")} placeholder={t("搜索备注、ID、参数或匹配规则")} value={search} onChange={event => setSearch(event.target.value)} /></label>
        <FacetedFilter label={t("动作")} value={actions} onChange={setActions} disabled={denied || list.isError} options={Object.entries(actionLabels).map(([value, label]) => ({ value, label: t(label), count: rows.filter(row => row.action === value).length }))} />
        <FacetedFilter label={t("状态")} value={statuses} onChange={setStatuses} disabled={denied || list.isError} options={[{ value: 'true', label: t("启用"), count: rows.filter(row => row.enabled).length }, { value: 'false', label: t("停用"), count: rows.filter(row => !row.enabled).length }]} />
        <FacetedFilter label={t("节点关联")} value={associations} onChange={setAssociations} disabled={denied || list.isError} options={[{ value: 'assigned', label: t("已关联节点"), count: rows.filter(row => row.server_count > 0).length }, { value: 'unassigned', label: t("未关联节点"), count: rows.filter(row => row.server_count === 0).length }]} />
      </FilterBar>
      <ManagementFeedback error={list.error} loading={list.isFetching} onRetry={() => void refresh()} />
      {!denied && !list.isError && list.data ? <><p className="network-route-summary">{t("完整集合{total}条 · 当前显示{count}条 · 匹配顺序自上而下", { total: rows.length, count: filtered.length })}</p>
        <DataTable<Route> rows={filtered} columns={columns} rowKey={row => row.id} label={t("路由列表")} empty={t("没有符合条件的路由")} stickyHeader stickyFirstColumn stickyLastColumn mobilePrimaryKey="remarks" />
        <button className="button" disabled={locked || !rows.length || rows.length > 500} onClick={() => void beginSort()}>{t("编辑完整路由排序")}</button>
        {rows.length > 500 ? <p>{t("当前排序接口最多接受 500 条；不能以子集替代完整排序。")}</p> : null}</> : null}
    </section>
    {sortIds.length && !denied ? <section className="list-card network-sort-card"><div className="network-section-title"><h2>{t("完整路由排序")}</h2></div><p className="network-help">{t("拖动手柄或使用上移、下移调整完整列表，保存后按从上到下的顺序匹配。保存会核对完整集合修订，其他操作修改排序后必须重新读取。")}</p>{sortConflict ? <div role="alert"><p>{t("排序已变化，当前草稿已保留。")}</p><button className="button" disabled={locked} onClick={() => void beginSort()}>{t("重新读取完整排序")}</button></div> : null}<ol className="network-sort-list">{sortIds.map((id, index) => <li key={id} data-drop-target={dropTarget === id || undefined}
        onDragOver={event => { if (locked || !draggedRoute.current) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move'; setDropTarget(id) }}
        onDragLeave={event => { if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setDropTarget('') }}
        onDrop={event => { event.preventDefault(); if (draggedRoute.current) move(sortIds.indexOf(draggedRoute.current), index); draggedRoute.current = ''; setDropTarget('') }}><span className="network-sort-identity"><span className="network-drag-handle" draggable={!locked} title={t("拖动调整路由顺序")} aria-hidden="true"
        onDragStart={event => { if (locked || sortConflict) { event.preventDefault(); return }; draggedRoute.current = id; event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', id) }}
        onDragEnd={() => { draggedRoute.current = ''; setDropTarget('') }}><GripVertical size={17} /></span><small>{index + 1}</small><strong>{rows.find(row => row.id === id)?.remarks || '#' + id}</strong><code>#{id}</code></span><div className="network-sort-actions"><button className="button" disabled={locked || sortConflict || index === 0} onClick={() => move(index, index - 1)}>{t("上移")}</button><button className="button" disabled={locked || sortConflict || index === sortIds.length - 1} onClick={() => move(index, index + 1)}>{t("下移")}</button></div></li>)}</ol><div className="network-sort-footer"><button className="button" disabled={locked || sortConflict} onClick={() => void saveSort()}>{t("保存完整路由排序")}</button><button className="button" disabled={locked} onClick={() => { setSortIds([]); setSortRevision(''); setSortConflict(false); draggedRoute.current = ''; setDropTarget('') }}>{t("取消排序")}</button></div></section> : null}
    <Modal open={editing && !denied} title={draft.id ? t("编辑路由") : t("新增路由")} subtitle={t("路由按配置顺序匹配；动作参数只在指定 DNS 或代理转发时提交。")} className="network-reference-page network-editor-modal" onClose={closeEditor}
      footer={<><button className="button" type="button" disabled={state.busy} onClick={closeEditor}>{t("取消")}</button><button className="button" type="submit" form={formId} disabled={locked}>{t("保存路由")}</button></>}>
      <div className="network-editor-body">{error || operationError ? <p role="alert">{error ? t(error) : managementError(operationError)}</p> : null}
        {pending ? <div role="status"><p>{t("路由保存结果等待确认，将复用原配置和原请求编号。")}</p><button type="button" className="button" disabled={denied || state.busy || list.isFetching || list.isError} onClick={() => void perform(() => workflowActions.run(pending.operation, pending.input))}>{t("确认原路由操作")}</button></div> : null}
        {list.isError ? <p role="alert">{t("路由列表读取失败，请关闭编辑后重新加载。")}</p> : null}
        <form id={formId} className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}>
          <p className="network-editor-note">{t("请先填写匹配条件，再选择命中后的处理动作。DNS 与代理分别保留未保存的参数草稿。")}</p>
          <label>{t("备注")}<input data-autofocus placeholder={t("例如：流媒体直连")} required maxLength={255} value={draft.remarks} disabled={locked} onChange={event => setDraft({ ...draft, remarks: event.target.value })} /></label>
          <label>{t("匹配规则（每行一项，最多 200 项）")}<textarea aria-label={t("路由匹配规则")} placeholder={'example.com\n*.example.net\n203.0.113.0/24'} rows={6} required value={draft.match} disabled={locked} onChange={event => setDraft({ ...draft, match: event.target.value })} /></label>
          <label>{t("动作")}<select value={draft.action} disabled={locked} onChange={event => changeAction(event.target.value as Route['action'])}>{['direct', 'block', 'dns', 'proxy'].map(value => <option key={value} value={value}>{t(actionLabels[value as Route['action']])}</option>)}</select><small className="network-field-hint">{t(actionHints[draft.action])}</small></label>
          {['dns', 'proxy'].includes(draft.action) ? <label>{t("动作参数")}<input aria-label={draft.action === 'dns' ? t("DNS 地址") : t("代理参数")} placeholder={draft.action === 'dns' ? t("例如：1.1.1.1") : t("填写部署端支持的代理参数")} required maxLength={255} value={draft.actionValue} disabled={locked} onChange={event => setDraft({ ...draft, actionValue: event.target.value })} /></label> : null}
          <Checkbox label={t("启用")} checked={draft.enabled} disabled={locked} onChange={event => setDraft({ ...draft, enabled: event.target.checked })} />
        </form>
      </div>
    </Modal>
    {!denied ? <section className="list-card network-simulation-card"><div className="network-section-title"><Network size={18} /><h2>{t("只读路由模拟")}</h2><span className="network-tag is-info">{t("只读")}</span></div><p className="network-help">{t("选择已读取的节点并输入目标，检查当前规则的匹配顺序和最终动作。")}</p><ManagementFeedback error={nodes.error} loading={nodes.isFetching} onRetry={() => void nodes.refetch()} /><form className="native-content-form network-simulation-form" onSubmit={event => { event.preventDefault(); void simulate() }}><label>{t("已读取节点")}<select value={nodeId} disabled={nodes.isFetching || nodes.isError} onChange={event => { cancelSimulation(); setNodeId(event.target.value) }}><option value="">{t("选择当前页节点")}</option>{!nodes.isError ? nodes.data?.data.map(row => <option key={row.id} value={row.id}>{row.name} · #{row.id}</option>) : null}</select></label><div className="network-node-pagination"><button type="button" className="button" disabled={nodePage === 1 || nodes.isFetching} onClick={() => { cancelSimulation(); setNodeId(''); setNodePage(value => value - 1) }}>{t("上一页节点")}</button><button type="button" className="button" disabled={!nodes.data || nodes.isFetching || nodePage >= nodes.data.meta.last_page} onClick={() => { cancelSimulation(); setNodeId(''); setNodePage(value => value + 1) }}>{t("下一页节点")}</button></div><label className="network-form-field-wide">{t("目标")}<input placeholder={t("例如：example.com")} required maxLength={512} value={target} onChange={event => { cancelSimulation(); setTarget(event.target.value) }} /></label><div className="network-simulation-actions"><button className="button" data-primary="true" disabled={simulating || nodes.isFetching || nodes.isError || !nodeId || !target.trim()}>{t("运行只读模拟")}</button>{simulating ? <button className="button" type="button" onClick={cancelSimulation}>{t("取消模拟")}</button> : null}</div></form>
      {simulationError ? <p role="alert">{t(simulationError)}</p> : null}{simulation && !nodes.isError && !list.isError ? <div className="network-simulation-result" role="status"><p>{t("{name} · 请求目标{requested} · 归一化目标{normalized}", { name: simulation.node.name, requested: simulationTarget, normalized: simulation.target })}</p><p>{simulation.authoritative ? t("当前配置匹配结果") : t("非权威参考结果")} · {simulation.warning ? t(simulation.warning) : null}</p><p>{simulation.match ? t(actionLabels[simulation.match.action]) + ' · ' + (simulation.match.action_value ?? '') + ' · ' + simulation.match.pattern : t("未匹配路由")}</p><p>{t("已评估{routes}条路由；未解析{patterns}项。", { routes: simulation.evaluated_routes.length, patterns: simulation.unresolved_patterns.length })}</p><ol aria-label={t("路由模拟评估")}>{simulation.evaluated_routes.map(row => <li key={row.id}>{row.remarks} · {row.matched ? t("命中") : t("未命中")} · {t(actionLabels[row.action])} · {row.matched_pattern}</li>)}</ol>{simulation.unresolved_patterns.length ? <ul aria-label={t("未解析路由规则")}>{simulation.unresolved_patterns.map((pattern, index) => <li key={index}>{pattern}</li>)}</ul> : null}</div> : null}
    </section> : null}
  </div>
}
