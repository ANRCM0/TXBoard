import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { GripVertical, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import {
  deletePlan,
  getPlans,
  sortPlans,
  updatePlanFlags,
  type PlanItem,
} from '../../api/finance'
import { getGroups } from '../../api/server'
import { PlanEditor } from '../../components/finance/PlanEditor'
import { PageHeader } from '../../components/ui/PageHeader'

export function PlansPage() {
  const qc = useQueryClient()
  const query = useQuery({ queryKey: ['plans'], queryFn: getPlans })
  const groupsQuery = useQuery({ queryKey: ['groups'], queryFn: getGroups })
  const [rows, setRows] = useState<PlanItem[]>([])
  const [editing, setEditing] = useState<PlanItem | null>(null)
  const [editorOpen, setEditorOpen] = useState(false)
  const [dragId, setDragId] = useState<number | null>(null)
  const [search, setSearch] = useState('')

  useEffect(() => {
    if (Array.isArray(query.data)) setRows(query.data)
  }, [query.data])

  const refresh = () => qc.invalidateQueries({ queryKey: ['plans'] })

  const remove = useMutation({
    mutationFn: deletePlan,
    onSuccess: () => {
      toast.success('套餐已删除')
      refresh()
    },
  })

  const flags = useMutation({
    mutationFn: ({ id, key, value }: { id: number; key: 'show' | 'sell' | 'renew'; value: boolean }) =>
      updatePlanFlags(id, { [key]: value }),
    onSuccess: () => refresh(),
  })

  const sort = useMutation({
    mutationFn: sortPlans,
    onSuccess: () => {
      toast.success('套餐顺序已保存')
      refresh()
    },
  })

  const visibleRows = rows.filter(plan => {
    const q = search.trim().toLowerCase()
    if (!q) return true
    return (
      String(plan.name || '').toLowerCase().includes(q) ||
      String(plan.id).includes(q) ||
      (plan.tags || []).some(tag => tag.toLowerCase().includes(q))
    )
  })

  function startCreate() {
    setEditing(null)
    setEditorOpen(true)
  }

  function startEdit(plan: PlanItem) {
    setEditing(plan)
    setEditorOpen(true)
  }

  function dropOn(targetId: number) {
    if (dragId === null || dragId === targetId) return
    const next = [...rows]
    const from = next.findIndex(item => item.id === dragId)
    const to = next.findIndex(item => item.id === targetId)
    if (from < 0 || to < 0) return
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)
    setRows(next)
    setDragId(null)
    sort.mutate(next.map(item => item.id))
  }

  return <>
    <PageHeader
      title="套餐管理"
      description="管理套餐价格、流量、限制、可见性和排序。"
    />

    <div className="finance-page-toolbar">
      <button className="button" onClick={startCreate}><Plus size={16}/>添加套餐</button>
      <div className="finance-search">
        <Search size={15}/>
        <input value={search} onChange={event => setSearch(event.target.value)} placeholder="搜索套餐…" />
      </div>
      <span className="toolbar-hint">拖动左侧手柄调整排序</span>
    </div>

    <div className="plan-table-card">
      <div className="plan-table-wrap">
        <table className="data-table plan-table">
          <thead>
            <tr>
              <th className="drag-col"/>
              <th>套餐</th>
              <th>流量</th>
              <th>价格</th>
              <th>用户</th>
              <th>显示</th>
              <th>销售</th>
              <th>续费</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {visibleRows.map(plan => <tr
              key={plan.id}
              draggable
              className={dragId === plan.id ? 'is-dragging' : ''}
              onDragStart={() => setDragId(plan.id)}
              onDragEnd={() => setDragId(null)}
              onDragOver={event => event.preventDefault()}
              onDrop={() => dropOn(plan.id)}
            >
              <td className="drag-col"><GripVertical size={17}/></td>
              <td>
                <div className="plan-name">
                  <strong>{plan.name}</strong>
                  <div className="plan-tags">
                    {plan.group?.name && <span className="badge">{plan.group.name}</span>}
                    {(plan.tags || []).slice(0, 3).map(tag => <span className="badge" key={tag}>{tag}</span>)}
                  </div>
                </div>
              </td>
              <td>
                <strong>{plan.transfer_enable} GB</strong>
                <small className="table-sub">{limitText(plan)}</small>
              </td>
              <td><PriceSummary plan={plan}/></td>
              <td>
                <strong>{Number(plan.active_users_count || 0)}</strong>
                <small className="table-sub">活跃 / {Number(plan.users_count || 0)} 总计</small>
              </td>
              <td><Toggle checked={Boolean(plan.show)} onChange={value => flags.mutate({ id: plan.id, key: 'show', value })}/></td>
              <td><Toggle checked={Boolean(plan.sell)} onChange={value => flags.mutate({ id: plan.id, key: 'sell', value })}/></td>
              <td><Toggle checked={Boolean(plan.renew)} onChange={value => flags.mutate({ id: plan.id, key: 'renew', value })}/></td>
              <td>
                <div className="actions">
                  <button className="icon-button" title="编辑" onClick={() => startEdit(plan)}><Pencil size={15}/></button>
                  <button
                    className="icon-button danger"
                    title="删除"
                    onClick={() => confirm(`确认删除套餐「${plan.name}」？存在订单或用户时后端会拒绝删除。`) && remove.mutate(plan.id)}
                  ><Trash2 size={15}/></button>
                </div>
              </td>
            </tr>)}
            {!visibleRows.length && <tr><td colSpan={9} className="empty-cell">{query.isLoading ? '加载中…' : '暂无套餐'}</td></tr>}
          </tbody>
        </table>
      </div>
      <div className="plan-table-footer">
        <span>拖动左侧手柄可调整套餐顺序，松开后自动保存。</span>
        {sort.isPending && <span>正在保存顺序…</span>}
      </div>
    </div>

    <PlanEditor
      open={editorOpen}
      plan={editing}
      groups={Array.isArray(groupsQuery.data) ? groupsQuery.data : []}
      onClose={() => setEditorOpen(false)}
      onSaved={() => refresh()}
    />
  </>
}

function Toggle({ checked, onChange }: { checked: boolean; onChange: (value: boolean) => void }) {
  return <button
    className={checked ? 'switch-control active' : 'switch-control'}
    role="switch"
    aria-checked={checked}
    onClick={() => onChange(!checked)}
  ><span/></button>
}

function PriceSummary({ plan }: { plan: PlanItem }) {
  const entries = Object.entries(plan.prices || {}).filter(([, value]) => Number(value) > 0)
  if (!entries.length) return <span className="price-empty-badge">未定价</span>
  return <div className="plan-price-badges">
    {entries.map(([period, value]) => (
      <span className="plan-price-badge" key={period}>
        {periodLabel(period)} ¥ {Number(value).toFixed(2)}
      </span>
    ))}
  </div>
}

function periodLabel(period: string) {
  return ({
    monthly: '月付',
    quarterly: '季付',
    half_yearly: '半年付',
    yearly: '年付',
    two_yearly: '两年付',
    three_yearly: '三年付',
    onetime: '一次性',
    reset_traffic: '重置流量',
  } as Record<string, string>)[period] || period
}

function limitText(plan: PlanItem) {
  const parts: string[] = []
  if (plan.speed_limit) parts.push(`${plan.speed_limit} Mbps`)
  if (plan.device_limit) parts.push(`${plan.device_limit} 设备`)
  if (plan.capacity_limit) parts.push(`${plan.capacity_limit} 人`)
  return parts.length ? parts.join(' · ') : '无限速 / 无设备限制'
}
