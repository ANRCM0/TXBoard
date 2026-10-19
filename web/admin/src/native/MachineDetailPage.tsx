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

type BoundNode = CoreOutput<'network.machines.nodes.list'>[number]
const columns: Column<BoundNode>[] = [
  { key: 'name', header: '节点', render: row => <Link to={'/server/node/' + row.id}>{row.name} (#{row.id})</Link> },
  { key: 'address', header: '地址', render: row => row.host + ':' + row.port },
  { key: 'status', header: '状态', render: row => (row.enabled ? '启用' : '禁用') + ' / ' + (row.show ? '显示' : '隐藏') },
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
  return <div className="native-management-page">
    <PageHeader title="服务器详情" description="管理服务器配置并查看已绑定节点。" action={<><Link className="button" to="/server/machine">返回服务器列表</Link><button className="button" disabled={query.isFetching || nodes.isFetching || management.state.busy} onClick={() => void management.refresh()}>刷新服务器</button></>} />
    <NetworkRecovery management={management} blocked={!machine || query.isFetching || nodes.isFetching || query.isError || nodes.isError || (!!management.pending && 'machine_id' in management.pending.input && management.pending.input.machine_id !== machine.id)} />
    <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void management.refresh()} notFoundMessage="服务器不存在或已被删除。" />
    {machine ? <><section className="list-card"><h2>{machine.name}</h2><ReadFields fields={[
      ['服务器 ID', machine.id], ['绑定节点数', machine.servers_count], ['最近心跳', formatTimestamp(machine.last_seen_at)], ['创建时间', formatTimestamp(machine.created_at)], ['更新时间', formatTimestamp(machine.updated_at)],
    ]} /><form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}>
      <label>服务器名称<input required maxLength={255} value={name} disabled={locked} onChange={event => setName(event.target.value)} /></label>
      <div style={{ display: 'grid', gap: 8 }}><label htmlFor="machine-notes">备注</label><textarea id="machine-notes" maxLength={2000} value={notes} disabled={locked} onChange={event => setNotes(event.target.value)} /></div>
      <label>镜像通道<select value={channel} disabled={locked} onChange={event => setChannel(event.target.value)}>{!['stable', 'dev'].includes(channel) ? <option value={channel}>{channel}（当前值）</option> : null}<option value="stable">stable</option><option value="dev">dev</option></select></label>
      <label><input type="checkbox" checked={active} disabled={locked} onChange={event => setActive(event.target.checked)} />启用服务器</label>
      <button className="button" disabled={locked || !name.trim() || !['stable', 'dev'].includes(channel)}>保存服务器</button><button type="button" className="button" disabled={locked} onClick={() => void remove()}>删除服务器</button>
    </form></section>
      <ReadList title="绑定节点" rows={nodes.isError ? [] : nodes.data?.data ?? []} columns={columns} page={page} meta={nodes.data?.meta} ready={!!nodes.data && !management.denied} loading={nodes.isFetching} error={nodes.error} onRetry={() => void management.refresh()} onPage={setPage} />
    </> : null}
  </div>
}
