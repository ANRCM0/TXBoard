import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Download, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import {
  deleteCoupon,
  generateCouponsCsv,
  getCoupons,
  saveCoupon,
  toggleCoupon,
  type CouponItem,
  type CouponPayload,
} from '../../api/coupon'
import { getPlans } from '../../api/finance'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'

const PERIODS = [
  ['monthly', '月付'],
  ['quarterly', '季付'],
  ['half_yearly', '半年付'],
  ['yearly', '年付'],
  ['two_yearly', '两年付'],
  ['three_yearly', '三年付'],
  ['onetime', '一次性'],
  ['reset_traffic', '重置流量'],
] as const

type Draft = {
  name: string
  code: string
  type: number
  value: string
  limit_use: string
  limit_use_with_user: string
  limit_plan_ids: number[]
  limit_period: string[]
  started_at: string
  ended_at: string
  generate_count: string
}

function blank(): Draft {
  return {
    name: '',
    code: '',
    type: 1,
    value: '',
    limit_use: '',
    limit_use_with_user: '',
    limit_plan_ids: [],
    limit_period: [],
    started_at: '',
    ended_at: '',
    generate_count: '',
  }
}

export function CouponPage() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<CouponItem | null>(null)
  const [draft, setDraft] = useState<Draft>(blank())

  const filters: Array<{ id: string; value: string | number | number[] }> = []
  if (appliedSearch.trim()) filters.push({ id: 'code', value: appliedSearch.trim() })
  if (typeFilter) filters.push({ id: 'type', value: Number(typeFilter) })

  const query = useQuery({
    queryKey: ['coupons', page, pageSize, appliedSearch, typeFilter],
    queryFn: () => getCoupons({
      current: page,
      pageSize,
      ...(filters.length ? { filter: filters } : {}),
    }),
    placeholderData: previous => previous,
  })

  const plansQuery = useQuery({ queryKey: ['plans'], queryFn: getPlans })
  const plans = Array.isArray(plansQuery.data) ? plansQuery.data : []
  const rows = query.data?.data || []

  const refresh = () => qc.invalidateQueries({ queryKey: ['coupons'] })

  const save = useMutation({
    mutationFn: async () => {
      const payload = buildPayload(draft, editing?.id)
      if (!editing && Number(draft.generate_count) > 0) {
        await generateCouponsCsv({
          ...payload,
          generate_count: Number(draft.generate_count),
        })
        return
      }
      return saveCoupon(payload)
    },
    onSuccess: () => {
      toast.success(editing ? '优惠券已更新' : Number(draft.generate_count) > 0 ? '批量优惠券已生成并下载' : '优惠券已创建')
      setDialogOpen(false)
      refresh()
    },
  })

  const toggle = useMutation({
    mutationFn: toggleCoupon,
    onSuccess: () => refresh(),
  })

  const remove = useMutation({
    mutationFn: deleteCoupon,
    onSuccess: () => {
      toast.success('优惠券已删除')
      refresh()
    },
  })

  function openCreate() {
    setEditing(null)
    setDraft(blank())
    setDialogOpen(true)
  }

  function openEdit(row: CouponItem) {
    setEditing(row)
    setDraft({
      name: row.name || '',
      code: row.code || '',
      type: Number(row.type || 1),
      value: row.type === 1 ? String(Number(row.value || 0) / 100) : String(row.value || 0),
      limit_use: row.limit_use == null ? '' : String(row.limit_use),
      limit_use_with_user: row.limit_use_with_user == null ? '' : String(row.limit_use_with_user),
      limit_plan_ids: Array.isArray(row.limit_plan_ids) ? row.limit_plan_ids : [],
      limit_period: Array.isArray(row.limit_period) ? row.limit_period : [],
      started_at: toLocal(row.started_at),
      ended_at: toLocal(row.ended_at),
      generate_count: '',
    })
    setDialogOpen(true)
  }

  function saveNow() {
    if (!draft.name.trim()) return toast.error('请输入优惠券名称')
    if (!(Number(draft.value) > 0)) return toast.error('优惠值必须大于 0')
    if (draft.type === 2 && Number(draft.value) > 100) return toast.error('折扣百分比不能超过 100')
    if (draft.started_at && draft.ended_at && new Date(draft.started_at) >= new Date(draft.ended_at)) {
      return toast.error('结束时间必须晚于开始时间')
    }
    save.mutate()
  }

  return <>
    <PageHeader
      title="优惠券"
      description="固定金额/百分比优惠、套餐与周期限制、有效期、使用次数及批量生成。"
      action={<button className="button primary" onClick={openCreate}><Plus size={15}/>添加优惠券</button>}
    />

    <div className="coupon-toolbar">
      <div className="order-search">
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && (setAppliedSearch(search), setPage(1))}
          placeholder="搜索优惠码…"
        />
        <button className="button" onClick={() => { setAppliedSearch(search); setPage(1) }}><Search size={15}/>搜索</button>
      </div>
      <select value={typeFilter} onChange={e => { setTypeFilter(e.target.value); setPage(1) }}>
        <option value="">全部类型</option>
        <option value="1">固定金额</option>
        <option value="2">百分比</option>
      </select>
      {(appliedSearch || typeFilter) && <button className="button" onClick={() => { setSearch(''); setAppliedSearch(''); setTypeFilter(''); setPage(1) }}>清除</button>}
    </div>

    <div className="card coupon-table-card">
      <div className="table-wrap"><table className="data-table">
        <thead><tr><th>ID</th><th>名称</th><th>优惠码</th><th>类型</th><th>优惠值</th><th>总次数</th><th>单用户</th><th>有效期</th><th>显示</th><th>操作</th></tr></thead>
        <tbody>
          {rows.map(row => <tr key={row.id}>
            <td>{row.id}</td>
            <td><strong>{row.name || '-'}</strong></td>
            <td><code>{row.code || '-'}</code></td>
            <td><span className="badge">{row.type === 2 ? '百分比' : '固定金额'}</span></td>
            <td><strong>{row.type === 2 ? Number(row.value || 0) + '%' : '¥ ' + (Number(row.value || 0) / 100).toFixed(2)}</strong></td>
            <td>{row.limit_use ?? '不限'}</td>
            <td>{row.limit_use_with_user ?? '不限'}</td>
            <td>{validity(row)}</td>
            <td><button className={Boolean(row.show) ? 'switch-control active' : 'switch-control'} onClick={() => toggle.mutate(row.id)}><span/></button></td>
            <td><div className="actions">
              <button className="icon-button" onClick={() => openEdit(row)}><Pencil size={15}/></button>
              <button className="icon-button danger" onClick={() => confirm('确认删除该优惠券？') && remove.mutate(row.id)}><Trash2 size={15}/></button>
            </div></td>
          </tr>)}
          {!rows.length && <tr><td colSpan={10} className="empty-cell">{query.isLoading ? '加载中…' : '暂无优惠券'}</td></tr>}
        </tbody>
      </table></div>
      <div className="pagination-bar">
        <span className="pagination-meta">共 {query.data?.total || 0} 条 · 第 {query.data?.current_page || page} / {query.data?.last_page || 1} 页</span>
        <div className="pagination-actions">
          <select value={pageSize} onChange={e => { setPageSize(Number(e.target.value)); setPage(1) }}>
            <option value={10}>10 / 页</option><option value={20}>20 / 页</option><option value={50}>50 / 页</option>
          </select>
          <button className="icon-button" disabled={page <= 1} onClick={() => setPage(v => Math.max(1, v - 1))}><ChevronLeft size={16}/></button>
          <button className="icon-button" disabled={page >= Number(query.data?.last_page || 1)} onClick={() => setPage(v => v + 1)}><ChevronRight size={16}/></button>
        </div>
      </div>
    </div>

    <Modal open={dialogOpen} title={editing ? '编辑优惠券' : '添加优惠券'} onClose={() => setDialogOpen(false)}>
      <div className="coupon-editor form-stack">
        <div className="settings-grid">
          <Field label="名称 *"><input value={draft.name} onChange={e => setDraft(v => ({ ...v, name: e.target.value }))}/></Field>
          <Field label="优惠码"><input value={draft.code} disabled={Boolean(editing)} placeholder="留空自动生成" onChange={e => setDraft(v => ({ ...v, code: e.target.value }))}/></Field>
          <Field label="类型"><select value={draft.type} onChange={e => setDraft(v => ({ ...v, type: Number(e.target.value) }))}><option value={1}>固定金额</option><option value={2}>百分比</option></select></Field>
          <Field label={draft.type === 1 ? '优惠金额（元）' : '优惠百分比（%）'}><input type="number" min="0" max={draft.type === 2 ? 100 : undefined} step={draft.type === 1 ? '0.01' : '1'} value={draft.value} onChange={e => setDraft(v => ({ ...v, value: e.target.value }))}/></Field>
          <Field label="总使用次数"><input type="number" min="1" value={draft.limit_use} placeholder="留空不限" onChange={e => setDraft(v => ({ ...v, limit_use: e.target.value }))}/></Field>
          <Field label="单用户使用次数"><input type="number" min="1" value={draft.limit_use_with_user} placeholder="留空不限" onChange={e => setDraft(v => ({ ...v, limit_use_with_user: e.target.value }))}/></Field>
          {!editing && <Field label="批量生成数量"><input type="number" min="1" value={draft.generate_count} placeholder="留空只生成一张；填写后下载 CSV" onChange={e => setDraft(v => ({ ...v, generate_count: e.target.value }))}/></Field>}
          <Field label="开始时间"><input type="datetime-local" value={draft.started_at} onChange={e => setDraft(v => ({ ...v, started_at: e.target.value }))}/></Field>
          <Field label="结束时间"><input type="datetime-local" value={draft.ended_at} onChange={e => setDraft(v => ({ ...v, ended_at: e.target.value }))}/></Field>
        </div>

        <section className="coupon-section">
          <h4>限定套餐</h4>
          <div className="option-grid">{plans.map(plan => <label className={draft.limit_plan_ids.includes(plan.id) ? 'option-chip active' : 'option-chip'} key={plan.id}>
            <input type="checkbox" checked={draft.limit_plan_ids.includes(plan.id)} onChange={() => setDraft(v => ({ ...v, limit_plan_ids: toggleIn(v.limit_plan_ids, plan.id) }))}/>
            <span>{plan.name}</span>
          </label>)}</div>
          {!plans.length && <span className="muted">暂无套餐或未加载。</span>}
        </section>

        <section className="coupon-section">
          <h4>限定周期</h4>
          <div className="option-grid">{PERIODS.map(([key,label]) => <label className={draft.limit_period.includes(key) ? 'option-chip active' : 'option-chip'} key={key}>
            <input type="checkbox" checked={draft.limit_period.includes(key)} onChange={() => setDraft(v => ({ ...v, limit_period: toggleIn(v.limit_period, key) }))}/>
            <span>{label}</span>
          </label>)}</div>
        </section>

        <div className="card-actions">
          <button className="button" onClick={() => setDialogOpen(false)}>取消</button>
          <button className="button primary" disabled={save.isPending} onClick={saveNow}>
            {!editing && Number(draft.generate_count) > 0 && <Download size={15}/>}
            {save.isPending ? '处理中…' : !editing && Number(draft.generate_count) > 0 ? '批量生成并下载' : '保存'}
          </button>
        </div>
      </div>
    </Modal>
  </>
}

