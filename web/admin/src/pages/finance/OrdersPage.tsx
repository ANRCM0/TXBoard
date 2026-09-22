import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, ChevronLeft, ChevronRight, Eye, Plus, Search, XCircle } from 'lucide-react'
import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import {
  cancelOrder,
  getOrders,
  getPlans,
  markOrderPaid,
  updateOrderCommission,
  type OrderItem,
} from '../../api/finance'
import { OrderAssignModal } from '../../components/finance/OrderAssignModal'
import { OrderDetailModal } from '../../components/finance/OrderDetailModal'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

type FilterField = 'trade_no' | 'email' | 'user_id' | 'callback_no'

export function OrdersPage() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [field, setField] = useState<FilterField>('trade_no')
  const [keyword, setKeyword] = useState('')
  const [appliedKeyword, setAppliedKeyword] = useState('')
  const [status, setStatus] = useState('')
  const [commission, setCommission] = useState('')
  const [commissionOnly, setCommissionOnly] = useState(false)
  const [detailId, setDetailId] = useState<number | null>(null)
  const [assignOpen, setAssignOpen] = useState(false)

  const filters = useMemo(() => {
    const result: Array<{ id: string; value: string | number[] }> = []
    if (appliedKeyword.trim()) result.push({ id: field, value: appliedKeyword.trim() })
    if (status !== '') result.push({ id: 'status', value: [Number(status)] })
    if (commission !== '') result.push({ id: 'commission_status', value: [Number(commission)] })
    return result
  }, [appliedKeyword, field, status, commission])

  const query = useQuery({
    queryKey: ['orders', page, pageSize, filters, commissionOnly],
    queryFn: () => getOrders({
      current: page,
      pageSize,
      ...(filters.length ? { filter: filters } : {}),
      ...(commissionOnly ? { is_commission: true } : {}),
    }),
    placeholderData: previous => previous,
  })

  const plansQuery = useQuery({ queryKey: ['plans'], queryFn: getPlans })
  const data = query.data
  const rows = Array.isArray(data?.data) ? data.data : []

  const refresh = () => qc.invalidateQueries({ queryKey: ['orders'] })

  const paid = useMutation({
    mutationFn: markOrderPaid,
    onSuccess: () => {
      toast.success('订单已标记付款')
      refresh()
    },
  })

  const cancel = useMutation({
    mutationFn: cancelOrder,
    onSuccess: () => {
      toast.success('订单已取消')
      refresh()
    },
  })

  const commissionMutation = useMutation({
    mutationFn: ({ tradeNo, value }: { tradeNo: string; value: 0 | 1 | 3 }) => updateOrderCommission(tradeNo, value),
    onSuccess: () => {
      toast.success('佣金状态已更新')
      refresh()
    },
  })

  function applySearch() {
    setPage(1)
    setAppliedKeyword(keyword)
  }

  function clearFilters() {
    setKeyword('')
    setAppliedKeyword('')
    setStatus('')
    setCommission('')
    setCommissionOnly(false)
    setPage(1)
  }

  return <>
    <PageHeader
      title="订单管理"
      description="查询订单、核对收款与跟进佣金发放。"
    />

    <div className="order-toolbar finance-order-toolbar">
      <button className="button" onClick={() => setAssignOpen(true)}><Plus size={16}/>创建订单</button>
      <div className="order-search">
        <select aria-label="订单搜索字段" value={field} onChange={e => { setPage(1); setField(e.target.value as FilterField); setAppliedKeyword(''); setKeyword(''); }}>
          <option value="trade_no">订单号</option>
          <option value="email">用户邮箱</option>
          <option value="user_id">用户 ID</option>
          <option value="callback_no">回调号</option>
        </select>
        <input
          aria-label="搜索订单"
          value={keyword}
          onChange={e => setKeyword(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && applySearch()}
          placeholder="输入筛选内容…"
        />
        <button className="button" onClick={applySearch}><Search size={15}/>搜索</button>
      </div>

      <select aria-label="筛选订单状态" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
        <option value="">全部订单状态</option>
        <option value="0">待支付</option>
        <option value="1">开通中</option>
        <option value="2">已取消</option>
        <option value="3">已完成</option>
        <option value="4">已折抵</option>
      </select>

      <select aria-label="筛选佣金状态" value={commission} onChange={e => { setCommission(e.target.value); setPage(1) }}>
        <option value="">全部佣金状态</option>
        <option value="0">待确认</option>
        <option value="1">发放中</option>
        <option value="2">有效</option>
        <option value="3">无效</option>
      </select>

      <label className="toolbar-check">
        <input type="checkbox" checked={commissionOnly} onChange={e => { setCommissionOnly(e.target.checked); setPage(1) }}/>
        <span>仅佣金订单</span>
      </label>

      {(appliedKeyword || status || commission || commissionOnly) && <button className="button" onClick={clearFilters}>清除筛选</button>}
    </div>

    <div className="card order-table-card">
      <QueryFeedback loading={query.isFetching} error={query.isError} onRetry={() => query.refetch()} />
      <div className="table-wrap" tabIndex={0} role="region" aria-label="订单列表" aria-busy={query.isFetching}>
        <table className="data-table order-table">
          <thead>
            <tr>
              <th>订单号</th>
              <th>类型</th>
              <th>套餐 / 周期</th>
              <th>金额</th>
              <th>状态</th>
              <th>佣金</th>
              <th>创建时间</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(order => <tr key={order.id}>
              <td>
                <button className="link-button" onClick={() => setDetailId(order.id)}>{order.trade_no}</button>
                <small className="table-sub">{order.user?.email || `User #${order.user_id}`}</small>
              </td>
              <td><span className="badge">{typeLabel(order.type)}</span></td>
              <td>
                <strong>{order.plan?.name || `Plan #${order.plan_id}`}</strong>
                <small className="table-sub">{periodLabel(order.period)}</small>
              </td>
              <td>
                <strong>{money(order.total_amount)}</strong>
                {(order.discount_amount || order.balance_amount) ? <small className="table-sub">
                  {order.discount_amount ? `优惠 ${money(order.discount_amount)}` : ''}
                  {order.balance_amount ? ` · 余额 ${money(order.balance_amount)}` : ''}
                </small> : null}
              </td>
              <td><span className={statusClass(order.status)}>{statusLabel(order.status)}</span></td>
              <td><CommissionCell disabled={commissionMutation.isPending || query.isPlaceholderData} order={order} onChange={value => commissionMutation.mutate({ tradeNo: order.trade_no, value })}/></td>
              <td>{formatTime(order.created_at)}</td>
              <td>
                <div className="actions">
                  <button className="icon-button" title="详情" onClick={() => setDetailId(order.id)}><Eye size={15}/></button>
                  {order.status === 0 && <>
                    <button className="icon-button success" title="标记付款" disabled={paid.isPending || cancel.isPending || query.isPlaceholderData} onClick={() => requestConfirm({ title: '标记付款', message: '确认手动标记该订单为已付款？', confirmLabel: '标记付款', action: () => paid.mutate(order.trade_no) })}><CheckCircle2 size={15}/></button>
                    <button className="icon-button danger" title="取消订单" disabled={paid.isPending || cancel.isPending || query.isPlaceholderData} onClick={() => requestConfirm({ title: '取消订单', message: '确认取消该订单？用户将无法继续支付。', danger: true, confirmLabel: '取消订单', action: () => cancel.mutate(order.trade_no) })}><XCircle size={15}/></button>
                  </>}
                </div>
              </td>
            </tr>)}
            {!rows.length && !query.isFetching && !query.isError && <tr><td colSpan={8} className="empty-cell">{query.isLoading ? '加载中…' : '没有符合条件的订单'}</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="pagination-bar">
        <div className="pagination-meta">
          共 {Number(data?.total || 0)} 条 · 第 {Number(data?.current_page || page)} / {Math.max(1, Number(data?.last_page || 1))} 页
        </div>
        <div className="pagination-actions">
          <select aria-label="每页条数" value={pageSize} onChange={e => { setPageSize(Number(e.target.value)); setPage(1) }}>
            <option value={10}>10 / 页</option>
            <option value={20}>20 / 页</option>
            <option value={50}>50 / 页</option>
            <option value={100}>100 / 页</option>
          </select>
          <button className="icon-button" aria-label="上一页" disabled={query.isFetching || page <= 1} onClick={() => setPage(value => Math.max(1, value - 1))}><ChevronLeft size={16}/></button>
          <button className="icon-button" aria-label="下一页" disabled={query.isFetching || page >= Number(data?.last_page || 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16}/></button>
        </div>
      </div>
    </div>

    <OrderDetailModal orderId={detailId} onClose={() => setDetailId(null)}/>
    <OrderAssignModal
      open={assignOpen}
      plans={Array.isArray(plansQuery.data) ? plansQuery.data : []}
      onClose={() => setAssignOpen(false)}
      onCreated={() => { setPage(1); refresh() }}
    />
  </>
}

function CommissionCell({ order, onChange, disabled }: { disabled?: boolean; order: OrderItem; onChange: (value: 0 | 1 | 3) => void }) {
  if (!order.invite_user_id || Number(order.commission_balance || 0) <= 0) return <span className="muted">-</span>
  if (order.commission_status === 2) return <div><span className="status ok">有效</span><small className="table-sub">{money(order.commission_balance)}</small></div>
  return <div className="commission-cell">
    <select
      aria-label={'订单 '+order.trade_no+' 的佣金状态'}
      disabled={disabled}
      value={String(order.commission_status ?? 0)}
      onChange={e => onChange(Number(e.target.value) as 0 | 1 | 3)}
    >
      <option value="0">待确认</option>
      <option value="1">发放中</option>
      <option value="3">无效</option>
    </select>
    <small>{money(order.commission_balance)}</small>
  </div>
}

function money(value: unknown) {
  const n = Number(value)
  return Number.isFinite(n) ? `¥ ${(n / 100).toFixed(2)}` : '-'
}
function statusLabel(value: number) {
  return ({ 0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已折抵' } as Record<number, string>)[value] || String(value)
}
function statusClass(value: number) {
  if (value === 3) return 'status ok'
  if (value === 2) return 'status off'
  return 'badge'
}
function typeLabel(value: number) {
  return ({ 1: '新购', 2: '续费', 3: '升级', 4: '流量重置' } as Record<number, string>)[value] || String(value)
}
function periodLabel(value?: string) {
  if (!value) return '-'
  return ({
    month_price: '月付',
    quarter_price: '季付',
    half_year_price: '半年付',
    year_price: '年付',
    two_year_price: '两年付',
    three_year_price: '三年付',
    onetime_price: '一次性',
    reset_price: '重置流量',
  } as Record<string, string>)[value] || value
}
function formatTime(value: unknown) {
  const n = Number(value)
  if (Number.isFinite(n) && n > 0) return new Date(n < 10_000_000_000 ? n * 1000 : n).toLocaleString()
  if (typeof value === 'string' && value) {
    const t = Date.parse(value)
    if (Number.isFinite(t)) return new Date(t).toLocaleString()
  }
  return '-'
}
