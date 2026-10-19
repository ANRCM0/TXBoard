import type { CoreOutput } from '@txboard/core-sdk'
import { Link } from 'react-router-dom'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { formatTimestamp } from './Inventory'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import type { Column } from '../components/ui/DataTable'
import { admin } from './session'
import '../pages/server/MachinesPage.css'

type Machine = CoreOutput<'network.machines.list'>[number]
const columns: Column<Machine>[] = [
  { key: 'name', header: '服务器', render: row => <div className="native-row-identity">
    <Link to={'/server/machine/' + row.id}><strong>{row.name}</strong></Link><small>#{row.id}</small>
  </div> },
  { key: 'active', header: '管理状态', render: row => row.is_active ? '已启用' : '已停用' },
  { key: 'channel', header: '镜像通道', render: row => row.image_channel },
  { key: 'nodes', header: '绑定节点', render: row => row.servers_count },
  { key: 'seen', header: '最近心跳', render: row => formatTimestamp(row.last_seen_at) },
]
export function MachinesPage() {
  const [page, setPage] = useState(1), [search, setSearch] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'network.machines.list', page],
    queryFn: ({ signal }) => admin.read('network.machines.list', { page, per_page: 20 }, signal) })
  const management = useNetworkManagement('network.machines.', [query.error], () => query.refetch({ throwOnError: true }))
  return <div className="native-management-page"><PageHeader title="服务器管理" description="查看服务器，进入详情管理配置和已绑定节点。" action={<button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void management.refresh()}>刷新服务器</button>} />
    <NetworkRecovery management={management} blocked={query.isFetching || query.isError || !query.data} />
    <ReadList title="服务器列表" rows={management.denied || query.isError ? [] : (query.data?.data ?? []).filter(row => [row.id, row.name, row.image_channel].some(value => value.toLowerCase().includes(search.trim().toLowerCase())))} columns={columns} page={page} meta={query.data?.meta} ready={!!query.data && !management.denied} loading={query.isFetching} error={query.error} onRetry={() => void management.refresh()} onPage={next => { setPage(next); setSearch('') }}>
      <label className="native-list-search">搜索当前页<input aria-label="搜索当前页" value={search} onChange={event => setSearch(event.target.value)} /></label>
    </ReadList>
  </div>
}
