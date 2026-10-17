// Home page (server component, GW-506): bootstrap + plans, both public.
// The version gate runs first: an incompatible Gateway contract renders a
// refusal page instead of attempting calls that would fail.
import { createClient } from '@/lib/server'
import { negotiateVersion } from '@/lib/version'
import { safeImageUrl } from '@/lib/safe-url'
import { PlanCard } from '@/components/PlanCard'
import { ErrorState } from '@/components/ErrorState'
import type { BootstrapData, NativeOffer } from '@txboard/theme-sdk'

export const dynamic = 'force-dynamic'

export default async function HomePage() {
  const api = createClient()
  let bootstrap: BootstrapData | null = null
  let plans: NativeOffer[] = []
  let error: Error | null = null
  let refusal: string | null = null

  try {
    bootstrap = await api.bootstrap()
    const verdict = negotiateVersion('1')
    if (!verdict.ok) refusal = verdict.reason
    else plans = await api.plans.list()
  } catch (err) {
    error = err as Error
  }

  if (refusal) {
    return (
      <main className="page">
        <div className="error-state" role="alert">
          <strong>版本不兼容</strong>
          <p>{refusal}</p>
          <p className="muted">请联系管理员升级主题或网关。不会降级到不兼容版本继续运行。</p>
        </div>
      </main>
    )
  }

  const site = bootstrap?.site
  const logo = safeImageUrl(site?.logo)

  return (
    <main className="page">
      <header className="hero">
        {logo && <img className="logo" src={logo} alt="" />}
        <h1>{site?.name ?? '加载中…'}</h1>
        <p>{site?.description}</p>
      </header>

      <ErrorState error={error} />

      <section className="plans" aria-label="套餐列表">
        {plans.map(plan => <PlanCard key={plan.offer_id} plan={plan} />)}
      </section>
      {plans.length === 0 && !error && <p className="muted">暂无公开套餐。</p>}
    </main>
  )
}
