import { Search, RefreshCw, ArrowUpRight } from 'lucide-react'
import './network-reference.css'
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

type Node = CoreOutput<'network.nodes.list'>[number]
const columns: Column<Node>[] = [
  { key: 'name', header: '节点', render: node => <div className="nodes-v3-identity">
    <Link to={'/server/node/' + node.id}><strong>{node.name}</strong></Link><small>#{node.id} · {node.type}</small>
  </div> },
  { key: 'host', header: '连接地址', render: node =>
    <code>{node.host.includes(':') ? '[' + node.host + ']' : node.host}:{node.port}</code> },
  { key: 'machine', header: '所属服务器', render: node => node.machine_id ? <Link className="network-resource-link" to={'/server/machine/' + node.machine_id}>#{node.machine_id}</Link> : <span className="network-muted">未绑定</span> },
  { key: 'state', header: '状态', render: node => <div className="network-tags"><span className={'network-tag ' + (node.enabled ? 'is-success' : '')}>{node.enabled ? '已启用' : '已禁用'}</span><span className="network-tag">{node.show ? '显示' : '隐藏'}</span></div> },
  { key: 'traffic', header: '上传 / 下载字节', render: node => <div className="network-traffic-pair"><span><small>上传</small><code>{dash(node.u)}</code></span><span><small>下载</small><code>{dash(node.d)}</code></span></div> },
  { key: 'details', header: '操作', render: node => <Link className="network-icon-link" title="查看详情" aria-label={'查看 ' + node.name + ' 详情'} to={'/server/node/' + node.id}><ArrowUpRight size={16} /></Link> },
]
export function NodesPage() {
  const [page, setPage] = useState(1), [search, setSearch] = useState(''), [status, setStatus] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'network.nodes.list', page],
    queryFn: ({ signal }) => admin.read('network.nodes.list', { page, per_page: 20 }, signal) })
  const management = useNetworkManagement('network.nodes.', [query.error], () => query.refetch({ throwOnError: true }))
  return <div className="native-management-page network-reference-page network-nodes-page"><PageHeader title="节点管理" description="查看节点，进入详情管理节点状态、归属和计数。" action={<button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void management.refresh()}><RefreshCw size={15} aria-hidden="true" />刷新节点</button>} />
    <NetworkRecovery management={management} blocked={query.isFetching || query.isError || !query.data} />
    <ReadList title="节点列表" rows={management.denied || query.isError ? [] : (query.data?.data ?? []).filter(node => (!status || String(node.enabled) === status) && [node.id, node.name, node.type, node.host, node.machine_id].some(value => String(value ?? '').toLowerCase().includes(search.trim().toLowerCase())))} columns={columns} page={page} meta={query.data?.meta} ready={!!query.data && !management.denied} loading={query.isFetching} error={query.error} onRetry={() => void management.refresh()} onPage={next => { setPage(next); setSearch('') }}>
      <div className="network-filterbar"><label className="native-list-search"><Search size={16} aria-hidden="true" /><span className="network-sr-only">搜索当前页</span><input placeholder="搜索当前页名称、ID 或地址" aria-label="搜索当前页" value={search} onChange={event => setSearch(event.target.value)} /></label><select aria-label="节点状态" value={status} onChange={event => setStatus(event.target.value)}><option value="">全部状态</option><option value="true">已启用</option><option value="false">已停用</option></select><span className="network-toolbar-hint">仅筛选当前页</span>{search || status ? <button type="button" className="button network-clear-filter" onClick={() => { setSearch(''); setStatus('') }}>清除筛选</button> : null}</div>
    </ReadList>
  </div>
}
