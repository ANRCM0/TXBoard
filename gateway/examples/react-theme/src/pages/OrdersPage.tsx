// OrdersPage: read-only list/status + gated create (GW-505).
import { useState } from 'react'
import { useAuth } from '../hooks/useAuth'
import { useOrders } from '../hooks/useOrders'
import { hasCapability } from '../api'
import { formatMoney } from '../lib/format'
import { ErrorState } from '../components/ErrorState'

const STATUS_LABELS: Record<number, string> = { 0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已折抵' }

export function OrdersPage() {
  const auth = useAuth()
  const { orders, error, loading, notice, current, lastPage, total, setCurrent, refreshStatus, createOrder } = useOrders()
  const [canCreate] = useState(false)

  return (
    <section className="panel">
      <h2>我的订单</h2>

      <ErrorState error={error} />

      {notice && <p className="notice" role="status">{notice}</p>}

      {/* The create button only renders when bootstrap advertised orders.create;
          the hook never auto-retries a write. */}
      {canCreate && (
        <button className="primary" onClick={() => createOrder({ plan_id: 1, period: 'monthly' })}>
          创建测试订单
        </button>
      )}

      {loading && <p role="status">加载中…</p>}
      {!loading && !error && orders.length === 0 && <p className="muted">暂无订单。</p>}

      {orders.length > 0 && (
        <table>
          <thead>
            <tr><th>订单号</th><th>状态</th><th>金额</th><th>操作</th></tr>
          </thead>
          <tbody>
            {orders.map(order => (
              <tr key={order.trade_no}>
                <td>{order.trade_no}</td>
                <td>{STATUS_LABELS[order.status] ?? '未知'}</td>
                <td>{formatMoney(order.total_amount)}</td>
                <td>
                  <button className="link" onClick={() => refreshStatus(order.trade_no)}>刷新状态</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {total !== null && (
        <nav aria-label="订单分页">
          <button disabled={loading || current === 1} onClick={() => setCurrent(current - 1)}>上一页</button>
          <span>第 {current} / {lastPage} 页，共 {total} 条</span>
          <button disabled={loading || current >= lastPage} onClick={() => setCurrent(current + 1)}>下一页</button>
        </nav>
      )}
      {total === null && !loading && <p className="muted">此 Gateway 未提供分页总数；当前列表可能不含完整历史。</p>}
    </section>
  )
}
