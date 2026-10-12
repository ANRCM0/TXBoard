// Orders page (server component, GW-506): read-only list/status.
// Write (orders.create) is gated on bootstrap.capabilities — a visibility
// hint only, never an authorization (GW-507: capability ≠ permission).
import { createClient, hasSession } from '@/lib/server'
import { negotiateVersion, hasCapability } from '@/lib/version'
import { ErrorState } from '@/components/ErrorState'
import { formatMoney } from '@/lib/format'
import type { BootstrapData, Order } from '@txboard/theme-sdk'

export const dynamic = 'force-dynamic'

const STATUS_LABELS: Record<number, string> = { 0: '待支付', 1: '已支付', 2: '已取消', 3: '其他' }

export default async function OrdersPage() {
  if (!hasSession()) {
    return <main className="page"><p className="muted">未登录。<a href="/login">前往登录</a></p></main>
  }

  const api = createClient()
  let bootstrap: BootstrapData | null = null
  let orders: Order[] = []
  let error: Error | null = null
  let refusal: string | null = null

  try {
    bootstrap = await api.bootstrap()
    const verdict = negotiateVersion('1')
    if (!verdict.ok) refusal = verdict.reason
    else orders = await api.orders.list()
  } catch (err) {
    error = err as Error
  }

  if (refusal) {
    return (
      <main className="page">
        <div className="error-state" role="alert"><strong>版本不兼容</strong><p>{refusal}</p></div>
      </main>
    )
  }

  // Capability gating: the create entry only renders when the Gateway
  // advertised orders.create. This is a UI hint — calling the route without
  // it would still be refused by the Gateway (405), never silently succeed.
  const canCreate = hasCapability(bootstrap?.capabilities, 'orders.create')

  return (
    <main className="page">
      <section className="panel">
        <h2>我的订单</h2>
        <ErrorState error={error} />
        {canCreate && <p className="notice">创建订单能力已通告（默认部署通常不启用）。</p>}
        {orders.length === 0 && !error && <p className="muted">暂无订单。</p>}
        {orders.length > 0 && (
          <table>
            <thead><tr><th>订单号</th><th>状态</th><th>金额</th></tr></thead>
            <tbody>
              {orders.map(order => (
                <tr key={order.trade_no}>
                  <td>{order.trade_no}</td>
                  <td>{STATUS_LABELS[order.status] ?? '未知'}</td>
                  <td>{formatMoney(order.total_amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </main>
  )
}
