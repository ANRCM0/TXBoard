// Dashboard page (server component, GW-506): protected user reads.
// The session token comes ONLY from this request's httpOnly cookie, is used
// server-side to call the Gateway, and is never serialized to the client.
import { createClient, hasSession } from '@/lib/server'
import { negotiateVersion } from '@/lib/version'
import { ErrorState } from '@/components/ErrorState'
import { formatBytes, formatDate } from '@/lib/format'

export const dynamic = 'force-dynamic'

export default async function DashboardPage() {
  if (!hasSession()) {
    return (
      <main className="page">
        <p className="muted">未登录。<a href="/login">前往登录</a></p>
      </main>
    )
  }

  const api = createClient()
  let profile = null
  let subscription = null
  let stats = null
  let error: Error | null = null
  let refusal: string | null = null

  try {
    // GW-507: negotiate BEFORE the first protected call.
    const bootstrap = await api.bootstrap()
    const verdict = negotiateVersion('1')
    if (!verdict.ok) refusal = verdict.reason
    else [profile, subscription, stats] = await Promise.all([
      api.user.profile(),
      api.user.subscription(),
      api.dashboard.stats(),
    ])
  } catch (err) {
    error = err as Error
  }

  if (refusal) {
    return (
      <main className="page">
        <div className="error-state" role="alert">
          <strong>版本不兼容</strong><p>{refusal}</p>
        </div>
      </main>
    )
  }

  return (
    <main className="page">
      <section className="panel">
        <h2>账号概览</h2>
        <ErrorState error={error} />
        {!error && (
          <dl className="stats">
            <div><dt>邮箱</dt><dd>{profile?.email}</dd></div>
            <div><dt>当前套餐</dt><dd>{subscription?.planName ?? '无订阅'}</dd></div>
            <div><dt>到期时间</dt><dd>{subscription?.expiredAt ? formatDate(subscription.expiredAt) : '—'}</dd></div>
            <div><dt>剩余流量</dt><dd>{formatBytes(subscription?.transferEnable)}</dd></div>
            <div><dt>待支付订单</dt><dd>{stats?.unpaidOrders ?? 0}</dd></div>
          </dl>
        )}
      </section>
    </main>
  )
}
