// HomePage: bootstrap + plans + login entry (GW-505).
import { useNavigate } from 'react-router-dom'
import { useBootstrap } from '../hooks/useBootstrap'
import { usePlans } from '../hooks/usePlans'
import { useAuth } from '../hooks/useAuth'
import { hasCapability } from '../api'
import { safeImageUrl } from '../lib/safe-url'
import { PlanCard } from '../components/PlanCard'
import { ErrorState } from '../components/ErrorState'

export function HomePage() {
  const auth = useAuth()
  const bootstrap = useBootstrap()
  const plans = usePlans()
  const navigate = useNavigate()

  const site = bootstrap.data?.site
  const logo = safeImageUrl(site?.logo)

  function choosePlan(planId: number) {
    if (!auth.isAuthed) return navigate('/login')
    // Write capability is advertised by the Gateway; never assume it.
    if (!hasCapability(bootstrap.data?.capabilities ?? [], 'orders.create')) return navigate('/plans')
    navigate('/plans')
  }

  return (
    <main className="page">
      <header className="hero">
        {logo && <img className="logo" src={logo} alt="" />}
        <h1>{site?.name ?? '加载中…'}</h1>
        <p>{site?.description}</p>
        <button className="primary" onClick={() => navigate(auth.isAuthed ? '/dashboard' : '/login')}>
          {auth.isAuthed ? '进入用户中心' : '登录 / 注册'}
        </button>
      </header>

      <ErrorState error={bootstrap.error ?? plans.error} onRetry={() => { bootstrap.reload(); plans.reload() }} />

      {plans.loading && <p className="muted" role="status">正在加载…</p>}
      {!plans.loading && !plans.error && plans.plans.length === 0 && <p className="muted">暂无公开套餐。</p>}

      <section className="plans" aria-label="套餐列表">
        {plans.plans.map(plan => (
          <PlanCard key={plan.id} plan={plan} onChoose={choosePlan} />
        ))}
      </section>
    </main>
  )
}
