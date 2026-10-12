// PlanCard: renders the public plan DTO (GW-506). Minor-unit conversion here.
import type { Plan } from '@txboard/theme-sdk'
import { formatMoney } from '@/lib/format'

export function PlanCard({ plan }: { plan: Plan }) {
  return (
    <article className="plan-card">
      <h3>{plan.name}</h3>
      <p className="price">¥{formatMoney(plan.month_price)} <span>/ 月</span></p>
      {typeof plan.description === 'string' && <p className="muted">{plan.description}</p>}
    </article>
  )
}