function buildPayload(draft: Draft, id?: number): CouponPayload {
  const payload: CouponPayload = {
    ...(id ? { id } : {}),
    name: draft.name.trim(),
    ...(draft.code.trim() ? { code: draft.code.trim() } : {}),
    type: draft.type,
    value: draft.type === 1 ? Math.round(Number(draft.value) * 100) : Number(draft.value),
    ...(draft.limit_use !== '' ? { limit_use: Number(draft.limit_use) } : {}),
    ...(draft.limit_use_with_user !== '' ? { limit_use_with_user: Number(draft.limit_use_with_user) } : {}),
    ...(draft.limit_plan_ids.length ? { limit_plan_ids: draft.limit_plan_ids } : {}),
    ...(draft.limit_period.length ? { limit_period: draft.limit_period } : {}),
    ...(draft.started_at ? { started_at: toTs(draft.started_at) } : {}),
    ...(draft.ended_at ? { ended_at: toTs(draft.ended_at) } : {}),
  }
  return payload
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="field"><span>{label}</span>{children}</label>
}
function toggleIn<T>(items: T[], value: T) { return items.includes(value) ? items.filter(item => item !== value) : [...items, value] }
function toTs(value: string) { const t = new Date(value).getTime(); return Number.isFinite(t) ? Math.floor(t / 1000) : null }
function toLocal(ts?: number) {
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const offset = d.getTimezoneOffset() * 60_000
  return new Date(d.getTime() - offset).toISOString().slice(0, 16)
}
function validity(row: CouponItem) {
  const now = Math.floor(Date.now() / 1000)
  if (!row.started_at && !row.ended_at) return '不限'
  if (row.ended_at && row.ended_at < now) return '已过期'
  if (row.started_at && row.started_at > now) return '未开始'
  if (row.ended_at) return '至 ' + new Date(row.ended_at * 1000).toLocaleDateString()
  return '有效'
}
