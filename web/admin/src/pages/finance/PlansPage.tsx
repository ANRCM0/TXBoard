import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, GripVertical, PackageOpen, Pencil, Plus, Search, Trash2 } from 'lucide-react'
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
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { IconAction, SettingSwitch } from '../../components/ui/AccessibleControls'
import { EmptyState } from '../../components/ui/EmptyState'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { dropPlan, movePlan } from './planReorder'
import './PlansPage.css'

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
    onError: () => {
      toast.error('排序保存失败，已恢复此前顺序')
      refresh()
    },
  })

  const hasSearch = Boolean(search.trim())
  // Sorting filtered subsets is ambiguous; require the complete list.
  const canReorder = !hasSearch && !sort.isPending && !query.isFetching && !query.isError

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

  function commitOrder(next: PlanItem[] | null) {
    if (!next || !canReorder) return
    const previous = rows
    setRows(next)
    sort.mutate(next.map(item => item.id), {
      onError: () => setRows(previous),
    })
  }

  function shiftPlan(id: number, direction: -1 | 1) {
    commitOrder(movePlan(rows, id, direction))
  }

  function dropOn(targetId: number) {
    if (canReorder) commitOrder(dropPlan(rows, dragId, targetId))
    setDragId(null)
  }

  const showEmpty = !query.isLoading && !query.isError && visibleRows.length === 0

  return <>
    <PageHeader
      title="套餐管理"
      description="管理套餐价格、流量、限制、可见性和排序。"
      action={<button className="button primary" type="button" onClick={startCreate}>
        <Plus size={16} aria-hidden="true"/>添加套餐
      </button>}
    />

    <div className="plans-v4-toolbar" role="search" aria-label="筛选套餐">
      <div className="plans-v4-search">
        <Search size={16} aria-hidden="true"/>
        <input aria-label="搜索套餐名称、编号或标签" value={search}
          onChange={event => setSearch(event.target.value)} placeholder="搜索套餐…" />
      </div>
      {hasSearch ? <button className="button" type="button" onClick={() => setSearch('')}>清除搜索</button> : null}
      <span className="plans-v4-count" aria-live="polite">
        {hasSearch ? `显示 ${visibleRows.length} / ${rows.length} 个套餐` : `共 ${rows.length} 个套餐`}
      </span>
    </div>

    <QueryFeedback loading={query.isLoading && rows.length === 0}
      error={query.isError} onRetry={() => void query.refetch()}/>

    {showEmpty ? <EmptyState
      className="plans-v4-empty"
      icon={<PackageOpen size={21}/>}
      title={hasSearch ? '没有找到匹配的套餐' : '还没有套餐'}
      description={hasSearch ? '请更换搜索词，或清除搜索查看全部套餐。' : '创建第一个套餐，开始配置流量与价格。'}
      action={hasSearch
        ? <button className="button" type="button" onClick={() => setSearch('')}>清除搜索</button>
        : <button className="button primary" type="button" onClick={startCreate}>添加套餐</button>}
    /> : null}

    {visibleRows.length > 0 ? <section className="plans-v4-surface" aria-label="套餐管理列表">
      <div className="plans-v4-table-wrap" role="region" aria-label="套餐列表" tabIndex={0}>
        <table className="data-table plan-table plans-v4-table">
          <thead>
            <tr>
              <th scope="col" className="plans-v4-sort-col">排序</th>
              <th scope="col">套餐</th>
              <th scope="col">流量</th>
              <th scope="col">价格</th>
              <th scope="col">用户</th>
              <th scope="col">显示</th>
              <th scope="col">销售</th>
              <th scope="col">续费</th>
              <th scope="col">操作</th>
            </tr>
          </thead>
          <tbody>
            {visibleRows.map((plan, index) => <tr
              key={plan.id}
              className={dragId === plan.id ? 'is-dragging' : ''}
              onDragOver={event => { if (canReorder && dragId !== null) event.preventDefault() }}
              onDrop={() => dropOn(plan.id)}
            >
              <td className="plans-v4-sort-col">
                <div className="plans-v4-sort-actions">
                  <span className="plans-v4-drag-handle" draggable={canReorder}
                    aria-hidden="true" title="拖动调整套餐顺序"
                    onDragStart={event => { event.dataTransfer.effectAllowed = 'move'; setDragId(plan.id) }}
                    onDragEnd={() => setDragId(null)}>
                    <GripVertical size={17} aria-hidden="true"/>
                  </span>
                  <div className="plans-v4-sort-buttons">
                    <button type="button" className="plans-v4-sort-button"
                      aria-label={`上移套餐 ${plan.name}`}
                      disabled={!canReorder || index === 0}
                      onClick={() => shiftPlan(plan.id, -1)}>
                      <ArrowUp size={15} aria-hidden="true"/>
                    </button>
                    <button type="button" className="plans-v4-sort-button"
                      aria-label={`下移套餐 ${plan.name}`}
                      disabled={!canReorder || index === rows.length - 1}
                      onClick={() => shiftPlan(plan.id, 1)}>
                      <ArrowDown size={15} aria-hidden="true"/>
                    </button>
                  </div>
                </div>
              </td>
              <td>
                <div className="plan-name">
                  <strong>{plan.name}</strong>
                  <small className="plans-v4-meta">
                    {[plan.group?.name, ...(plan.tags || [])].filter(Boolean).join(' · ') || `#${plan.id}`}
                  </small>
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
              <td><SettingSwitch label={`显示套餐 ${plan.name}`}
                checked={Boolean(plan.show)} disabled={flags.isPending}
                onChange={value => flags.mutate({ id: plan.id, key: 'show', value })}/></td>
              <td><SettingSwitch label={`销售套餐 ${plan.name}`}
                checked={Boolean(plan.sell)} disabled={flags.isPending}
                onChange={value => flags.mutate({ id: plan.id, key: 'sell', value })}/></td>
              <td><SettingSwitch label={`允许续费套餐 ${plan.name}`}
                checked={Boolean(plan.renew)} disabled={flags.isPending}
                onChange={value => flags.mutate({ id: plan.id, key: 'renew', value })}/></td>
              <td>
                <div className="actions plans-v4-row-actions">
                  <IconAction label={`编辑套餐 ${plan.name}`} disabled={remove.isPending}
                    onClick={() => startEdit(plan)}><Pencil size={16} aria-hidden="true"/></IconAction>
                  <IconAction label={`删除套餐 ${plan.name}`} className="danger"
                    disabled={remove.isPending}
                    onClick={() => requestConfirm({ title: '删除套餐', message: `确认删除套餐「${plan.name}」？存在订单或用户时后端会拒绝删除。`, danger: true, confirmLabel: '删除', action: () => remove.mutate(plan.id) })}>
                    <Trash2 size={16} aria-hidden="true"/>
                  </IconAction>
                </div>
              </td>
            </tr>)}
          </tbody>
        </table>
      </div>
      {rows.length > 1 ? <div className="plans-v4-footer" role="status">
        {sort.isPending ? '正在保存顺序…' : hasSearch ? '清除搜索后可调整排序。' : '拖动手柄，或使用上移、下移按钮调整顺序。'}
      </div> : null}
    </section> : null}

    <PlanEditor
      open={editorOpen}
      plan={editing}
      groups={Array.isArray(groupsQuery.data) ? groupsQuery.data : []}
      onClose={() => setEditorOpen(false)}
      onSaved={() => refresh()}
    />
  </>
}

function PriceSummary({ plan }: { plan: PlanItem }) {
  const entries = Object.entries(plan.prices || {}).filter(([, value]) => Number(value) > 0)
  if (!entries.length) return <span className="plans-v4-meta">未定价</span>
  return <div className="plans-v4-prices">
    {entries.map(([period, value]) => (
      <span key={period}>{periodLabel(period)} ¥ {Number(value).toFixed(2)}</span>
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
