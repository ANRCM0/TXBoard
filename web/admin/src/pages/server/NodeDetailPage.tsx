import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  Copy,
  Pencil,
  Trash2,
} from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { getAgentActions, getAgentFleetHealth } from '../../api/agent'
import {
  copyNode,
  deleteNode,
  getGroups,
  getMachines,
  getNodes,
  getProtocolDefinitions,
  getRoutes,
  saveNode,
  type NodeItem,
} from '../../api/server'
import { NodeEditorModal } from './NodeEditorModal'
import { PageHeader } from '../../components/ui/PageHeader'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

const GB = 1024 * 1024 * 1024

export function NodeDetailPage() {
  const navigate = useNavigate()
  const params = useParams()
  const qc = useQueryClient()
  const nodeId = Number(params.nodeId)
  const validNodeId = Number.isInteger(nodeId) && nodeId > 0
  const [editorOpen, setEditorOpen] = useState(false)

  const nodesQuery = useQuery({
    queryKey: ['nodes'],
    queryFn: getNodes,
    refetchInterval: 30_000,
    enabled: validNodeId,
  })
  const machinesQuery = useQuery({ queryKey: ['machines'], queryFn: getMachines, staleTime: 30_000 })
  const groupsQuery = useQuery({ queryKey: ['groups'], queryFn: getGroups, staleTime: 30_000 })
  const routesQuery = useQuery({ queryKey: ['routes'], queryFn: getRoutes, staleTime: 30_000 })
  const protocolsQuery = useQuery({ queryKey: ['protocol-definitions'], queryFn: getProtocolDefinitions, staleTime: 300_000, retry: 1 })
  const fleetQuery = useQuery({ queryKey: ['agentFleetHealth'], queryFn: getAgentFleetHealth, refetchInterval: 30_000, retry: 1 })
  const actionsQuery = useQuery({ queryKey: ['agentActions'], queryFn: () => getAgentActions(), refetchInterval: 15_000, retry: 1 })

  const nodes = Array.isArray(nodesQuery.data) ? nodesQuery.data : []
  const machines = Array.isArray(machinesQuery.data) ? machinesQuery.data : []
  const groups = Array.isArray(groupsQuery.data) ? groupsQuery.data : []
  const routes = Array.isArray(routesQuery.data) ? routesQuery.data : []
  const node = nodes.find(item => item.id === nodeId)
  const machine = machines.find(item => item.id === node?.machine_id)
  const parent = nodes.find(item => item.id === node?.parent_id)
  const childNodes = node ? nodes.filter(item => item.parent_id === node.id) : []
  const fleet = fleetQuery.data?.nodes.find(item => item.node_id === nodeId)
  const actions = (actionsQuery.data || []).filter(item => item.node_id === nodeId).slice(0, 8)

  const save = useMutation({
    mutationFn: (payload: Partial<NodeItem>) => saveNode(payload),
    onSuccess: async () => {
      toast.success('节点已更新')
      setEditorOpen(false)
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['nodes'] }),
        qc.invalidateQueries({ queryKey: ['agentFleetHealth'] }),
      ])
    },
  })

  const copy = useMutation({
    mutationFn: () => copyNode(nodeId),
    onSuccess: async id => {
      toast.success('节点已复制')
      await qc.invalidateQueries({ queryKey: ['nodes'] })
      navigate(`/server/node/${id}`)
    },
  })

  const remove = useMutation({
    mutationFn: () => deleteNode(nodeId),
    onSuccess: async () => {
      toast.success('节点已删除')
      await qc.invalidateQueries({ queryKey: ['nodes'] })
      navigate('/server/manage')
    },
  })

  if (!validNodeId) {
    return <div className="empty-state">无效的节点 ID。</div>
  }

  if (nodesQuery.isLoading) {
    return <QueryFeedback loading />
  }

  if (nodesQuery.isError || !node) {
    return <div className="entity-detail-error">
      <QueryFeedback error={nodesQuery.isError} onRetry={() => nodesQuery.refetch()} />
      {!nodesQuery.isError ? <div className="empty-state">未找到该节点。</div> : null}
      <button className="button" onClick={() => navigate('/server/manage')}><ArrowLeft size={15}/>返回节点管理</button>
    </div>
  }

  const groupNames = (node.group_ids || []).map(id => groups.find(item => item.id === id)?.name || `Group #${id}`)
  const routeNames = (node.route_ids || []).map(id => routes.find(item => item.id === id)?.remarks || `Route #${id}`)
  const used = Number(node.u || 0) + Number(node.d || 0)
  const total = Number(node.transfer_enable || 0)
  const protocolLabel = protocolsQuery.data?.find(item => item.type === node.type)?.label || normalizeProtocol(node.type)

  return <>
    <PageHeader
      title={node.name || `Node #${node.id}`}
      description={`Node #${node.id} · ${protocolLabel} · 节点详情中心`}
      action={<div className="actions">
        <button className="button" onClick={() => navigate('/server/manage')}><ArrowLeft size={15}/>返回节点管理</button>
        <button className="button" onClick={() => setEditorOpen(true)}><Pencil size={15}/>编辑节点</button>
        <button className="button" disabled={copy.isPending} onClick={() => copy.mutate()}><Copy size={15}/>{copy.isPending ? '复制中…' : '复制节点'}</button>
        <button
          className="button danger"
          disabled={remove.isPending}
          onClick={() => requestConfirm({
            title: '删除节点',
            message: `确认删除 ${node.name || `Node #${node.id}`}？删除后无法恢复。`,
            danger: true,
            confirmLabel: '删除节点',
            action: () => remove.mutate(),
          })}
        ><Trash2 size={15}/>{remove.isPending ? '删除中…' : '删除'}</button>
      </div>}
    />

    <div className="entity-detail-status-row">
      <span className={node.online ? 'status ok' : 'status off'}>{node.online ? '节点在线' : '节点离线'}</span>
      <span className={node.enabled === false ? 'status off' : 'status ok'}>{node.enabled === false ? '已停用' : '已启用'}</span>
      <span className={node.show ? 'badge' : 'badge muted-badge'}>{node.show ? '订阅可见' : '订阅隐藏'}</span>
      {fleet ? <span className={`badge fleet-${fleet.status}`}>Fleet {fleet.status}</span> : null}
    </div>

    <div className="entity-summary-grid">
      <Summary label="协议" value={protocolLabel} sub={node.type || '-'} />
      <Summary label="服务地址" value={addressOf(node)} sub={node.server_port ? `服务端口 ${node.server_port}` : '未配置独立服务端口'} />
      <Summary label="运行机器" value={machine?.name || (node.machine_id ? `Machine #${node.machine_id}` : '未绑定')} sub={machine ? machineStatusText(machine.last_seen_at, machine.is_active) : '机器模式未绑定'} />
      <Summary label="流量 / 倍率" value={total > 0 ? `${formatBytes(used)} / ${formatBytes(total)}` : formatBytes(used)} sub={`倍率 ×${Number(node.rate ?? 1)}`} />
    </div>

    <div className="entity-detail-two-col">
      <section className="card entity-detail-section">
        <div className="entity-detail-section-head">
          <div><h2>运行关系</h2><p>机器、父子节点、权限组与路由关系。</p></div>
        </div>
        <dl className="entity-meta-grid">
          <Meta label="运行机器" value={machine ? <Link className="table-link" to={`/server/machine/${machine.id}`}>{machine.name || `Machine #${machine.id}`}</Link> : '未绑定'} />
          <Meta label="父节点" value={parent ? <Link className="table-link" to={`/server/node/${parent.id}`}>{parent.name || `Node #${parent.id}`}</Link> : '无'} />
          <Meta label="子节点" value={childNodes.length ? childNodes.map((child, index) => <span key={child.id}>{index ? '、' : ''}<Link className="table-link" to={`/server/node/${child.id}`}>{child.name || `#${child.id}`}</Link></span>) : '无'} />
          <Meta label="权限组" value={groupNames.length ? groupNames.join('、') : '未限制'} />
          <Meta label="路由" value={routeNames.length ? routeNames.join('、') : '默认路由'} />
          <Meta label="标签" value={node.tags?.length ? node.tags.join('、') : '无'} />
          <Meta label="动态倍率" value={node.rate_time_enable ? '已启用' : '未启用'} />
          <Meta label="自定义代码" value={node.code || '无'} />
        </dl>
      </section>

      <section className="card entity-detail-section">
        <div className="entity-detail-section-head">
          <div><h2>Fleet Health</h2><p>来自 Agent Ops 的节点实时健康视图。</p></div>
          <Link className="button" to="/system/agent-ops">Agent 运维</Link>
        </div>
        <QueryFeedback loading={fleetQuery.isFetching && !fleetQuery.data} error={fleetQuery.isError} onRetry={() => fleetQuery.refetch()} />
        {fleet ? <dl className="entity-meta-grid">
          <Meta label="健康状态" value={fleet.status} />
          <Meta label="WebSocket" value={fleet.websocket ? 'online' : 'offline'} />
          <Meta label="Kernel" value={fleet.kernel_running == null ? 'unknown' : fleet.kernel_running ? 'running' : 'stopped'} />
          <Meta label="在线状态" value={fleet.online ? 'online' : 'offline'} />
          <Meta
            label="Warnings"
            wide
            value={fleet.warnings.length
              ? fleet.warnings.map(item => `${item.code} (${item.severity})`).join('、')
              : '无'}
          />
        </dl> : !fleetQuery.isError ? <div className="empty-state">Fleet Health 暂无该节点记录。</div> : null}
      </section>
    </div>

    <section className="card entity-detail-section">
      <div className="entity-detail-section-head">
        <div><h2>协议配置</h2><p>用于排查协议参数是否与入口、证书和节点端配置一致。敏感字段请仅在受信任环境查看。</p></div>
        <button className="button" onClick={() => setEditorOpen(true)}>进入结构化编辑器</button>
      </div>
      <details className="entity-sensitive-details">
        <summary>查看原始协议配置（可能包含密钥）</summary>
        <pre className="entity-json-block">{safeJson(node.protocol_settings)}</pre>
      </details>
    </section>

    <section className="card entity-detail-section">
      <div className="entity-detail-section-head">
        <div><h2>最近 Agent 动作</h2><p>最近对该节点发起的人工审批运维动作。</p></div>
        <Link className="button" to="/system/agent-ops">查看全部</Link>
      </div>
      <QueryFeedback loading={actionsQuery.isFetching && !actionsQuery.data} error={actionsQuery.isError} onRetry={() => actionsQuery.refetch()} />
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Request ID</th><th>动作</th><th>风险</th><th>状态</th><th>时间</th><th>结果</th></tr></thead>
          <tbody>
            {actions.map(action => <tr key={action.request_id}>
              <td><code>{action.request_id}</code></td>
              <td><code>{action.action}</code></td>
              <td><span className="badge">{action.risk_level}</span></td>
              <td><span className="badge">{action.status}</span></td>
              <td>{formatEpoch(action.created_at)}</td>
              <td>{action.error_code || compact(action.result) || '-'}</td>
            </tr>)}
            {!actions.length && !actionsQuery.isError ? <tr><td colSpan={6} className="empty-cell">{actionsQuery.isLoading ? '加载中…' : '暂无 Agent 动作'}</td></tr> : null}
          </tbody>
        </table>
      </div>
    </section>

    <NodeEditorModal
      open={editorOpen}
      node={node}
      nodes={nodes}
      machines={machines}
      groups={groups}
      routes={routes}
      protocolDefinitions={Array.isArray(protocolsQuery.data) ? protocolsQuery.data : []}
      saving={save.isPending}
      onClose={() => setEditorOpen(false)}
      onSubmit={payload => save.mutate(payload)}
    />
  </>
}

