import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Network, Plus, RefreshCw, Search, Server, X } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import {
  copyNode, deleteNode, getGroups, getMachines, getNodes, getProtocolDefinitions,
  getRoutes, saveNode, type NodeItem,
} from '../../api/server'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { EmptyState } from '../../components/ui/EmptyState'
import { PageHeader } from '../../components/ui/PageHeader'
import { listSurfaceState } from '../../components/ui/listSurfaceState'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { NodeEditorModal } from './NodeEditorModal'
import { NodeRowActions } from './NodeRowActions'
import { nodeConnectionLabel, nodeStatus } from './nodeListPresentation'
import './NodesPage.css'

export function NodesPage() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<NodeItem | null>(null)
  const [search, setSearch] = useState('')
  const [machineFilter, setMachineFilter] = useState('')

  const query = useQuery({ queryKey: ['nodes'], queryFn: getNodes, refetchInterval: 30_000 })
  const protocolDefinitions = useQuery({
    queryKey: ['protocol-definitions'], queryFn: getProtocolDefinitions, staleTime: 300_000, retry: 1,
  })
  const machines = useQuery({ queryKey: ['machines'], queryFn: getMachines, staleTime: 30_000 })
  const groups = useQuery({ queryKey: ['groups'], queryFn: getGroups, staleTime: 30_000 })
  const routes = useQuery({ queryKey: ['routes'], queryFn: getRoutes, staleTime: 30_000 })

  const save = useMutation({
    mutationFn: (payload: Partial<NodeItem>) => saveNode(payload),
    onSuccess: () => {
      toast.success(editing ? '节点已更新' : '节点已创建')
      setOpen(false)
      setEditing(null)
      void qc.invalidateQueries({ queryKey: ['nodes'] })
    },
  })
  const remove = useMutation({
    mutationFn: deleteNode,
    onSuccess: () => {
      toast.success('节点已删除')
      void qc.invalidateQueries({ queryKey: ['nodes'] })
    },
  })
  // A copied node can contain secret-bearing protocol fields. Preserve the
  // existing flow: open it directly in the editor to revise host and port.
  const copy = useMutation({
    mutationFn: copyNode,
    onSuccess: async id => {
      toast.success('节点已复制，请修改名称、地址与端口')
      await qc.invalidateQueries({ queryKey: ['nodes'] })
      const refreshed = qc.getQueryData<NodeItem[]>(['nodes'])
      const copied = Array.isArray(refreshed) ? refreshed.find(node => node.id === id) : undefined
      if (copied) { setEditing(copied); setOpen(true) }
    },
  })

  const rows = Array.isArray(query.data) ? query.data : []
  const machineRows = Array.isArray(machines.data) ? machines.data : []
  const groupRows = Array.isArray(groups.data) ? groups.data : []
  const routeRows = Array.isArray(routes.data) ? routes.data : []
  const hasFilters = Boolean(search.trim() || machineFilter)
  const createNode = () => { setEditing(null); setOpen(true) }
  const clearFilters = () => { setSearch(''); setMachineFilter('') }

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rows.filter(node => {
      if (machineFilter && String(node.machine_id ?? '') !== machineFilter) return false
      if (!q) return true
      return [node.name, node.type, node.host, node.id, node.port, node.code]
        .some(value => String(value ?? '').toLowerCase().includes(q))
    })
  }, [rows, search, machineFilter])

  const machineNames = useMemo(() => new Map(machineRows.map(machine => [
    machine.id, machine.name || `Machine #${machine.id}`,
  ])), [machineRows])

  const columns: Column<NodeItem>[] = [
    {
      key: 'identity', header: '节点', width: '28%',
      render: node => <div className="nodes-v3-identity">
        <Link className="table-link nodes-v3-node-link" to={`/server/node/${node.id}`}>
          {node.name || `Node #${node.id}`}
        </Link>
        <span className="nodes-v3-secondary">#{node.id} · {node.type === 'hysteria' ? 'hysteria2' : node.type || '未知协议'}</span>
      </div>,
    },
    {
      key: 'address', header: '连接地址', width: '23%',
      render: node => <span className="nodes-v3-address" title={nodeConnectionLabel(node)}>
        {nodeConnectionLabel(node)}
      </span>,
    },
    { key: 'rate', header: '倍率', width: '9%',
      render: node => <span className="nodes-v3-number">{node.rate == null ? '—' : String(node.rate) + '×'}</span>,
    },
    {
      key: 'machine', header: '运行机器', width: '18%',
      render: node => node.machine_id ? (
        <Link className="table-link nodes-v3-machine" title={machineNames.get(node.machine_id) || `Machine #${node.machine_id}`}
          to={`/server/machine/${node.machine_id}`}>
          {machineNames.get(node.machine_id) || `Machine #${node.machine_id}`}
        </Link>
      ) : <span className="nodes-v3-secondary">未关联</span>,
    },
    {
      key: 'status', header: '状态', width: '13%',
      render: node => {
        const status = nodeStatus(node)
        return <span className={'nodes-v3-status is-' + status.tone}>
          <i aria-hidden="true"/>{status.label}
        </span>
      },
    },
    {
      key: 'actions', header: '操作', width: '9%',
      render: node => <NodeRowActions
        node={node} pending={copy.isPending || remove.isPending}
        onEdit={() => { setEditing(node); setOpen(true) }}
        onCopy={() => copy.mutate(node.id)}
        onDelete={() => requestConfirm({
          title: '删除节点',
          message: '删除节点 ' + (node.name || node.id) + '？删除后无法恢复。',
          danger: true, confirmLabel: '删除', action: () => remove.mutate(node.id),
        })}
      />,
    },
  ]

  const surface = listSurfaceState({
    count: filtered.length, loading: query.isLoading, error: query.isError,
  })

  return <div className="nodes-v3-page">
    <PageHeader
      title="节点管理"
      description="查看服务节点、协议和所属机器；列表每 30 秒自动刷新。"
      action={<button type="button" className="button primary" onClick={createNode}>
        <Plus size={16} aria-hidden="true"/>添加节点
      </button>}
    />

    <div className="nodes-v3-toolbar" role="search" aria-label="筛选节点">
      <label className="nodes-v3-search">
        <Search size={17} aria-hidden="true"/>
        <span className="sr-only">按节点名称、协议、地址或 ID 搜索</span>
        <input aria-label="搜索节点" value={search} onChange={event => setSearch(event.target.value)}
          placeholder="搜索名称、地址、协议或 ID…"/>
        {search ? <button type="button" className="nodes-v3-clear-search"
          aria-label="清空节点搜索词" onClick={() => setSearch('')}>
          <X size={15} aria-hidden="true"/>
        </button> : null}
      </label>
      <label className="nodes-v3-machine-filter">
        <span className="sr-only">按运行机器筛选节点</span>
        <Server size={15} aria-hidden="true"/>
        <select aria-label="筛选运行机器" value={machineFilter} onChange={event => setMachineFilter(event.target.value)}>
          <option value="">全部机器</option>
          {machineRows.map(machine => <option key={machine.id} value={String(machine.id)}>
            {machine.name || 'Machine ' + machine.id}
          </option>)}
        </select>
      </label>
      {hasFilters ? <button type="button" className="button nodes-v3-clear-filters" onClick={clearFilters}>
        清除筛选
      </button> : null}
      <button type="button" className="button nodes-v3-refresh" disabled={query.isFetching}
        onClick={() => void query.refetch()}>
        <RefreshCw size={16} aria-hidden="true" className={query.isFetching ? 'loading-spinner' : undefined}/>
        {query.isFetching ? '刷新中…' : '刷新'}
      </button>
    </div>

    {!query.isLoading && !query.isError ? <div className="nodes-v3-list-meta" aria-live="polite">
      <span>{hasFilters ? `显示 ${filtered.length} / ${rows.length} 个节点` : `共 ${rows.length} 个节点`}</span>
      {query.isFetching ? <span>正在同步最新状态…</span> : null}
    </div> : null}

    <div className={'server-table-card admin-v2-list-surface nodes-v3-table is-' + surface}>
      <DataTable
        label="节点列表" rows={filtered} columns={columns} rowKey={node => node.id}
        loading={query.isLoading} error={query.isError} onRetry={() => void query.refetch()}
        emptyState={hasFilters ? (
          <EmptyState className="admin-v2-list-empty" icon={<Search size={23}/>}
            title="没有找到匹配的节点"
            description="当前搜索或机器筛选条件下没有匹配结果。"
            action={<button type="button" className="button" onClick={clearFilters}>清除筛选</button>}/>
        ) : (
          <EmptyState className="admin-v2-list-empty" icon={<Network size={23}/>}
            title="还没有节点"
            description="创建第一个节点并配置协议；也可以将节点关联到已接入的服务器。"
            action={<button type="button" className="button primary" onClick={createNode}>
              <Plus size={16} aria-hidden="true"/>创建节点
            </button>}
            secondaryAction={<Link className="button" to="/server/machine">查看服务器</Link>}/>
        )}
      />
    </div>
    {surface === 'rows' ? <p className="nodes-v3-scroll-hint">左右滑动表格可查看全部节点信息与操作</p> : null}

    <NodeEditorModal
      open={open} node={editing} nodes={rows}
      machines={machineRows} groups={groupRows} routes={routeRows}
      protocolDefinitions={Array.isArray(protocolDefinitions.data) ? protocolDefinitions.data : []}
      saving={save.isPending}
      onClose={() => { setOpen(false); setEditing(null) }}
      onSubmit={payload => save.mutate(payload)}
    />
  </div>
}
