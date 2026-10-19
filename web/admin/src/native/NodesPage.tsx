import type { CoreOutput } from '@txboard/core-sdk'
import { Link } from 'react-router-dom'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { dash } from './Inventory'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import type { Column } from '../components/ui/DataTable'
import { admin } from './session'
import '../pages/server/NodesPage.css'

type Node = CoreOutput<'network.nodes.list'>[number]
const columns: Column<Node>[] = [
  { key: 'name', header: '节点', render: node => <div className="nodes-v3-identity">
    <Link to={'/server/node/' + node.id}><strong>{node.name}</strong></Link><small>#{node.id} · {node.type}</small>
  </div> },
  { key: 'host', header: '连接地址', render: node =>
    <code>{node.host.includes(':') ? '[' + node.host + ']' : node.host}:{node.port}</code> },
  { key: 'machine', header: '所属服务器', render: node => dash(node.machine_id) },
  { key: 'state', header: '状态', render: node => <span>{node.enabled ? '已启用' : '已禁用'} · {node.show ? '显示' : '隐藏'}</span> },
  { key: 'traffic', header: '上传 / 下载字节', render: node => <span>{dash(node.u)} / {dash(node.d)}</span> },
]
export function NodesPage() {
  const [page, setPage] = useState(1), [search, setSearch] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'network.nodes.list', page],
    queryFn: ({ signal }) => admin.read('network.nodes.list', { page, per_page: 20 }, signal) })
  const management = useNetworkManagement('network.nodes.', [query.error], () => query.refetch({ throwOnError: true }))
  return <div className="native-management-page"><PageHeader title="节点管理" description="查看节点，进入详情管理节点状态、归属和计数。" action={<button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void management.refresh()}>刷新节点</button>} />
    <NetworkRecovery management={management} blocked={query.isFetching || query.isError || !query.data} />
    <ReadList title="节点列表" rows={management.denied || query.isError ? [] : (query.data?.data ?? []).filter(node => [node.id, node.name, node.type, node.host, node.machine_id].some(value => String(value ?? '').toLowerCase().includes(search.trim().toLowerCase())))} columns={columns} page={page} meta={query.data?.meta} ready={!!query.data && !management.denied} loading={query.isFetching} error={query.error} onRetry={() => void management.refresh()} onPage={next => { setPage(next); setSearch('') }}>
      <label className="native-list-search">搜索当前页<input aria-label="搜索当前页" value={search} onChange={event => setSearch(event.target.value)} /></label>
    </ReadList>
  </div>
}
