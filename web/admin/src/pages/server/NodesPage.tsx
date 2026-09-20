import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MoreHorizontal, Plus, RefreshCw, Search, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { deleteNode, getMachines, getNodes, saveNode, type NodeItem } from '../../api/server'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'

export function NodesPage(){
  const qc=useQueryClient()
  const [open,setOpen]=useState(false)
  const [name,setName]=useState('')
  const [type,setType]=useState('shadowsocks')
  const [search,setSearch]=useState('')
  const [machineFilter,setMachineFilter]=useState('')

  const query=useQuery({queryKey:['nodes'],queryFn:getNodes,refetchInterval:30_000})
  const machines=useQuery({queryKey:['machines'],queryFn:getMachines,staleTime:30_000})

  const create=useMutation({
    mutationFn:()=>saveNode({name,type}),
    onSuccess:()=>{
      toast.success('节点已创建')
      setOpen(false)
      setName('')
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

  const rows=Array.isArray(query.data)?query.data:[]
  const machineRows=Array.isArray(machines.data)?machines.data:[]

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
    {key:'name',header:'名称',render:row=><strong className="server-node-name">{row.name||'-'}</strong>},
    {key:'type',header:'类型',render:row=><span className="badge">{row.type||'-'}</span>},
    {key:'host',header:'地址',render:row=>row.host?`${row.host}${row.port?':'+row.port:''}`:'-'},
    {key:'machine',header:'机器',render:row=>machineRows.find(machine=>machine.id===row.machine_id)?.name||row.machine_id||'-'},
    {key:'status',header:'状态',render:row=><span className="machine-status"><i className={row.online?'online':'off'}/>{row.online?'在线':'离线'}</span>},
    {
      key:'actions',
      header:'操作',
      width:'72px',
      render:row=><details className="row-menu">
        <summary><MoreHorizontal size={18}/></summary>
        <div className="row-menu-popover">
          <button className="danger" onClick={()=>confirm(`删除节点 ${row.name||row.id}？`)&&remove.mutate(row.id)}><Trash2 size={15}/>删除</button>
        </div>
      </details>,
    },
  ]

  return <>
    <PageHeader
      title="节点管理"
      description="管理服务节点；列表每 30 秒自动刷新。"
      action={<button className="button primary" onClick={()=>setOpen(true)}><Plus size={16}/>添加节点</button>}
    />

    <div className="server-page-toolbar">
      <div className="server-search">
        <Search size={15}/>
        <input value={search} onChange={event=>setSearch(event.target.value)} placeholder="搜索节点…" />
      </div>
      <select value={machineFilter} onChange={event=>setMachineFilter(event.target.value)}>
        <option value="">全部机器</option>
        {machineRows.map(machine=><option key={machine.id} value={String(machine.id)}>{machine.name||`Machine ${machine.id}`}</option>)}
      </select>
      <button className="button" onClick={()=>query.refetch()}><RefreshCw size={16}/>刷新</button>
    </div>

    <div className="server-table-card"><DataTable rows={filtered} columns={columns}/></div>

    <Modal open={open} title="添加节点" onClose={()=>setOpen(false)}>
      <div className="form-stack">
        <label className="field"><span>名称</span><input value={name} onChange={event=>setName(event.target.value)}/></label>
        <label className="field"><span>类型</span><select value={type} onChange={event=>setType(event.target.value)}><option>shadowsocks</option><option>trojan</option><option>vless</option><option>hysteria2</option></select></label>
        <div className="modal-actions">
          <button className="button" onClick={()=>setOpen(false)}>取消</button>
          <button className="button primary" disabled={!name||create.isPending} onClick={()=>create.mutate()}>{create.isPending?'创建中…':'创建'}</button>
        </div>
      </div>
    </Modal>
  </>
}
