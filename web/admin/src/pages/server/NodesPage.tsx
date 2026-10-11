import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, MoreHorizontal, Network, Pencil, Plus, RefreshCw, Search, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import {
  copyNode,
  deleteNode,
  getGroups,
  getMachines,
  getNodes,
  getProtocolDefinitions,
  getRoutes,
  saveNode,
  type NodeItem,
} from '../../api/server'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { PageHeader } from '../../components/ui/PageHeader'
import { EmptyState } from '../../components/ui/EmptyState'
import { listSurfaceState } from '../../components/ui/listSurfaceState'
import { NodeEditorModal } from './NodeEditorModal'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

export function NodesPage(){
  const qc=useQueryClient()
  const [open,setOpen]=useState(false)
  const [editing,setEditing]=useState<NodeItem|null>(null)
  const [search,setSearch]=useState('')
  const [machineFilter,setMachineFilter]=useState('')

  const query=useQuery({queryKey:['nodes'],queryFn:getNodes,refetchInterval:30_000})
  const protocolDefinitions=useQuery({queryKey:['protocol-definitions'],queryFn:getProtocolDefinitions,staleTime:300_000,retry:1})
  const machines=useQuery({queryKey:['machines'],queryFn:getMachines,staleTime:30_000})
  const groups=useQuery({queryKey:['groups'],queryFn:getGroups,staleTime:30_000})
  const routes=useQuery({queryKey:['routes'],queryFn:getRoutes,staleTime:30_000})

  const save=useMutation({
    mutationFn:(payload:Partial<NodeItem>)=>saveNode(payload),
    onSuccess:()=>{
      toast.success(editing?'节点已更新':'节点已创建')
      setOpen(false)
      setEditing(null)
      qc.invalidateQueries({queryKey:['nodes']})
    },
  })
  const remove=useMutation({
    mutationFn:deleteNode,
    onSuccess:()=>{
      toast.success('节点已删除')
      qc.invalidateQueries({queryKey:['nodes']})
    },
  })
  // Copying keeps the protocol settings (keys included); the editor opens so the
  // operator can rename the copy and point it at its own host/port.
  const copy=useMutation({
    mutationFn:copyNode,
    onSuccess:async id=>{
      toast.success('节点已复制，请修改名称、地址与端口')
      await qc.invalidateQueries({queryKey:['nodes']})
      const rows=qc.getQueryData<NodeItem[]>(['nodes'])
      const copied=Array.isArray(rows)?rows.find(row=>row.id===id):undefined
      if(copied){setEditing(copied);setOpen(true)}
    },
  })

  const rows=Array.isArray(query.data)?query.data:[]
  const machineRows=Array.isArray(machines.data)?machines.data:[]
  const groupRows=Array.isArray(groups.data)?groups.data:[]
  const routeRows=Array.isArray(routes.data)?routes.data:[]

  const hasFilters = Boolean(search.trim() || machineFilter)
  const createNode = () => { setEditing(null); setOpen(true) }
  const clearFilters = () => { setSearch(''); setMachineFilter('') }

  const filtered=useMemo(()=>{
    const q=search.trim().toLowerCase()
    return rows.filter(row=>{
      if(machineFilter && String(row.machine_id??'')!==machineFilter)return false
      if(!q)return true
      return (
        String(row.name||'').toLowerCase().includes(q) ||
        String(row.type||'').toLowerCase().includes(q) ||
        String(row.host||'').toLowerCase().includes(q) ||
        String(row.id).includes(q)
      )
    })
  },[rows,search,machineFilter])

  const columns:Column<NodeItem>[]=[
    {key:'id',header:'ID',width:'70px',render:row=>row.id},
    {key:'name',header:'名称',render:row=><Link className="table-link server-node-name" to={`/server/node/${row.id}`}><strong>{row.name||`Node #${row.id}`}</strong></Link>},
    {key:'type',header:'类型',render:row=><span className="admin-v2-muted">{row.type==='hysteria'?'hysteria2':row.type||'-'}</span>},
    {key:'host',header:'地址',render:row=>row.host?(String(row.host)+(row.port?':'+row.port:'')):'-'},
    {key:'rate',header:'倍率',render:row=>row.rate??'-'},
    {key:'machine',header:'机器',render:row=>{
      const machine=machineRows.find(item=>item.id===row.machine_id)
      return machine?<Link className="table-link" to={`/server/machine/${machine.id}`}>{machine.name||`Machine #${machine.id}`}</Link>:row.machine_id?`Machine #${row.machine_id}`:'-'
    }},
    {key:'status',header:'状态',render:row=><span className="machine-status"><i className={row.online?'online':'off'}/>{row.online?'在线':'离线'}</span>},
    {
      key:'actions',
      header:'操作',
      width:'72px',
      render:row=><details className="row-menu">
        <summary aria-label={'节点 '+(row.name||row.id)+' 的操作'}><MoreHorizontal size={18}/></summary>
        <div className="row-menu-popover">
          <button onClick={()=>{setEditing(row);setOpen(true)}}><Pencil size={15}/>编辑</button>
          <button disabled={copy.isPending} onClick={()=>copy.mutate(row.id)}><Copy size={15}/>复制</button>
          <button className="danger" disabled={remove.isPending} onClick={()=>requestConfirm({title:'删除节点',message:'删除节点 '+(row.name||row.id)+'？删除后无法恢复。',danger: true, confirmLabel: '删除',action:()=>remove.mutate(row.id)})}><Trash2 size={15}/>删除</button>
        </div>
      </details>,
    },
  ]

  return <>
    <PageHeader
      title="节点管理"
      description="管理服务节点、协议配置与运行机器；列表每 30 秒自动刷新。"
      action={<button className="button primary" onClick={createNode}><Plus size={16}/>添加节点</button>}
    />

    <div className="server-page-toolbar">
      <div className="server-search">
        <Search size={15}/>
        <input aria-label="搜索节点" value={search} onChange={event=>setSearch(event.target.value)} placeholder="搜索节点…" />
      </div>
      <select aria-label="筛选运行机器" value={machineFilter} onChange={event=>setMachineFilter(event.target.value)}>
        <option value="">全部机器</option>
        {machineRows.map(machine=><option key={machine.id} value={String(machine.id)}>{machine.name||'Machine '+machine.id}</option>)}
      </select>
      {(search||machineFilter)&&<button className="button" onClick={clearFilters}>清除筛选</button>}
      <button className="button" disabled={query.isFetching} onClick={()=>query.refetch()}><RefreshCw size={16} className={query.isFetching?'loading-spinner':undefined}/>{query.isFetching?'刷新中…':'刷新'}</button>
    </div>

    <div className={'server-table-card admin-v2-list-surface is-' + listSurfaceState({
      count: filtered.length, loading: query.isLoading, error: query.isError,
    })}>
      <DataTable
        rows={filtered} columns={columns} rowKey={row=>row.id}
        loading={query.isLoading} error={query.isError} onRetry={()=>query.refetch()}
        emptyState={hasFilters ? (
          <EmptyState className="admin-v2-list-empty" icon={<Search size={23}/>} title="没有找到匹配的节点"
            description="当前搜索或机器筛选条件下没有匹配结果，请调整条件后重试。"
            action={<button type="button" className="button" onClick={clearFilters}>清除筛选</button>}/>
        ) : (
          <EmptyState className="admin-v2-list-empty" icon={<Network size={23}/>} title="还没有节点"
            description="创建第一个节点并配置协议；如有需要，可以将节点关联到已接入的服务器。"
            action={<button type="button" className="button primary" onClick={createNode}><Plus size={16}/>创建第一个节点</button>}
            secondaryAction={<Link className="button" to="/server/machine">查看服务器</Link>}/>
        )}
      />
    </div>

    <NodeEditorModal
      open={open}
      node={editing}
      nodes={rows}
      machines={machineRows}
      groups={groupRows}
      routes={routeRows}
      protocolDefinitions={Array.isArray(protocolDefinitions.data)?protocolDefinitions.data:[]}
      saving={save.isPending}
      onClose={()=>{setOpen(false);setEditing(null)}}
      onSubmit={(payload)=>save.mutate(payload)}
    />
  </>
}
