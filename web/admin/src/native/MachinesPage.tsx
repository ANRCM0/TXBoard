import { t, useLocale, formatNumber } from '../lib/i18n'
import { useListPagination } from './useListPagination'
import { Search, RefreshCw, Plus, ArrowUpRight } from 'lucide-react'
import './network-reference.css'
import type { CoreOutput } from '@txboard/core-sdk'
import { Link, useNavigate } from 'react-router-dom'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { formatTimestamp } from './Inventory'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import type { Column } from '../components/ui/DataTable'
import { StatusBadge } from '../components/ui/StatusBadge'
import { FilterBar } from '../components/ui/FilterBar'
import { Input } from '../components/ui/Field'
import { admin, workflowActions } from './session'
import { MachineCreateDialog } from './MachineCreateDialog'
import { ManagementFeedback } from './Feedback'
import { FacetedFilter } from '../components/ui/FacetedFilter'
import { Tag } from '../components/ui/Tag'
import { NetworkBytes } from './NetworkValues'
import { ServerQuickCard } from './ServerQuickCard'

type Machine = CoreOutput<'network.machines.list'>[number]

const columns: Column<Machine>[] = [
  { key: 'name', header: '服务器', render: row => <ServerQuickCard machine={row} /> },
  { key: 'active', header: '管理状态', render: row => <StatusBadge tone={row.is_active ? 'success' : 'neutral'}>{row.is_active ? t("已启用") : t("已停用")}</StatusBadge> },
  { key: 'online', header: '连接状态', render: row => row.observation ? <StatusBadge tone={row.observation.online ? 'success' : 'neutral'}>{row.observation.online ? t('在线') : t('离线')}</StatusBadge> : <span className="network-muted">{t('未上报')}</span> },
  { key: 'load', header: '运行负载', render: row => row.observation?.telemetry_fresh ? <div><span>{row.observation.cpu_percent === null ? t('未上报') : 'CPU ' + row.observation.cpu_percent + '%'}</span><small className="network-machine-memory">{t('内存')} <NetworkBytes value={row.observation.memory_used_bytes} /> / <NetworkBytes value={row.observation.memory_total_bytes} /></small>{row.observation.high_load ? <StatusBadge tone="warning">{t('高负载')}</StatusBadge> : null}</div> : <span className="network-muted">{t('负载未上报或已过期')}</span> },
  { key: 'channel', header: '镜像通道', render: row => <Tag tone="info" mono>{row.image_channel}</Tag> },
  { key: 'nodes', header: '绑定节点', render: row => <span className="network-count">{row.servers_count}</span> },
  { key: 'seen', header: '最近心跳', render: row => <span className={'network-timestamp' + (row.last_seen_at === null ? ' network-muted' : '')}>{row.last_seen_at === null ? t("尚未上报心跳") : formatTimestamp(row.last_seen_at)}</span> },
  { key: 'details', header: '操作', render: row => <Link className="network-icon-link" title={t("查看详情")} aria-label={t("查看 {name} 详情", { name: row.name })} to={'/server/machine/' + row.id}><ArrowUpRight size={16} /></Link> },
]
export function MachinesPage() {
  useLocale()
  const navigate = useNavigate()
  const { page, setPage, perPage, setPerPage } = useListPagination()
  const [creating, setCreating] = useState(false)
  const [search, setSearch] = useState(''), [status, setStatus] = useState<string[]>([]), [channel, setChannel] = useState<string[]>([])
  const [connection, setConnection] = useState<string[]>([])
  const query = useQuery({ queryKey: ['native-admin', 'network.machines.list', page, perPage],
    queryFn: ({ signal }) => admin.read('network.machines.list', { page, per_page: perPage }, signal) })
  const overview = useQuery({ queryKey: ['native-admin', 'machine-overview'], queryFn: ({ signal }) => admin.read('network.machines.overview.get', {}, signal) })
  const management = useNetworkManagement('network.machines.', [query.error, overview.error], async () => { await query.refetch({ throwOnError: true }); await overview.refetch({ throwOnError: true }) })
  const locked = query.isFetching || query.isError || !query.data || management.denied || management.state.busy || !!management.state.pending
  const applied = [
    ...(search.trim() ? [{ key: 'search', label: t("搜索 {value}", { value: search.trim() }) }] : []),
    ...status.map(value => ({ key: 'status:' + value, label: value === 'true' ? t('已启用') : t('已停用') })),
    ...channel.map(value => ({ key: 'channel:' + value, label: t('通道 {value}', { value }) })),
    ...connection.map(value => ({ key: 'connection:' + value, label: value === 'online' ? t('在线') : value === 'offline' ? t('离线') : t('未上报') })),
  ]
  return <div className="native-management-page network-reference-page network-machines-page"><PageHeader title={t("服务器管理")} description={t("创建服务器，管理部署认证、运行负载与已绑定节点。")} action={<><button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void management.refresh()}><RefreshCw size={15} aria-hidden="true" />{t("刷新服务器")}</button><button className="button" data-primary="true" disabled={locked} onClick={() => setCreating(true)}><Plus size={15} aria-hidden="true" />{t("新建服务器")}</button></>} />
    <NetworkRecovery management={management} blocked={query.isFetching || query.isError || !query.data} />
    <ManagementFeedback loading={overview.isFetching} error={overview.error} onRetry={() => void management.refresh()} />
    {!management.denied && !overview.isError && overview.data ? <section className="network-machine-overview" aria-label={t('全部服务器概览')}>{([['total', '全部服务器'], ['enabled', '已启用'], ['online', '在线'], ['high_load', '高负载'], ['unknown_load', '负载未知']] as const).map(([key, label]) => <div key={key}><span>{t(label)}</span><strong>{formatNumber(overview.data.data[key])}</strong></div>)}<p>{t('在线取自有效连接；负载上报有效期 {seconds} 秒，CPU、内存或磁盘占用达到 {percent}% 视为高负载。', { seconds: overview.data.data.freshness_seconds, percent: overview.data.data.high_load_threshold_percent })}</p></section> : null}
    <ReadList title={"服务器列表"} rows={management.denied || query.isError ? [] : (query.data?.data ?? []).filter(row => (!status.length || status.includes(String(row.is_active))) && (!channel.length || channel.includes(row.image_channel)) && (!connection.length || connection.includes(row.observation ? row.observation.online ? 'online' : 'offline' : 'unknown')) && [row.id, row.name, row.image_channel].some(value => value.toLowerCase().includes(search.trim().toLowerCase())))} columns={columns} perPage={perPage} onPageSize={setPerPage} page={page} meta={query.data?.meta} ready={!!query.data && !management.denied} loading={query.isFetching} error={query.error} onRetry={() => void management.refresh()} onPage={next => { setPage(next); setSearch('') }} columnSettingsKey="machines" stickyHeader stickyFirstColumn>
      <FilterBar applied={applied} onRemove={key => { if (key === 'search') setSearch(''); if (key.startsWith('status:')) setStatus(values => values.filter(value => value !== key.slice(7))); if (key.startsWith('channel:')) setChannel(values => values.filter(value => value !== key.slice(8))); if (key.startsWith('connection:')) setConnection(values => values.filter(value => value !== key.slice(11))) }} onClear={() => { setSearch(''); setStatus([]); setChannel([]); setConnection([]) }} hint={t('仅筛选当前页 · 管理状态与最近心跳分别展示')}>
        <label className="native-list-search"><Search size={16} aria-hidden="true" /><span className="network-sr-only">{t('搜索当前页')}</span><Input placeholder={t('搜索当前页名称、ID 或镜像通道')} aria-label={t('搜索当前页')} value={search} onChange={event => setSearch(event.target.value)} /></label>
        <FacetedFilter label={t('服务器状态')} value={status} onChange={setStatus} options={[{ value: 'true', label: t('已启用') }, { value: 'false', label: t('已停用') }]} />
        <FacetedFilter label={t('镜像通道')} value={channel} onChange={setChannel} options={[...new Set([...(management.denied || query.isError ? [] : query.data?.data ?? []).map(row => row.image_channel), ...channel])].map(value => ({ value, label: value }))} />
        <FacetedFilter label={t('连接状态')} value={connection} onChange={setConnection} options={[{ value: 'online', label: t('在线') }, { value: 'offline', label: t('离线') }, { value: 'unknown', label: t('未上报') }]} />
      </FilterBar>
    </ReadList>
    <MachineCreateDialog open={creating && !management.denied && !query.isError} locked={locked} onClose={() => setCreating(false)} onCreate={async input => {
      let machineId = ''
      await management.perform(async () => {
        const result = await workflowActions.runResult('network.machines.create', input) as { data: CoreOutput<'network.machines.create'> }
        machineId = result.data.id
      }, () => { setCreating(false); navigate('/server/machine/' + machineId) })
    }} />
  </div>
}
