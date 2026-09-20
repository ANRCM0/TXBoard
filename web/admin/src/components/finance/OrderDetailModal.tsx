import { useQuery } from '@tanstack/react-query'
import { getOrderDetail } from '../../api/finance'
import { Modal } from '../ui/Modal'

export function OrderDetailModal({
  orderId,
  onClose,
}: {
  orderId: number | null
  onClose: () => void
}) {
  const query = useQuery({
    queryKey: ['orderDetail', orderId],
    queryFn: () => getOrderDetail(orderId!),
    enabled: orderId !== null,
  })
  const order = query.data

  return <Modal open={orderId !== null} title="订单详情" onClose={onClose}>
    {query.isLoading ? <div className="empty-state">加载订单详情…</div> :
      !order ? <div className="empty-state">未找到订单。</div> :
      <div className="order-detail">
        <section className="detail-section">
          <h4>订单</h4>
          <div className="detail-grid">
            <Item label="订单号" value={order.trade_no}/>
            <Item label="用户" value={order.user?.email || `User #${order.user_id}`}/>
            <Item label="套餐" value={order.plan?.name || `Plan #${order.plan_id}`}/>
            <Item label="周期" value={periodLabel(order.period)}/>
            <Item label="类型" value={typeLabel(order.type)}/>
            <Item label="状态" value={statusLabel(order.status)}/>
            <Item label="订单金额" value={money(order.total_amount)}/>
            <Item label="手续费" value={money(order.handling_amount)}/>
            <Item label="余额抵扣" value={money(order.balance_amount)}/>
            <Item label="优惠金额" value={money(order.discount_amount)}/>
            <Item label="支付回调号" value={order.callback_no || '-'}/>
            <Item label="创建时间" value={formatTime(order.created_at)}/>
            <Item label="支付时间" value={formatTime(order.paid_at)}/>
          </div>
        </section>

        <section className="detail-section">
          <h4>佣金</h4>
          <div className="detail-grid">
            <Item label="邀请用户" value={order.invite_user?.email || (order.invite_user_id ? `User #${order.invite_user_id}` : '-')}/>
            <Item label="佣金状态" value={commissionLabel(order.commission_status)}/>
            <Item label="预估佣金" value={money(order.commission_balance)}/>
            <Item label="实际佣金" value={money(order.actual_commission_balance)}/>
          </div>
        </section>

        {Array.isArray(order.surplus_orders) && order.surplus_orders.length > 0 && <section className="detail-section">
          <h4>折抵订单</h4>
          <div className="surplus-list">{order.surplus_orders.map(item => <div key={item.id}><code>{item.trade_no}</code><span>{money(item.total_amount)}</span></div>)}</div>
        </section>}
      </div>}
  </Modal>
}

function Item({ label, value }: { label: string; value: string }) {
  return <div className="detail-item"><span>{label}</span><strong>{value}</strong></div>
}

function money(value: unknown) {
  const n = Number(value)
  return Number.isFinite(n) && value != null ? `¥ ${(n / 100).toFixed(2)}` : '-'
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

function statusLabel(value: number) {
  return ({ 0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已折抵' } as Record<number, string>)[value] || String(value)
}
function typeLabel(value: number) {
  return ({ 1: '新购', 2: '续费', 3: '升级', 4: '流量重置' } as Record<number, string>)[value] || String(value)
}
function commissionLabel(value?: number | null) {
  if (value == null) return '-'
  return ({ 0: '待确认', 1: '发放中', 2: '有效', 3: '无效' } as Record<number, string>)[value] || String(value)
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
    monthly: '月付',
    quarterly: '季付',
    half_yearly: '半年付',
    yearly: '年付',
    two_yearly: '两年付',
    three_yearly: '三年付',
    onetime: '一次性',
    reset_traffic: '重置流量',
  } as Record<string, string>)[value] || value
}
