import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  Cable,
  Copy,
  KeyRound,
  Pencil,
  RotateCcw,
  Trash2,
} from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { getAgentActions, getAgentFleetHealth } from '../../api/agent'
import {
  deleteMachine,
  getMachineNodes,
  getMachineCredentials,
  getMachines,
  resetMachineToken,
  saveMachine,
  type MachineItem,
} from '../../api/server'
import { MachineHistoryChart } from '../../components/server/MachineHistoryChart'
import { MachineNodeBinding } from '../../components/server/MachineNodeBinding'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

export function MachineDetailPage() {
  const navigate = useNavigate()
  const params = useParams()
  const qc = useQueryClient()
  const machineId = Number(params.machineId)
  const validMachineId = Number.isInteger(machineId) && machineId > 0
  const [editorOpen, setEditorOpen] = useState(false)
  const [bindingOpen, setBindingOpen] = useState(false)
  const [credentials, setCredentials] = useState<{ token: string; command: string } | null>(null)
  const [credentialsLoading, setCredentialsLoading] = useState(false)
  const [name, setName] = useState('')
  const [notes, setNotes] = useState('')
  const [active, setActive] = useState(true)

  const machinesQuery = useQuery({
    queryKey: ['machines'],
    queryFn: getMachines,
    refetchInterval: 60_000,
    enabled: validMachineId,
  })
  const nodesQuery = useQuery({
    queryKey: ['machineNodes', machineId],
    queryFn: () => getMachineNodes(machineId),
    refetchInterval: 30_000,
    enabled: validMachineId,
  })
  const fleetQuery = useQuery({
    queryKey: ['agentFleetHealth'],
    queryFn: getAgentFleetHealth,
    refetchInterval: 30_000,
    retry: 1,
  })
  const actionsQuery = useQuery({
    queryKey: ['agentActions'],
    queryFn: () => getAgentActions(),
    refetchInterval: 15_000,
    retry: 1,
  })

  const machines = Array.isArray(machinesQuery.data) ? machinesQuery.data : []
  const machine = machines.find(item => item.id === machineId)
  const nodes = Array.isArray(nodesQuery.data) ? nodesQuery.data : []
  const nodeIds = new Set(nodes.map(item => item.id))
  const fleetNodes = (fleetQuery.data?.nodes || []).filter(item => nodeIds.has(item.node_id))
  const healthy = fleetNodes.filter(item => item.status === 'healthy').length
  const degraded = fleetNodes.filter(item => item.status === 'degraded').length
  const critical = fleetNodes.filter(item => item.status === 'critical').length
  const actions = (actionsQuery.data || []).filter(item => nodeIds.has(item.node_id)).slice(0, 8)

  const save = useMutation({
    mutationFn: () => saveMachine({ id: machineId, name, notes, is_active: active }),
    onSuccess: async () => {
      toast.success('机器已更新')
      setEditorOpen(false)
      await qc.invalidateQueries({ queryKey: ['machines'] })
    },
  })

  const resetToken = useMutation({
    mutationFn: () => resetMachineToken(machineId),
    onSuccess: async result => {
      toast.success('Machine Token 已重置，请保存新的凭据')
      setCredentials({ token: result.token, command: result.install_command })
      await qc.invalidateQueries({ queryKey: ['machines'] })
    },
  })

  const remove = useMutation({
    mutationFn: () => deleteMachine(machineId),
    onSuccess: async () => {
      toast.success('机器已删除，关联节点已解绑')
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['machines'] }),
        qc.invalidateQueries({ queryKey: ['nodes'] }),
      ])
      navigate('/server/machine')
    },
  })

  function openEditor() {
    if (!machine) return
    setName(machine.name || '')
    setNotes(String(machine.notes || ''))
    setActive(machine.is_active !== false)
    setEditorOpen(true)
  }

  async function showCredentials() {
    setCredentialsLoading(true)
    try {
      const result = await getMachineCredentials(machineId)
      setCredentials({ token: result.token, command: result.install_command })
    } catch {
      toast.error('无法读取机器凭据')
    } finally {
      setCredentialsLoading(false)
    }
  }

  async function copyText(value: string, label: string) {
    if (!value) return
    try {
      await navigator.clipboard.writeText(value)
      toast.success(`${label}已复制`)
    } catch {
      toast.error('复制失败')
    }
  }

  if (!validMachineId) {
    return <div className="empty-state">无效的机器 ID。</div>
  }

  if (machinesQuery.isLoading) {
    return <QueryFeedback loading />
  }

  if (machinesQuery.isError || !machine) {
    return <div className="entity-detail-error">
      <QueryFeedback error={machinesQuery.isError} onRetry={() => machinesQuery.refetch()} />
      {!machinesQuery.isError ? <div className="empty-state">未找到该机器。</div> : null}
      <button className="button" onClick={() => navigate('/server/machine')}><ArrowLeft size={15}/>返回机器管理</button>
    </div>
  }

  const online = machineOnline(machine)
  const cpu = finite(machine.load_status?.cpu)
  const mem = ratio(machine.load_status?.mem)
  const disk = ratio(machine.load_status?.disk)

  return <>
    <PageHeader
      title={machine.name || `Machine #${machine.id}`}
      description={`Machine #${machine.id} · 运行机器详情中心`}
      action={<div className="actions">
        <button className="button" onClick={() => navigate('/server/machine')}><ArrowLeft size={15}/>返回机器管理</button>
        <button className="button" onClick={openEditor}><Pencil size={15}/>编辑机器</button>
        <button className="button" disabled={credentialsLoading} onClick={() => void showCredentials()}><KeyRound size={15}/>{credentialsLoading ? '读取中…' : 'Token / 安装命令'}</button>
        <button className="button" onClick={() => setBindingOpen(true)}><Cable size={15}/>节点绑定</button>
        <button
          className="button danger"
          disabled={remove.isPending}
          onClick={() => requestConfirm({
            title: '删除机器',
            message: `确认删除 ${machine.name || `Machine #${machine.id}`}？当前绑定 ${nodes.length} 个节点，删除后这些节点会自动解绑。`,
            danger: true,
            confirmLabel: '删除机器',
            action: () => remove.mutate(),
          })}
        ><Trash2 size={15}/>{remove.isPending ? '删除中…' : '删除'}</button>
      </div>}
    />

    <div className="entity-detail-status-row">
      <span className={online ? 'status ok' : 'status off'}>{online ? '机器在线' : '机器离线'}</span>
      <span className={machine.is_active === false ? 'status off' : 'status ok'}>{machine.is_active === false ? '已停用' : '已启用'}</span>
      <span className="muted">最后心跳 {formatTime(machine.last_seen_at)}</span>
    </div>

    <div className="entity-summary-grid">
      <Summary label="绑定节点" value={String(nodes.length)} sub={nodes.length ? '点击下方节点进入详情' : '当前未绑定节点'} />
      <Summary label="CPU" value={cpu == null ? '—' : cpu.toFixed(1) + '%'} sub={loadFreshness(machine)} />
      <Summary label="内存" value={mem == null ? '—' : mem.toFixed(1) + '%'} sub={memoryText(machine)} />
      <Summary label="磁盘" value={disk == null ? '—' : disk.toFixed(1) + '%'} sub={diskText(machine)} />
    </div>

    <div className="entity-detail-two-col">
      <section className="card entity-detail-section">
        <div className="entity-detail-section-head">
          <div><h2>机器信息</h2><p>用于判断节点端进程是否连接到预期宿主机。</p></div>
        </div>
        <dl className="entity-meta-grid">
          <Meta label="机器 ID" value={String(machine.id)} />
          <Meta label="运行状态" value={online ? '在线' : '离线'} />
          <Meta label="启用状态" value={machine.is_active === false ? '停用' : '启用'} />
          <Meta label="最后心跳" value={formatTime(machine.last_seen_at)} />
          <Meta label="入站速率" value={formatRate(machine.load_status?.net?.in_speed)} />
          <Meta label="出站速率" value={formatRate(machine.load_status?.net?.out_speed)} />
          <Meta label="创建时间" value={formatTime(machine.created_at)} />
          <Meta label="更新时间" value={formatTime(machine.updated_at)} />
          <Meta label="备注" value={machine.notes || '无'} wide />
        </dl>
      </section>

      <section className="card entity-detail-section">
        <div className="entity-detail-section-head">
          <div><h2>Fleet Health</h2><p>汇总这台机器所承载节点的健康状态。</p></div>
          <Link className="button" to="/system/agent-ops">Agent 运维</Link>
        </div>
        <QueryFeedback loading={fleetQuery.isFetching && !fleetQuery.data} error={fleetQuery.isError} onRetry={() => fleetQuery.refetch()} />
        <div className="machine-fleet-summary">
          <div><span>Healthy</span><strong>{healthy}</strong></div>
          <div><span>Degraded</span><strong>{degraded}</strong></div>
          <div><span>Critical</span><strong>{critical}</strong></div>
          <div><span>未上报</span><strong>{Math.max(0, nodes.length - fleetNodes.length)}</strong></div>
        </div>
        {fleetNodes.some(item => item.warnings.length) ? (
          <div className="entity-warning-list">
            {fleetNodes.filter(item => item.warnings.length).map(item => (
              <div key={item.node_id}>
                <Link className="table-link" to={`/server/node/${item.node_id}`}>{item.name || `Node #${item.node_id}`}</Link>
                <span>{item.warnings.map(warning => `${warning.code} (${warning.severity})`).join('、')}</span>
              </div>
            ))}
          </div>
        ) : null}
      </section>
    </div>

    <section className="card entity-detail-section">
      <div className="entity-detail-section-head">
        <div><h2>绑定节点</h2><p>机器是宿主环境，节点是面向用户的协议服务；从这里直接进入对应节点排障。</p></div>
        <button className="button" onClick={() => setBindingOpen(true)}>管理绑定</button>
      </div>
      <QueryFeedback loading={nodesQuery.isFetching && !nodesQuery.data} error={nodesQuery.isError} onRetry={() => nodesQuery.refetch()} />
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>节点</th><th>协议</th><th>地址</th><th>启用</th><th>订阅</th><th>Fleet</th></tr></thead>
          <tbody>
            {nodes.map(node => {
              const finding = fleetNodes.find(item => item.node_id === node.id)
              return <tr key={node.id}>
                <td><Link className="table-link" to={`/server/node/${node.id}`}><strong>{node.name || `Node #${node.id}`}</strong></Link></td>
                <td><span className="badge">{node.type === 'hysteria' ? 'hysteria2' : node.type || '-'}</span></td>
                <td>{node.host ? `${node.host}${node.port ? ':' + node.port : ''}` : '-'}</td>
                <td><span className={node.enabled === false ? 'status off' : 'status ok'}>{node.enabled === false ? '停用' : '启用'}</span></td>
                <td>{node.show ? '显示' : '隐藏'}</td>
                <td>{finding ? <span className={`badge fleet-${finding.status}`}>{finding.status}</span> : '-'}</td>
              </tr>
            })}
            {!nodes.length && !nodesQuery.isError ? <tr><td colSpan={6} className="empty-cell">{nodesQuery.isLoading ? '加载中…' : '该机器尚未绑定节点'}</td></tr> : null}
          </tbody>
        </table>
      </div>
    </section>

    <section className="card entity-detail-section">
      <MachineHistoryChart machineId={machineId} />
    </section>

    <section className="card entity-detail-section">
      <div className="entity-detail-section-head">
        <div><h2>最近 Agent 动作</h2><p>汇总这台机器上所有节点最近的运维动作。</p></div>
        <Link className="button" to="/system/agent-ops">查看全部</Link>
      </div>
      <QueryFeedback loading={actionsQuery.isFetching && !actionsQuery.data} error={actionsQuery.isError} onRetry={() => actionsQuery.refetch()} />
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>节点</th><th>动作</th><th>风险</th><th>状态</th><th>时间</th><th>结果</th></tr></thead>
          <tbody>
            {actions.map(action => {
              const node = nodes.find(item => item.id === action.node_id)
              return <tr key={action.request_id}>
                <td><Link className="table-link" to={`/server/node/${action.node_id}`}>{node?.name || `Node #${action.node_id}`}</Link></td>
                <td><code>{action.action}</code></td>
                <td><span className="badge">{action.risk_level}</span></td>
                <td><span className="badge">{action.status}</span></td>
                <td>{formatEpoch(action.created_at)}</td>
                <td>{action.error_code || compact(action.result) || '-'}</td>
              </tr>
            })}
            {!actions.length && !actionsQuery.isError ? <tr><td colSpan={6} className="empty-cell">{actionsQuery.isLoading ? '加载中…' : '暂无 Agent 动作'}</td></tr> : null}
          </tbody>
        </table>
      </div>
    </section>

    <Modal open={editorOpen} title="编辑机器" onClose={() => setEditorOpen(false)}>
      <div className="form-stack">
        <label className="field"><span>机器名称</span><input value={name} onChange={event => setName(event.target.value)} /></label>
        <label className="field"><span>备注</span><textarea value={notes} onChange={event => setNotes(event.target.value)} /></label>
        <label className="config-switch-field">
          <div><strong>启用机器</strong><small>停用后机器仍保留配置，但不参与正常调度。</small></div>
          <button type="button" role="switch" aria-checked={active} className={`config-switch ${active ? 'active' : ''}`} onClick={() => setActive(value => !value)}><span /></button>
        </label>
        <div className="card-actions">
          <button className="button" onClick={() => setEditorOpen(false)}>取消</button>
          <button className="button primary" disabled={!name.trim() || save.isPending} onClick={() => save.mutate()}>{save.isPending ? '保存中…' : '保存机器'}</button>
        </div>
      </div>
    </Modal>

    <Modal open={bindingOpen} title={`节点绑定：${machine.name || machine.id}`} onClose={() => setBindingOpen(false)} wide>
      <MachineNodeBinding machineId={machineId} />
    </Modal>

    <Modal open={Boolean(credentials)} title="机器 Token / 安装命令" onClose={() => setCredentials(null)}>
      <div className="form-stack">
        <div className="page-alert">Machine Token 属于高敏感凭据。仅在受信任环境中查看和复制；重置 Token 后旧节点进程需要重新配置。</div>
        <label className="field"><span>Machine Token</span><pre className="code-block">{credentials?.token || '-'}</pre></label>
        <label className="field"><span>一键安装命令</span><pre className="code-block">{credentials?.command || '-'}</pre></label>
        <div className="card-actions">
          <button className="button" onClick={() => void copyText(credentials?.token || '', 'Token')}><Copy size={15}/>复制 Token</button>
          <button className="button primary" onClick={() => void copyText(credentials?.command || '', '安装命令')}><Copy size={15}/>复制安装命令</button>
          <button
            className="button danger"
            disabled={resetToken.isPending}
            onClick={() => requestConfirm({
              title: '重置 Machine Token',
              message: `确认重置 ${machine.name || `Machine #${machine.id}`} 的 Token？旧 Token 会立即失效，节点端需要重新配置。`,
              danger: true,
              confirmLabel: '重置 Token',
              action: () => resetToken.mutate(),
            })}
          ><RotateCcw size={15}/>{resetToken.isPending ? '重置中…' : '重置 Token'}</button>
        </div>
      </div>
    </Modal>
  </>
}

