import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Cable, KeyRound, Pencil, Trash2, Server } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { getAgentActions, getAgentFleetHealth } from '../../api/agent'
import { deleteMachine, getMachineNodes, getMachines, saveMachine, type MachineItem, type NodeItem } from '../../api/server'
import { MachineHistoryChart } from '../../components/server/MachineHistoryChart'
import { MachineNodeBinding } from '../../components/server/MachineNodeBinding'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { EmptyState } from '../../components/ui/EmptyState'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { SectionHeader } from '../../components/ui/SectionHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { MachineCredentialsDialog } from './MachineCredentialsDialog'
import {
  machineActionSummary, machineDetailAddress, machineDetailPercent,
  machineDetailPresence, machineDetailRate, machineDetailRatio,
  machineDetailSize, machineDetailTime,
} from './machineDetailPresentation'
import './MachineDetailPage.css'

export function MachineDetailPage() {
  const navigate = useNavigate()
  const params = useParams()
  const qc = useQueryClient()
  const machineId = Number(params.machineId)
  const validMachineId = Number.isSafeInteger(machineId) && machineId > 0
  const [editorOpen, setEditorOpen] = useState(false)
  const [bindingOpen, setBindingOpen] = useState(false)
  const [credentialsFor, setCredentialsFor] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [notes, setNotes] = useState('')
  const [active, setActive] = useState(true)
  const [imageChannel, setImageChannel] = useState<'stable' | 'dev'>('stable')

  const machinesQuery = useQuery({
    queryKey: ['machines'], queryFn: getMachines, refetchInterval: 60_000, enabled: validMachineId,
  })
  const nodesQuery = useQuery({
    queryKey: ['machineNodes',machineId], queryFn: () => getMachineNodes(machineId),
    refetchInterval: 30_000, enabled: validMachineId,
  })
  const fleetQuery = useQuery({
    queryKey: ['agentFleetHealth'], queryFn: getAgentFleetHealth,
    refetchInterval: 30_000, retry: 1, enabled: validMachineId,
  })
  const actionsQuery = useQuery({
    queryKey: ['agentActions'], queryFn: () => getAgentActions(),
    refetchInterval: 15_000, retry: 1, enabled: validMachineId,
  })

  const machines = Array.isArray(machinesQuery.data) ? machinesQuery.data : []
  const machine = machines.find(item => item.id === machineId)
  const nodes = Array.isArray(nodesQuery.data) ? nodesQuery.data : []
  const nodeIds = new Set(nodes.map(item => item.id))
  const fleetNodes = (fleetQuery.data?.nodes || []).filter(item => nodeIds.has(item.node_id))
  const healthy = fleetNodes.filter(item => item.status === 'healthy').length
  const degraded = fleetNodes.filter(item => item.status === 'degraded').length
  const critical = fleetNodes.filter(item => item.status === 'critical').length
  const actions = (actionsQuery.data || []).filter(item => nodeIds.has(item.node_id)).slice(0,8)

  const save=useMutation({
    mutationFn: () => saveMachine({id:machineId,name,notes,is_active:active,image_channel:imageChannel}),
    onSuccess: async () => {
      toast.success('机器已更新')
      setEditorOpen(false)
      await qc.invalidateQueries({queryKey:['machines']})
    },
  })
  const remove=useMutation({
    mutationFn: () => deleteMachine(machineId),
    onSuccess: async () => {
      toast.success('机器已删除，关联节点已解绑')
      setCredentialsFor(null)
      await Promise.all([
        qc.invalidateQueries({queryKey:['machines']}),
        qc.invalidateQueries({queryKey:['nodes']}),
      ])
      navigate('/server/machine')
    },
  })

  function openEditor() {
    if (!machine) return
    setCredentialsFor(null)
    setName(machine.name || '')
    setNotes(String(machine.notes || ''))
    setActive(machine.is_active !== false)
    setImageChannel(machine.image_channel === 'dev' ? 'dev' : 'stable')
    setEditorOpen(true)
  }

  if (!validMachineId) return <div className="entity-detail-error">
    <EmptyState icon={<Server size={22}/>} title="机器 ID 无效"
      description="请从服务器列表进入有效的机器。"
      action={<Link className="button" to="/server/machine">返回服务器管理</Link>}/>
  </div>
  if (machinesQuery.isLoading) return <QueryFeedback loading/>
  if (!machine) return <div className="entity-detail-error">
    <QueryFeedback error={machinesQuery.isError} onRetry={()=>void machinesQuery.refetch()}/>
    {!machinesQuery.isError ? <EmptyState icon={<Server size={22}/>}
      title="未找到该机器" description="机器可能已删除或不再属于当前管理范围。"
      action={<Link className="button" to="/server/machine">返回服务器管理</Link>}/> :
      <Link className="button" to="/server/machine">返回服务器管理</Link>}
  </div>

  const presence = machineDetailPresence(machine)
  const cpu = machineDetailPercent(machine.load_status?.cpu)
  const mem = machineDetailRatio(machine.load_status?.mem)
  const disk = machineDetailRatio(machine.load_status?.disk)

  const nodeColumns:Column<NodeItem>[]=[
    {key:'node',header:'节点',width:'25%',render:node=><Link className="table-link"
      to={`/server/node/${node.id}`}><strong>{node.name || `Node #${node.id}`}</strong></Link>},
    {key:'protocol',header:'协议',render:node=><span className="muted">
      {node.type==='hysteria'?'hysteria2':node.type || '未知'}</span>},
    {key:'host',header:'地址',render:node=><span className="machine-detail-v3-result">
      {machineDetailAddress(node)}</span>},
    {key:'enabled',header:'运行',render:node=><span>
      {node.enabled===false?'已停用':node.enabled===undefined?'未上报':'已启用'}</span>},
    {key:'show',header:'订阅',render:node=><span className="muted">
      {node.show==null?'未知':node.show?'可见':'隐藏'}</span>},
    {key:'fleet',header:'健康',render:node=>{
      const finding=fleetNodes.find(item=>item.node_id===node.id)
      return <span>{finding ? (finding.status==='healthy'?'正常':
        finding.status==='degraded'?'需关注':'严重'):'未上报'}</span>
    }},
  ]
  const actionColumns:Column<(typeof actions)[number]>[]=[
    {key:'node',header:'节点',render:item=>{
      const target=nodes.find(node=>node.id===item.node_id)
      return <Link className="table-link" to={`/server/node/${item.node_id}`}>
        {target?.name || `Node #${item.node_id}`}</Link>
    }},
    {key:'action',header:'动作',render:item=><code>{item.action}</code>},
    {key:'risk',header:'风险',render:item=><span className="muted">{item.risk_level || '—'}</span>},
    {key:'state',header:'状态',render:item=><span>{item.status || '未知'}</span>},
    {key:'time',header:'时间',render:item=>machineDetailTime(item.created_at ? item.created_at*1000 : null)},
    {key:'result',header:'结果',render:item=><span className="machine-detail-v3-result">
      {item.error_code || machineActionSummary(item.result)}</span>},
  ]

  return <div className="machine-detail-v3">
    <PageHeader title={machine.name || `Machine #${machine.id}`}
      description={`Machine #${machine.id} · 服务器详情`}
      action={<div className="actions">
        <Link className="button" to="/server/machine"><ArrowLeft size={15}/>返回服务器</Link>
        <button type="button" className="button" onClick={openEditor}><Pencil size={15}/>编辑</button>
        <button type="button" className="button" onClick={()=>setCredentialsFor(machineId)}>
          <KeyRound size={15}/>Token / 安装命令</button>
        <button type="button" className="button" onClick={()=>setBindingOpen(true)}>
          <Cable size={15}/>节点绑定</button>
        <button type="button" className="button danger" disabled={remove.isPending}
          onClick={()=>requestConfirm({
            title:'删除机器',
            message:`确认删除 ${machine.name || `Machine #${machine.id}`}？当前绑定 ${nodes.length} 个节点，删除后将自动解绑。`,
            danger:true,confirmLabel:'删除机器',action:()=>remove.mutate(),
          })}><Trash2 size={15}/>{remove.isPending?'删除中…':'删除'}</button>
      </div>}/>
    <QueryFeedback error={machinesQuery.isError} onRetry={()=>void machinesQuery.refetch()}/>
    <div className="machine-detail-v3-presence" aria-label="机器连接状态">
      <span className={'is-'+presence.tone}><i aria-hidden="true"/>{presence.label}</span>
      <span>·</span><span>最后心跳 {machineDetailTime(machine.last_seen_at)}</span>
      <span>·</span><span>{machine.image_channel==='dev'?'开发版 · dev':'稳定版 · latest'}</span>
    </div>
    <div className="machine-detail-v3-summary" aria-label="机器核心指标">
      <Summary label="绑定节点" value={nodesQuery.isLoading?'—':String(nodes.length)}
        sub={nodesQuery.isLoading?'正在同步节点…':nodes.length?'点击下方节点查看详情':'尚未绑定节点'}/>
      <Summary label="CPU" value={cpu==null?'—':cpu.toFixed(1)+'%'}
        sub={machine.load_status?.updated_at?'采样于 '+machineDetailTime(machine.load_status.updated_at):'未上报采样时间'}/>
      <Summary label="内存" value={mem==null?'—':mem.toFixed(1)+'%'}
        sub={machineDetailSize(machine.load_status?.mem?.used)+' / '+machineDetailSize(machine.load_status?.mem?.total)}/>
      <Summary label="磁盘" value={disk==null?'—':disk.toFixed(1)+'%'}
        sub={machineDetailSize(machine.load_status?.disk?.used)+' / '+machineDetailSize(machine.load_status?.disk?.total)}/>
    </div>
    <div className="machine-detail-v3-main">
      <section className="machine-detail-v3-section">
        <SectionHeader title="机器信息" description="连接、镜像、心跳与网络传输。"/>
        <dl className="machine-detail-v3-meta">
          <Meta label="机器 ID" value={machine.id}/>
          <Meta label="连接状态" value={presence.label}/>
          <Meta label="镜像渠道" value={machine.image_channel==='dev'?'开发版 (dev)':'稳定版 (latest)'}/>
          <Meta label="最后心跳" value={machineDetailTime(machine.last_seen_at)}/>
          <Meta label="入站速率" value={machineDetailRate(machine.load_status?.net?.in_speed)}/>
          <Meta label="出站速率" value={machineDetailRate(machine.load_status?.net?.out_speed)}/>
          <Meta label="创建时间" value={machineDetailTime(machine.created_at)}/>
          <Meta label="更新时间" value={machineDetailTime(machine.updated_at)}/>
          <Meta label="备注" value={machine.notes || '无'} wide/>
        </dl>
      </section>
      <section className="machine-detail-v3-section">
        <SectionHeader title="Fleet 健康" description="汇总当前机器所承载节点的 Agent 健康信息。"
          action={<Link className="button" to="/system/agent-ops">Agent 运维</Link>}/>
        <QueryFeedback loading={fleetQuery.isLoading || nodesQuery.isLoading}
          error={fleetQuery.isError || nodesQuery.isError}
          onRetry={()=>{void fleetQuery.refetch();void nodesQuery.refetch()}}/>
        {!nodesQuery.isLoading && !nodesQuery.isError && !fleetQuery.isLoading && !fleetQuery.isError ?
          <div className="machine-detail-v3-fleet">
            {[
              ['正常',healthy],['需关注',degraded],['严重',critical],
              ['未上报',Math.max(0,nodes.length-fleetNodes.length)],
            ].map(([label,value])=><div key={label}><span>{label}</span><strong>{value}</strong></div>)}
          </div>:null}
        {fleetNodes.some(item=>item.warnings.length) ? <div className="entity-warning-list">
          {fleetNodes.filter(item=>item.warnings.length).map(item=><div key={item.node_id}>
            <Link className="table-link" to={`/server/node/${item.node_id}`}>
              {item.name || `Node #${item.node_id}`}</Link>
            <span>{item.warnings.map(w=>`${w.code}（${w.severity}）`).join('、')}</span>
          </div>)}
        </div>:null}
      </section>
    </div>

    <section className="machine-detail-v3-section is-full">
      <SectionHeader title="绑定节点" description="查看该机器承载的协议服务，或调整节点绑定关系。"
        action={<button type="button" className="button" onClick={()=>setBindingOpen(true)}>管理绑定</button>}/>
      <DataTable label="绑定节点" rows={nodes} columns={nodeColumns} rowKey={node=>node.id}
        loading={nodesQuery.isLoading} error={nodesQuery.isError} onRetry={()=>void nodesQuery.refetch()}
        emptyState={<EmptyState icon={<Cable size={22}/>} title="尚未绑定节点"
          description="可以从节点管理界面关联，也可以点击“管理绑定”选择现有节点。"
          action={<button type="button" className="button" onClick={()=>setBindingOpen(true)}>管理绑定</button>}/>}/>
    </section>
    <section className="machine-detail-v3-section is-full machine-detail-v3-history">
      <MachineHistoryChart machineId={machineId}/>
    </section>
    <section className="machine-detail-v3-section is-full">
      <SectionHeader title="最近 Agent 动作" description="当前机器下所有节点最近的审批与运维动作。"
        action={<Link className="button" to="/system/agent-ops">查看全部</Link>}/>
      <DataTable label="最近 Agent 动作" rows={actions} columns={actionColumns}
        rowKey={item=>item.request_id}
        loading={nodesQuery.isLoading || actionsQuery.isLoading}
        error={nodesQuery.isError || actionsQuery.isError}
        onRetry={()=>{void nodesQuery.refetch();void actionsQuery.refetch()}}
        emptyState={<EmptyState icon={<Server size={22}/>} title="暂无 Agent 动作"
          description="机器关联节点尚无审批或运维动作记录。"
          action={<Link className="button" to="/system/agent-ops">前往 Agent 运维</Link>}/>}/>
    </section>

    <Modal open={editorOpen} title="编辑机器" onClose={()=>setEditorOpen(false)}>
      <div className="form-stack">
        <label className="field"><span>机器名称</span>
          <input value={name} onChange={e=>setName(e.target.value)}/></label>
        <label className="field"><span>备注</span>
          <textarea value={notes} onChange={e=>setNotes(e.target.value)}/></label>
        <label className="field"><span>TX-Node 镜像渠道</span>
          <select value={imageChannel}
            onChange={e=>setImageChannel(e.target.value as 'stable'|'dev')}>
            <option value="stable">稳定版 (latest)</option><option value="dev">开发版 (dev)</option>
          </select>
          <small>保存只改变渠道设置，正在运行的机器需单独执行更新。</small>
        </label>
        <label className="config-switch-field">
          <div><strong>启用机器</strong><small>停用后机器不再参与正常调度。</small></div>
          <button type="button" role="switch" aria-label="启用机器" aria-checked={active}
            className={`config-switch ${active?'active':''}`}
            onClick={()=>setActive(value=>!value)}><span/></button>
        </label>
        <div className="card-actions">
          <button type="button" className="button" onClick={()=>setEditorOpen(false)}>取消</button>
          <button type="button" className="button primary" disabled={!name.trim() || save.isPending}
            onClick={()=>save.mutate()}>{save.isPending?'保存中…':'保存机器'}</button>
        </div>
      </div>
    </Modal>
    <Modal open={bindingOpen} title={`节点绑定：${machine.name || machine.id}`}
      onClose={()=>setBindingOpen(false)} wide>
      <MachineNodeBinding machineId={machineId}/>
    </Modal>
    {credentialsFor===machineId ? <MachineCredentialsDialog key={machineId}
      machineId={machineId} machineName={machine.name || `Machine #${machineId}`}
      onClose={()=>setCredentialsFor(null)}/>:null}
  </div>
}
function Summary({label,value,sub}:{label:string;value:string;sub:string}) {
  return <div><span>{label}</span><strong>{value}</strong><small>{sub}</small></div>
}
function Meta({label,value,wide=false}:{label:string;value:ReactNode;wide?:boolean}) {
  return <div className={wide?'is-wide':undefined}><dt>{label}</dt><dd>{value}</dd></div>
}
