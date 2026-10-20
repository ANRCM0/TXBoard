import { Search, RefreshCw, ArrowUpRight } from 'lucide-react'
import './network-reference.css'
import type { CoreOutput } from '@txboard/core-sdk'
import { Link } from 'react-router-dom'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { formatTimestamp } from './Inventory'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import type { Column } from '../components/ui/DataTable'
import { StatusBadge } from '../components/ui/StatusBadge'
import { FilterBar } from '../components/ui/FilterBar'
import { Input, Select } from '../components/ui/Field'
import { admin } from './session'

type Machine = CoreOutput<'network.machines.list'>[number]
const columns: Column<Machine>[] = [
  { key: 'name', header: '服务器', render: row => <div className="native-row-identity">
    <Link to={'/server/machine/' + row.id}><strong>{row.name}</strong></Link><small>#{row.id}</small>
  </div> },
  { key: 'active', header: '管理状态', render: row => <StatusBadge tone={row.is_active ? 'success' : 'neutral'}>{row.is_active ? '已启用' : '已停用'}</StatusBadge> },
  { key: 'channel', header: '镜像通道', render: row => <span className="network-tag is-info">{row.image_channel}</span> },
  { key: 'nodes', header: '绑定节点', render: row => <span className="network-count">{row.servers_count}</span> },
  { key: 'seen', header: '最近心跳', render: row => <span className={'network-timestamp' + (row.last_seen_at === null ? ' network-muted' : '')}>{formatTimestamp(row.last_seen_at)}</span> },
  { key: 'details', header: '操作', render: row => <Link className="network-icon-link" title="查看详情" aria-label={'查看 ' + row.name + ' 详情'} to={'/server/machine/' + row.id}><ArrowUpRight size={16} /></Link> },
]
export function MachinesPage() {
  const [page, setPage] = useState(1), [search, setSearch] = useState(''), [status, setStatus] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'network.machines.list', page],
    queryFn: ({ signal }) => admin.read('network.machines.list', { page, per_page: 20 }, signal) })
  const management = useNetworkManagement('network.machines.', [query.error], () => query.refetch({ throwOnError: true }))
  const applied = [
    ...(search.trim() ? [{ key: 'search', label: '搜索 ' + search.trim() }] : []),
    ...(status ? [{ key: 'status', label: status === 'true' ? '已启用' : '已停用' }] : []),
  ]
  return <div className="native-management-page network-reference-page network-machines-page"><PageHeader title="服务器管理" description="查看服务器，进入详情管理配置和已绑定节点。" action={<button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void management.refresh()}><RefreshCw size={15} aria-hidden="true" />刷新服务器</button>} />
    <NetworkRecovery management={management} blocked={query.isFetching || query.isError || !query.data} />
    <ReadList title="服务器列表" rows={management.denied || query.isError ? [] : (query.data?.data ?? []).filter(row => (!status || String(row.is_active) === status) && [row.id, row.name, row.image_channel].some(value => value.toLowerCase().includes(search.trim().toLowerCase())))} columns={columns} page={page} meta={query.data?.meta} ready={!!query.data && !management.denied} loading={query.isFetching} error={query.error} onRetry={() => void management.refresh()} onPage={next => { setPage(next); setSearch('') }} columnSettingsKey="machines" stickyHeader stickyFirstColumn>
      <FilterBar applied={applied} onClear={() => { setSearch(''); setStatus('') }} hint="仅筛选当前页">
        <label className="native-list-search"><Search size={16} aria-hidden="true" /><span className="network-sr-only">搜索当前页</span><Input placeholder="搜索当前页名称、ID 或镜像通道" aria-label="搜索当前页" value={search} onChange={event => setSearch(event.target.value)} /></label>
        <label>服务器状态<Select aria-label="服务器状态" value={status} onChange={event => setStatus(event.target.value)}><option value="">全部状态</option><option value="true">已启用</option><option value="false">已停用</option></Select></label>
      </FilterBar>
    </ReadList>
  </div>
}
