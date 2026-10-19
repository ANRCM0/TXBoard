import { RefreshCw, Server, Settings2 } from 'lucide-react'
import './network-reference.css'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import type { CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'
import { ManagementFeedback } from './Feedback'
import { ReadFields, ReadList } from './ReadList'
import { formatTimestamp } from './Inventory'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import { DetailSection } from '../components/ui/DetailSection'
import { DangerZone } from '../components/ui/DangerZone'
import { StatusBadge } from '../components/ui/StatusBadge'
import { Field, Input, Select, Switch, Textarea } from '../components/ui/Field'

type BoundNode = CoreOutput<'network.machines.nodes.list'>[number]
const columns: Column<BoundNode>[] = [
  { key: 'name', header: '节点', render: row => <div className="native-row-identity"><Link to={'/server/node/' + row.id}><strong>{row.name}</strong></Link><small>#{row.id}</small></div> },
  { key: 'address', header: '地址', render: row => <code>{row.host.includes(':') ? '[' + row.host + ']' : row.host}:{row.port}</code> },
  { key: 'status', header: '状态', render: row => <div className="network-tags"><StatusBadge tone={row.enabled ? 'success' : 'neutral'}>{row.enabled ? '启用' : '禁用'}</StatusBadge><span className="reference-tag">{row.show ? '显示' : '隐藏'}</span></div> },
]
export function MachineDetailPage() {
  const { machineId = '' } = useParams(), navigate = useNavigate()
  const [page, setPage] = useState(1), [name, setName] = useState(''), [notes, setNotes] = useState('')
  const [active, setActive] = useState(false), [channel, setChannel] = useState('stable')
  const query = useQuery({ queryKey: ['native-admin', 'machine-detail', machineId],
    queryFn: ({ signal }) => admin.read('network.machines.get', { machine_id: machineId }, signal), enabled: !!machineId })
  const nodes = useQuery({ queryKey: ['native-admin', 'machine-nodes', machineId, page],
    queryFn: ({ signal }) => admin.read('network.machines.nodes.list', { machine_id: machineId, page, per_page: 20 }, signal), enabled: !!machineId && !!query.data && !query.isError })
  const management = useNetworkManagement('network.machines.', [query.error, nodes.error], async () => {
    await query.refetch({ throwOnError: true }); await nodes.refetch({ throwOnError: true })
  })
  const machine = !management.denied && !query.isError ? query.data?.data : undefined
  useEffect(() => { setPage(1) }, [machineId])
  useEffect(() => { setName(machine?.name ?? ''); setNotes(machine?.notes ?? ''); setActive(machine?.is_active ?? false); setChannel(machine?.image_channel ?? 'stable') }, [machine])
  const locked = !machine || query.isFetching || nodes.isFetching || nodes.isError || management.state.busy || !!management.state.pending
  async function save() {
    if (locked || !machine || !name.trim() || !['stable', 'dev'].includes(channel) || !window.confirm('确定保存服务器配置？请核对当前配置后继续。')) return
    await management.perform(() => workflowActions.run('network.machines.update', { machine_id: machine.id, name: name.trim(), notes: notes || null, is_active: active, image_channel: channel as 'stable' | 'dev' }))
  }
  async function remove() {
    if (locked || !machine || !window.confirm('确定删除服务器 ' + machine.name + '？存在绑定资源时服务端将拒绝。')) return
    await management.perform(() => workflowActions.run('network.machines.delete', { machine_id: machine.id }), () => navigate('/server/machine'))
  }
  return <div className="native-management-page network-reference-page network-machine-detail-page">
    <PageHeader title="服务器详情" description="管理服务器配置并查看已绑定节点。" action={<><Link className="button" to="/server/machine">返回服务器列表</Link><button className="button" disabled={query.isFetching || nodes.isFetching || management.state.busy} onClick={() => void management.refresh()}><RefreshCw size={15} aria-hidden="true" />刷新服务器</button></>} />
    <NetworkRecovery management={management} blocked={!machine || query.isFetching || nodes.isFetching || query.isError || nodes.isError || (!!management.pending && 'machine_id' in management.pending.input && management.pending.input.machine_id !== machine.id)} />
    <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void management.refresh()} notFoundMessage="服务器不存在或已被删除。" />
    {machine ? <>
      <DetailSection label="服务器配置概况" title={machine.name} description={'服务器 #' + machine.id + ' · ' + machine.image_channel}
        icon={<Server size={17} />} className="network-profile-card"
        actions={<StatusBadge tone={machine.is_active ? 'success' : 'neutral'}>{machine.is_active ? '已启用' : '已停用'}</StatusBadge>}>
        <h3 className="network-section-heading">运行概况</h3>
        <ReadFields fields={[
          ['服务器 ID', machine.id], ['绑定节点数', machine.servers_count], ['最近心跳', formatTimestamp(machine.last_seen_at)],
          ['创建时间', formatTimestamp(machine.created_at)], ['更新时间', formatTimestamp(machine.updated_at)],
        ]} />
      </DetailSection>
      <DetailSection label="服务器设置" title="服务器设置" description="设置名称、镜像通道和管理状态。保存前请核对当前配置。"
        icon={<Settings2 size={17} />} className="network-settings-card">
        <form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}>
          <Field label="服务器名称" required>
            <Input required maxLength={255} value={name} disabled={locked} onChange={event => setName(event.target.value)} />
          </Field>
          <Field label="镜像通道">
            <Select value={channel} disabled={locked} onChange={event => setChannel(event.target.value)}>
              {!['stable', 'dev'].includes(channel) ? <option value={channel}>{channel}（当前值）</option> : null}
              <option value="stable">stable</option><option value="dev">dev</option>
            </Select>
          </Field>
          <Switch label="启用服务器" checked={active} disabled={locked} onChange={event => setActive(event.target.checked)} />
          <Field label="备注" className="network-form-field-wide">
            <Textarea id="machine-notes" placeholder="添加用途、区域或运维备注" rows={4} maxLength={2000} value={notes} disabled={locked} onChange={event => setNotes(event.target.value)} />
          </Field>
          <div className="network-save-actions">
            <button className="button" data-primary="true" disabled={locked || !name.trim() || !['stable', 'dev'].includes(channel)}>保存服务器</button>
            <span className="network-muted">保存前请核对名称、通道与启用状态</span>
          </div>
        </form>
        <DangerZone title="移除服务器资源" description="存在绑定资源时，服务端会拒绝删除。" className="network-danger-zone">
          <button type="button" className="button danger" disabled={locked} onClick={() => void remove()}>删除服务器</button>
        </DangerZone>
      </DetailSection>
      <ReadList title="绑定节点" rows={nodes.isError ? [] : nodes.data?.data ?? []} columns={columns} page={page} meta={nodes.data?.meta} ready={!!nodes.data && !management.denied} loading={nodes.isFetching} error={nodes.error} onRetry={() => void management.refresh()} onPage={setPage} />
    </> : null}
  </div>
}
