import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { admin, workflowActions } from './session'
import { PageHeader } from '../components/ui/PageHeader'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { dash, formatTimestamp } from './Inventory'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'

export function NodeDetailPage() {
  const { nodeId = '' } = useParams(), navigate = useNavigate()
  const [machine, setMachine] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'node-detail', nodeId],
    queryFn: ({ signal }) => admin.read('network.nodes.get', { node_id: nodeId }, signal), enabled: !!nodeId })
  const management = useNetworkManagement('network.nodes.', [query.error], () => query.refetch({ throwOnError: true }))
  const node = !management.denied && !query.isError ? query.data?.data : undefined
  useEffect(() => { setMachine(node?.machine_id ?? '') }, [node])
  const locked = !node || query.isFetching || management.state.busy || !!management.state.pending
  async function command(kind: 'show' | 'enabled' | 'machine' | 'copy' | 'delete' | 'reset') {
    if (locked || !node || !window.confirm('确定对节点 ' + node.name + ' 执行此操作？配置以本次读取结果为准，请核对后继续。')) return
    const input = { node_id: node.id }
    await management.perform(() => kind === 'copy' ? workflowActions.run('network.nodes.copy', input)
      : kind === 'delete' ? workflowActions.run('network.nodes.delete', input)
      : kind === 'reset' ? workflowActions.run('network.nodes.traffic-reset', input)
      : workflowActions.run('network.nodes.patch', { ...input, ...(kind === 'show' ? { show: !node.show }
        : kind === 'enabled' ? { enabled: !node.enabled } : { machine_id: machine.trim() || null }) }),
    kind === 'delete' ? () => navigate('/server/manage') : undefined)
  }
  const recoveryBlocked = query.isFetching || query.isError || !node || (!!management.pending && 'node_id' in management.pending.input && management.pending.input.node_id !== node.id)
  return <div className="native-management-page">
    <PageHeader title="节点详情" description="查看节点及其归属，管理显示、启用状态和节点计数。" action={<><Link className="button" to="/server/manage">返回节点列表</Link><button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void management.refresh()}>刷新节点</button></>} />
    <NetworkRecovery management={management} blocked={recoveryBlocked} />
    <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void management.refresh()} notFoundMessage="节点不存在或已被删除。" />
    {node ? <>
      <section className="list-card"><h2>{node.name}</h2><ReadFields fields={[
        ['节点 ID', node.id], ['协议', node.type], ['地址', node.host + ':' + node.port], ['服务端端口', dash(node.server_port)],
        ['父节点', node.parent_id ? <Link to={'/server/node/' + node.parent_id}>{node.parent_id}</Link> : '无'],
        ['服务器', node.machine_id ? <Link to={'/server/machine/' + node.machine_id}>{node.machine_id}</Link> : '未绑定'],
        ['显示', node.show ? '显示' : '隐藏'], ['状态', node.enabled ? '已启用' : '已禁用'], ['上传字节', dash(node.u)], ['下载字节', dash(node.d)],
        ['分组', node.group_ids.join(', ') || '无'], ['路由', node.route_ids.join(', ') || '无'], ['标签', node.tags.join(', ') || '无'],
        ['创建时间', formatTimestamp(node.created_at)], ['更新时间', formatTimestamp(node.updated_at)],
      ]} /></section>
      <section className="list-card"><h2>节点操作</h2><p>重置节点计数只影响节点统计；用户订阅额度请使用流量重置入口。</p>
        <div className="native-list-toolbar"><button className="button" disabled={locked} onClick={() => void command('show')}>{node.show ? '隐藏节点' : '显示节点'}</button><button className="button" disabled={locked} onClick={() => void command('enabled')}>{node.enabled ? '禁用节点' : '启用节点'}</button><button className="button" disabled={locked} onClick={() => void command('copy')}>复制节点</button><button className="button" disabled={locked} onClick={() => void command('reset')}>重置节点计数</button><button className="button" disabled={locked} onClick={() => void command('delete')}>删除节点</button></div>
        <form className="native-content-form" onSubmit={event => { event.preventDefault(); void command('machine') }}><label>绑定服务器 ID（留空解除绑定）<input value={machine} pattern="[1-9][0-9]{0,18}" maxLength={19} disabled={locked} onChange={event => setMachine(event.target.value)} /></label><button className="button" disabled={locked}>保存服务器归属</button></form>
      </section>
    </> : null}
  </div>
}
