// PlanCard: renders the public plan DTO (GW-505). Minor-unit conversion here.
import type { Plan } from '@txboard/theme-sdk'
import { formatMoney } from '../lib/format'

export function PlanCard({ plan, onChoose }: { plan: Plan; onChoose?: (id: number) => void }) {
  return (
    <article className="plan-card">
      <h3>{plan.name}</h3>
      <p className="price">¥{formatMoney(plan.month_price)} <span>/ 月</span></p>
      {typeof plan.description === 'string' && <p className="muted">{plan.description}</p>}
      {onChoose && <button className="primary" onClick={() => onChoose(plan.id)}>选择</button>}
    </article>
  )
}