function Summary({ label, value, sub }: { label: string; value: string; sub: string }) {
  return <div className="card entity-summary-card"><span>{label}</span><strong>{value}</strong><small>{sub}</small></div>
}

function Meta({ label, value, wide = false }: { label: string; value: React.ReactNode; wide?: boolean }) {
  return <div className={wide ? 'entity-meta-wide' : undefined}><dt>{label}</dt><dd>{value}</dd></div>
}

function addressOf(node: NodeItem) {
  if (!node.host) return '未配置'
  return `${node.host}${node.port ? ':' + node.port : ''}`
}

function normalizeProtocol(value: unknown) {
  return value === 'hysteria' ? 'Hysteria2' : String(value || '-')
}

function formatBytes(value: number) {
  if (!Number.isFinite(value) || value <= 0) return '0 GB'
  const gb = value / GB
  if (gb >= 1024) return (gb / 1024).toFixed(2) + ' TB'
  return gb.toFixed(gb >= 10 ? 1 : 2) + ' GB'
}

function machineStatusText(lastSeen: unknown, active?: boolean) {
  if (active === false) return '机器已停用'
  const time = timestamp(lastSeen)
  return time !== null && Date.now() - time < 180_000 ? '机器在线' : '机器离线'
}

function timestamp(value: unknown) {
  if (typeof value === 'number' && Number.isFinite(value)) return value < 10_000_000_000 ? value * 1000 : value
  if (typeof value === 'string' && value) {
    const parsed = Date.parse(value)
    if (Number.isFinite(parsed)) return parsed
  }
  return null
}

function formatEpoch(value?: number | null) {
  if (!value) return '-'
  return new Date(value * 1000).toLocaleString()
}

function safeJson(value: unknown) {
  try {
    return JSON.stringify(value || {}, null, 2)
  } catch {
    return '{}'
  }
}

function compact(value: unknown) {
  if (!value) return ''
  const text = JSON.stringify(value)
  return text.length > 90 ? text.slice(0, 87) + '…' : text
}
