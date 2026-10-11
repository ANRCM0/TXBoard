import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Copy, Eye, EyeOff, Pencil, Trash2 } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { getAgentActions, getAgentFleetHealth } from '../../api/agent'
import {
  copyNode, deleteNode, getGroups, getMachines, getNodes, getProtocolDefinitions,
  getRoutes, saveNode, type NodeItem,
} from '../../api/server'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { EmptyState } from '../../components/ui/EmptyState'
import { PageHeader } from '../../components/ui/PageHeader'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { SectionHeader } from '../../components/ui/SectionHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { NodeEditorModal } from './NodeEditorModal'
import {
  nodeDetailAddress, nodeDetailMachineStatus, nodeDetailPresence, nodeDetailTraffic,
  redactActionResult,
} from './nodeDetailPresentation'
import './NodeDetailPage.css'

export function NodeDetailPage() {
  const navigate = useNavigate()
  const params = useParams()
  const qc = useQueryClient()
  const nodeId = Number(params.nodeId)
  const validNodeId = Number.isSafeInteger(nodeId) && nodeId > 0
  const [editorOpen, setEditorOpen] = useState(false)
  const [rawVisible, setRawVisible] = useState(false)

  useEffect(() => { setRawVisible(false) }, [nodeId])
  useEffect(() => {
    if (!rawVisible) return
    const timeout = window.setTimeout(() => setRawVisible(false), 18_000)
    return () => window.clearTimeout(timeout)
  }, [rawVisible])

  const nodesQuery = useQuery({
    queryKey: ['nodes'], queryFn: getNodes, refetchInterval: 30_000, enabled: validNodeId,
  })
  const machinesQuery = useQuery({
    queryKey: ['machines'], queryFn: getMachines, staleTime: 30_000, enabled: validNodeId,
  })
  const groupsQuery = useQuery({
    queryKey: ['groups'], queryFn: getGroups, staleTime: 30_000, enabled: validNodeId,
  })
  const routesQuery = useQuery({
    queryKey: ['routes'], queryFn: getRoutes, staleTime: 30_000, enabled: validNodeId,
  })
  const protocolsQuery = useQuery({
    queryKey: ['protocol-definitions'], queryFn: getProtocolDefinitions,
    staleTime: 300_000, retry: 1, enabled: validNodeId,
  })
  const fleetQuery = useQuery({
    queryKey: ['agentFleetHealth'], queryFn: getAgentFleetHealth,
    refetchInterval: 30_000, retry: 1, enabled: validNodeId,
  })
  const actionsQuery = useQuery({
    queryKey: ['agentActions'], queryFn: () => getAgentActions(),
    refetchInterval: 15_000, retry: 1, enabled: validNodeId,
  })

  const nodes = Array.isArray(nodesQuery.data) ? nodesQuery.data : []
  const machines = Array.isArray(machinesQuery.data) ? machinesQuery.data : []
  const groups = Array.isArray(groupsQuery.data) ? groupsQuery.data : []
  const routes = Array.isArray(routesQuery.data) ? routesQuery.data : []
  const node = nodes.find(item => item.id === nodeId)
  const machine = machines.find(item => item.id === node?.machine_id)
  const parent = nodes.find(item => item.id === node?.parent_id)
  const children = node ? nodes.filter(item => item.parent_id === node.id) : []
  const fleet = fleetQuery.data?.nodes.find(item => item.node_id === nodeId)
  const actions = (actionsQuery.data || []).filter(item => item.node_id === nodeId).slice(0,8)

  const save = useMutation({
    mutationFn: (payload: Partial<NodeItem>) => saveNode(payload),
    onSuccess: async () => {
      toast.success('节点已更新')
      setEditorOpen(false)
      setRawVisible(false)
      await Promise.all([
        qc.invalidateQueries({queryKey:['nodes']}),
        qc.invalidateQueries({queryKey:['agentFleetHealth']}),
      ])
    },
  })
  const copy = useMutation({
    mutationFn: () => copyNode(nodeId),
    onSuccess: async id => {
      toast.success('节点已复制')
      setRawVisible(false)
      await qc.invalidateQueries({queryKey:['nodes']})
      navigate(`/server/node/${id}`)
    },
  })
  const remove = useMutation({
    mutationFn: () => deleteNode(nodeId),
    onSuccess: async () => {
      toast.success('节点已删除')
      setRawVisible(false)
      await qc.invalidateQueries({queryKey:['nodes']})
      navigate('/server/manage')
    },
  })

  if (!validNodeId) return <div className="entity-detail-error">
    <EmptyState icon={<ArrowLeft size={21}/>} title="节点 ID 无效"
      description="请从节点列表选择有效的节点。"
      action={<Link className="button" to="/server/manage">返回节点管理</Link>}/>
  </div>
  if (nodesQuery.isLoading) return <QueryFeedback loading/>
  if (!node) return <div className="entity-detail-error">
    <QueryFeedback error={nodesQuery.isError} onRetry={() => void nodesQuery.refetch()}/>
    {!nodesQuery.isError ? <EmptyState icon={<ArrowLeft size={21}/>} title="未找到该节点"
      description="可能已被删除或不再属于当前管理范围。"
      action={<Link className="button" to="/server/manage">返回节点管理</Link>}/> : null}
    {nodesQuery.isError ? <Link className="button" to="/server/manage">返回节点管理</Link> : null}
  </div>

  const protocolLabel = protocolsQuery.data?.find(item => item.type === node.type)?.label ||
    (node.type === 'hysteria' ? 'Hysteria2' : node.type || '未知协议')
  const groupNames = (node.group_ids || []).map(id => groups.find(item => item.id === id)?.name || `Group #${id}`)
  const routeNames = (node.route_ids || []).map(id => routes.find(item => item.id === id)?.remarks || `Route #${id}`)
  const presence = nodeDetailPresence(node)
  const traffic = nodeDetailTraffic(node)

  const editor = () => { setRawVisible(false); setEditorOpen(true) }
  const actionColumns: Column<(typeof actions)[number]>[] = [
    { key:'request', header:'请求 ID', render:a => <code>{a.request_id}</code> },
    { key:'name', header:'动作', render:a => <span className="node-detail-v3-code">{a.action}</span> },
    { key:'risk', header:'风险', render:a => <span className="node-detail-v3-muted">{a.risk_level || '—'}</span> },
    { key:'state', header:'状态', render:a => <span>{a.status || '未知'}</span> },
    { key:'time', header:'时间', render:a => formatEpoch(a.created_at) },
    { key:'result', header:'结果', render:a => <span className="node-detail-v3-result">
      {a.error_code || redactActionResult(a.result)}
    </span> },
  ]

  return <div className="node-detail-v3">
    <PageHeader
      title={node.name || `Node #${node.id}`}
      description={`Node #${node.id} · ${protocolLabel} · 节点详情`}
      action={<div className="actions">
        <Link className="button" to="/server/manage"><ArrowLeft size={15}/>返回节点</Link>
        <button type="button" className="button" onClick={editor}><Pencil size={15}/>编辑</button>
        <button type="button" className="button" disabled={copy.isPending}
          onClick={() => copy.mutate()}><Copy size={15}/>{copy.isPending ? '复制中…' : '复制节点'}</button>
        <button type="button" className="button danger" disabled={remove.isPending}
          onClick={() => requestConfirm({
            title:'删除节点',message:`确认删除 ${node.name || `Node #${node.id}`}？删除后无法恢复。`,
            danger:true,confirmLabel:'删除节点',action:()=>remove.mutate(),
          })}><Trash2 size={15}/>{remove.isPending ? '删除中…' : '删除'}</button>
      </div>}
    />
    <QueryFeedback error={nodesQuery.isError} onRetry={() => void nodesQuery.refetch()}/>
    <div className="node-detail-v3-presence" aria-label="节点状态">
      <span className={'node-detail-v3-state is-'+presence.tone}>
        <i aria-hidden="true"/>{presence.label}
      </span>
      <span className="node-detail-v3-separator">·</span>
      <span className="node-detail-v3-secondary">
        {node.show === undefined || node.show === null ? '订阅可见性未上报'
          : node.show ? '订阅可见' : '订阅隐藏'}
      </span>
      {fleet ? <><span className="node-detail-v3-separator">·</span>
        <span className={'node-detail-v3-fleet-status is-'+fleet.status}>
          <i aria-hidden="true"/>Fleet {fleetLabel(fleet.status)}
        </span>
      </> : null}
    </div>

    <div className="node-detail-v3-summary" aria-label="节点核心信息">
      <Summary label="协议" value={protocolLabel} sub={String(node.type || '未设置')}/>
      <Summary label="服务地址" value={nodeDetailAddress(node)}
        sub={node.server_port ? '服务端口 '+node.server_port : '未设置独立服务端口'}/>
      <Summary label="运行机器"
        value={machine
          ? <Link className="table-link" to={`/server/machine/${machine.id}`}>{machine.name || `Machine #${machine.id}`}</Link>
          : node.machine_id ? `Machine #${node.machine_id}` : '未绑定'}
        sub={node.machine_id && !machine && machinesQuery.isLoading
          ? '正在加载机器数据…' : nodeDetailMachineStatus(machine)}/>
      <Summary label="流量使用" value={traffic.value}
        sub={traffic.sub+' · 倍率 ×'+(node.rate ?? 1)}/>
    </div>

    <div className="node-detail-v3-main">
      <section className="node-detail-v3-section">
        <SectionHeader title="运行关系" description="机器、节点层级、权限与路由配置。"/>
        <dl className="node-detail-v3-facts">
          <Meta label="运行机器" value={machine
            ? <Link className="table-link" to={`/server/machine/${machine.id}`}>{machine.name || `Machine #${machine.id}`}</Link>
            : node.machine_id ? `Machine #${node.machine_id}` : '未绑定'}/>
          <Meta label="父节点" value={parent
            ? <Link className="table-link" to={`/server/node/${parent.id}`}>{parent.name || `Node #${parent.id}`}</Link>
            : '无'}/>
          <Meta label="子节点" wide value={children.length ? children.map((child,i)=><span key={child.id}>
            {i ? '、' : ''}<Link className="table-link" to={`/server/node/${child.id}`}>{child.name || `#${child.id}`}</Link>
          </span>) : '无'}/>
          <Meta label="权限组" value={groupNames.length ? groupNames.join('、') : '未限制'}/>
          <Meta label="路由" value={routeNames.length ? routeNames.join('、') : '默认路由'}/>
          <Meta label="标签" wide value={node.tags?.length ? node.tags.join('、') : '无'}/>
          <Meta label="动态倍率" value={node.rate_time_enable ? '已启用' : '未启用'}/>
          <Meta label="自定义代码" value={node.code || '无'}/>
        </dl>
      </section>
      <section className="node-detail-v3-section">
        <SectionHeader title="Fleet 健康" description="来自 Agent Ops 的节点健康报告。"
          action={<Link className="button" to="/system/agent-ops">Agent 运维</Link>}/>
        <QueryFeedback loading={fleetQuery.isLoading} error={fleetQuery.isError}
          onRetry={() => void fleetQuery.refetch()}/>
        {fleet ? <dl className="node-detail-v3-facts">
          <Meta label="健康状态" value={<span className={'node-detail-v3-fleet-status is-'+fleet.status}>
            <i aria-hidden="true"/>{fleetLabel(fleet.status)}</span>}/>
          <Meta label="Agent 在线" value={fleet.online ? '在线' : '离线'}/>
          <Meta label="WebSocket" value={fleet.websocket ? '已连接' : '未连接'}/>
          <Meta label="Kernel" value={fleet.kernel_running == null ? '未上报' : fleet.kernel_running ? '运行中' : '已停止'}/>
          <Meta label="告警" wide value={fleet.warnings?.length
            ? fleet.warnings.map(w => `${w.code}（${w.severity}）`).join('、') : '无'}/>
        </dl> : !fleetQuery.isLoading && !fleetQuery.isError
          ? <div className="node-detail-v3-empty"><p>Fleet 暂无此节点的健康记录。</p></div> : null}
      </section>
    </div>

    <section className="node-detail-v3-section node-detail-v3-actions">
      <SectionHeader title="协议配置" description="结构化编辑协议字段与服务端参数。"
        action={<button type="button" className="button" onClick={editor}>打开协议编辑器</button>}/>
      <div className="node-detail-v3-sensitive">
        <p>原始配置可能包含私钥、口令或 Token。只在可信任的环境中临时查看，18 秒后自动隐藏。</p>
        <div className="node-detail-v3-sensitive-actions">
          <button type="button" className="button" aria-expanded={rawVisible}
            onClick={() => setRawVisible(show => !show)}>
            {rawVisible ? <EyeOff size={15}/> : <Eye size={15}/>}
            {rawVisible ? '立即隐藏原始配置' : '临时查看原始配置'}
          </button>
          {rawVisible ? <span className="node-detail-v3-muted" role="status">18 秒后自动隐藏</span> : null}
        </div>
        {rawVisible ? <pre className="entity-json-block" data-testid="node-raw-config">
          {safeJson(node.protocol_settings)}
        </pre> : null}
      </div>
    </section>

    <section className="node-detail-v3-section node-detail-v3-actions">
      <SectionHeader title="最近 Agent 动作" description="最近针对该节点发起的审批或运维请求。"
        action={<Link className="button" to="/system/agent-ops">查看全部</Link>}/>
      <DataTable label="最近 Agent 动作" rows={actions} columns={actionColumns}
        rowKey={row=>row.request_id}
        loading={actionsQuery.isLoading} error={actionsQuery.isError}
        onRetry={() => void actionsQuery.refetch()}
        emptyState={<EmptyState icon={<ArrowLeft size={20}/>}
          title="暂无 Agent 动作" description="这个节点目前没有记录到审批或运维动作。"
          action={<Link className="button" to="/system/agent-ops">前往 Agent 运维</Link>}/>}/>
    </section>

    <NodeEditorModal open={editorOpen} node={node} nodes={nodes} machines={machines}
      groups={groups} routes={routes}
      protocolDefinitions={Array.isArray(protocolsQuery.data) ? protocolsQuery.data : []}
      saving={save.isPending} onClose={() => setEditorOpen(false)}
      onSubmit={payload => save.mutate(payload)}/>
  </div>
}

function Summary({label,value,sub}: {label:string;value:ReactNode;sub:string}) {
  return <div><span>{label}</span><strong>{value}</strong><small>{sub}</small></div>
}
function Meta({label,value,wide=false}: {label:string;value:ReactNode;wide?:boolean}) {
  return <div className={wide ? 'is-wide' : undefined}><dt>{label}</dt><dd>{value}</dd></div>
}
function fleetLabel(value:string) {
  return value==='healthy' ? '正常' : value==='degraded' ? '需关注' : value==='critical' ? '严重' : value
}
function formatEpoch(value:number|null|undefined) {
  if (value == null || !Number.isFinite(value) || value<=0) return '—'
  const date = new Date(value*1000)
  return Number.isFinite(date.getTime()) ? date.toLocaleString('zh-CN') : '—'
}
function safeJson(value:unknown) {
  try { return JSON.stringify(value ?? {},null,2) ?? '{}' } catch { return '{}' }
}
