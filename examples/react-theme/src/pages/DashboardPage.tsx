// DashboardPage: profile + subscription + stats (GW-505).
import { useAuth } from '../hooks/useAuth'
import { useUserData } from '../hooks/useUserData'
import { ErrorState } from '../components/ErrorState'
import { formatBytes, formatDate } from '../lib/format'

export function DashboardPage() {
  const auth = useAuth()
  const { profile, subscription, stats, error, loading } = useUserData(auth.isAuthed)

  return (
    <section className="panel">
      <h2>账号概览</h2>
      <ErrorState error={error} />
      {loading && <div role="status">加载中…</div>}
      {!loading && !error && (
        <dl className="stats">
          <div><dt>邮箱</dt><dd>{profile?.email}</dd></div>
          <div><dt>当前套餐</dt><dd>{subscription?.planName ?? '无订阅'}</dd></div>
          <div><dt>到期时间</dt><dd>{subscription?.expiredAt ? formatDate(subscription.expiredAt) : '—'}</dd></div>
          <div><dt>剩余流量</dt><dd>{formatBytes(subscription?.transferEnable)}</dd></div>
          <div><dt>待支付订单</dt><dd>{stats?.unpaidOrders ?? 0}</dd></div>
        </dl>
      )}
    </section>
  )
}
