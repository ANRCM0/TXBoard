import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { deleteGroup, getGroups, saveGroup, type GroupItem } from '../../api/server'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

export function GroupsPage() {
  const qc = useQueryClient()
  const [editing, setEditing] = useState<GroupItem | null>(null)
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')

  const query = useQuery({ queryKey: ['groups'], queryFn: getGroups })

  const save = useMutation({
    mutationFn: () => saveGroup({ ...(editing ? { id: editing.id } : {}), name: name.trim() }),
    onSuccess: async () => {
      toast.success(editing ? '权限组已更新' : '权限组已创建')
      closeEditor()
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['groups'] }),
        qc.invalidateQueries({ queryKey: ['nodes'] }),
        qc.invalidateQueries({ queryKey: ['plans'] }),
        qc.invalidateQueries({ queryKey: ['adminUsers'] }),
      ])
    },
  })

  const remove = useMutation({
    mutationFn: deleteGroup,
    onSuccess: async () => {
      toast.success('权限组已删除')
      await qc.invalidateQueries({ queryKey: ['groups'] })
    },
  })

  const rows = Array.isArray(query.data) ? query.data : []

  function openCreate() {
    setEditing(null)
    setName('')
    setOpen(true)
  }

  function openEdit(group: GroupItem) {
    setEditing(group)
    setName(group.name || '')
    setOpen(true)
  }

  function closeEditor() {
    setOpen(false)
    setEditing(null)
    setName('')
  }

  function impact(group: GroupItem) {
    return Number(group.server_count || 0) + Number(group.plans_count || 0) + Number(group.users_count || 0)
  }

  const columns: Column<GroupItem>[] = [
    { key: 'id', header: 'ID', width: '72px', render: row => row.id },
    { key: 'name', header: '权限组', render: row => <strong>{row.name || `Group #${row.id}`}</strong> },
    {
      key: 'impact',
      header: '影响范围',
      render: row => <div className="impact-chips">
        <span className="badge">节点 {Number(row.server_count || 0)}</span>
        <span className="badge">套餐 {Number(row.plans_count || 0)}</span>
        <span className="badge">用户 {Number(row.users_count || 0)}</span>
      </div>,
    },
    {
      key: 'status',
      header: '删除条件',
      render: row => impact(row)
        ? <span className="muted">需先解除全部关联</span>
        : <span className="status ok">可删除</span>,
    },
    {
      key: 'actions',
      header: '操作',
      width: '110px',
      render: row => <div className="table-actions">
        <button className="icon-button" aria-label="编辑权限组" onClick={() => openEdit(row)}><Pencil size={15}/></button>
        <button
          className="icon-button danger"
          aria-label="删除权限组"
          disabled={remove.isPending || impact(row) > 0}
          title={impact(row) ? '该权限组仍被节点、套餐或用户使用' : '删除权限组'}
          onClick={() => requestConfirm({
            title: '删除权限组',
            message: `确认删除「${row.name || `Group #${row.id}`}」？该权限组当前没有节点、套餐或用户关联。`,
            danger: true,
            confirmLabel: '删除',
            action: () => remove.mutate(row.id),
          })}
        ><Trash2 size={15}/></button>
      </div>,
    },
  ]

  return <>
    <PageHeader
      title="权限组管理"
      description="权限组连接用户/套餐与节点。删除前必须先解除所有关联，避免产生悬空权限。"
      action={<button className="button primary" onClick={openCreate}><Plus size={16}/>添加权限组</button>}
    />

    <div className="card content-table-card">
      <DataTable
        rowKey={row => row.id}
        loading={query.isFetching}
        error={query.isError}
        onRetry={() => query.refetch()}
        rows={rows}
        columns={columns}
        empty="还没有权限组。"
      />
    </div>

    <Modal open={open} title={editing ? '编辑权限组' : '添加权限组'} onClose={closeEditor}>
      <div className="form-stack">
        {editing ? <div className="page-alert">
          当前关联：节点 {Number(editing.server_count || 0)} · 套餐 {Number(editing.plans_count || 0)} · 用户 {Number(editing.users_count || 0)}。重命名不会改变这些关联。
        </div> : null}
        <label className="field">
          <span>名称</span>
          <input value={name} onChange={event => setName(event.target.value)} placeholder="例如：标准节点组" autoFocus />
        </label>
        <div className="card-actions">
          <button className="button" onClick={closeEditor}>取消</button>
          <button
            className="button primary"
            disabled={save.isPending}
            onClick={() => {
              if (!name.trim()) return toast.error('请输入权限组名称')
              save.mutate()
            }}
          >{save.isPending ? '保存中…' : '保存'}</button>
        </div>
      </div>
    </Modal>
  </>
}
