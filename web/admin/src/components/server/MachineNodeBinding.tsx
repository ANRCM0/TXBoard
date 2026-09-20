import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link2, Search, Unlink } from 'lucide-react'
import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { batchUpdateNodes, getMachineNodes, getNodes, type NodeItem } from '../../api/server'
import { DataTable, type Column } from '../ui/DataTable'

export function MachineNodeBinding({ machineId }: { machineId: number }) {
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<number[]>([])

  const boundQuery = useQuery({
    queryKey: ['machineNodes', machineId],
    queryFn: () => getMachineNodes(machineId),
  })
  const allQuery = useQuery({
    queryKey: ['nodes'],
    queryFn: getNodes,
  })

  const bound = Array.isArray(boundQuery.data) ? boundQuery.data : []
  const all = Array.isArray(allQuery.data) ? allQuery.data : []
  const boundIds = useMemo(() => new Set(bound.map(node => node.id)), [bound])

  const available = useMemo(() => {
    const keyword = search.trim().toLowerCase()
    return all.filter(node => {
      if (boundIds.has(node.id)) return false
      if (node.machine_id !== null && node.machine_id !== undefined && Number(node.machine_id) !== 0) return false
      if (!keyword) return true
      return `${node.name || ''} ${node.type || ''} ${node.host || ''}`.toLowerCase().includes(keyword)
    })
  }, [all, boundIds, search])

  const invalidate = async () => {
    setSelected([])
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['machineNodes', machineId] }),
      qc.invalidateQueries({ queryKey: ['nodes'] }),
      qc.invalidateQueries({ queryKey: ['machines'] }),
    ])
  }

  const bind = useMutation({
    mutationFn: (ids: number[]) => batchUpdateNodes(ids, { machine_id: machineId }),
    onSuccess: async () => {
      toast.success('节点已绑定')
      await invalidate()
    },
  })

  const unbind = useMutation({
    mutationFn: (ids: number[]) => batchUpdateNodes(ids, { machine_id: null }),
    onSuccess: async () => {
      toast.success('节点已解绑')
      await invalidate()
    },
  })

  const boundColumns: Column<NodeItem>[] = [
    { key: 'id', header: 'ID', render: row => row.id },
    { key: 'name', header: '节点', render: row => <strong>{row.name || `Node ${row.id}`}</strong> },
    { key: 'type', header: '类型', render: row => <span className="badge">{row.type || '-'}</span> },
    { key: 'host', header: '地址', render: row => row.host ? `${row.host}${row.port ? ':' + row.port : ''}` : '-' },
    { key: 'enabled', header: '启用', render: row => <span className={row.enabled === false ? 'status off' : 'status ok'}>{row.enabled === false ? '否' : '是'}</span> },
    { key: 'action', header: '操作', render: row => <button className="icon-button danger" title="解绑" onClick={() => unbind.mutate([row.id])}><Unlink size={15}/></button> },
  ]

  return <div className="machine-binding">
    <section className="binding-section">
      <div className="binding-head">
        <div><strong>已绑定节点</strong><span className="muted">{bound.length} 个</span></div>
      </div>
      <DataTable rows={bound} columns={boundColumns} empty="当前机器尚未绑定节点"/>
    </section>

    <section className="binding-section">
      <div className="binding-head">
        <div><strong>绑定新节点</strong><span className="muted">仅显示未绑定机器的节点</span></div>
        <div className="binding-search"><Search size={15}/><input value={search} onChange={e => setSearch(e.target.value)} placeholder="搜索名称 / 类型 / 地址"/></div>
      </div>

      <div className="node-picker">
        {available.map(node => {
          const checked = selected.includes(node.id)
          return <label className={checked ? 'node-pick active' : 'node-pick'} key={node.id}>
            <input
              type="checkbox"
              checked={checked}
              onChange={e => setSelected(current => e.target.checked ? [...current, node.id] : current.filter(id => id !== node.id))}
            />
            <span><strong>{node.name || `Node ${node.id}`}</strong><small>{node.type || '-'} · {node.host || '-'}</small></span>
          </label>
        })}
        {!available.length && <div className="empty-state">没有可绑定的节点。</div>}
      </div>

      <div className="card-actions">
        <button className="button primary" disabled={!selected.length || bind.isPending} onClick={() => bind.mutate(selected)}>
          <Link2 size={15}/>{bind.isPending ? '绑定中…' : `绑定 ${selected.length || ''} 个节点`}
        </button>
      </div>
    </section>
  </div>
}