function Summary({ label, value, sub }: { label: string; value: string; sub: string }) {
  return <div className="card entity-summary-card"><span>{label}</span><strong>{value}</strong><small>{sub}</small></div>
}

function Meta({ label, value, wide = false }: { label: string; value: React.ReactNode; wide?: boolean }) {
  return <div className={wide ? 'entity-meta-wide' : undefined}><dt>{label}</dt><dd>{value}</dd></div>
}

function machineOnline(machine: MachineItem) {
  if (machine.is_active === false) return false
  const last = timestamp(machine.last_seen_at)
  return last !== null && Date.now() - last < 180_000
}

function finite(value: unknown) {
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

function ratio(value?: { total?: number; used?: number }) {
  const total = finite(value?.total)
  const used = finite(value?.used)
  if (total == null || used == null || total <= 0) return null
  return used / total * 100
}

function bytes(value: unknown) {
  const n = Number(value)
  if (!Number.isFinite(n) || n <= 0) return '—'
  const gb = n / 1073741824
  return gb >= 1024 ? `${(gb / 1024).toFixed(2)} TB` : `${gb.toFixed(gb >= 10 ? 1 : 2)} GB`
}

function memoryText(machine: MachineItem) {
  return machine.load_status?.mem ? `${bytes(machine.load_status.mem.used)} / ${bytes(machine.load_status.mem.total)}` : '暂无内存数据'
}

function diskText(machine: MachineItem) {
  return machine.load_status?.disk ? `${bytes(machine.load_status.disk.used)} / ${bytes(machine.load_status.disk.total)}` : '暂无磁盘数据'
}

function loadFreshness(machine: MachineItem) {
  return machine.load_status?.updated_at ? `采样于 ${formatTime(machine.load_status.updated_at)}` : '实时心跳负载'
}

function formatRate(value: unknown) {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 0) return '—'
  if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(2)} MB/s`
  if (n >= 1024) return `${(n / 1024).toFixed(1)} KB/s`
  return `${n.toFixed(0)} B/s`
}

function timestamp(value: unknown) {
  if (typeof value === 'number' && Number.isFinite(value)) return value < 10_000_000_000 ? value * 1000 : value
  if (typeof value === 'string' && value) {
    const parsed = Date.parse(value)
    if (Number.isFinite(parsed)) return parsed
  }
  return null
}

function formatTime(value: unknown) {
  const time = timestamp(value)
  return time === null ? '-' : new Date(time).toLocaleString()
}

function formatEpoch(value?: number | null) {
  if (!value) return '-'
  return new Date(value * 1000).toLocaleString()
}

function compact(value: unknown) {
  if (!value) return ''
  const text = JSON.stringify(value)
  return text.length > 90 ? text.slice(0, 87) + '…' : text
}
