// PlanCard: renders the public plan DTO (GW-505). Minor-unit conversion here.
import type { NativeOffer } from '@txboard/theme-sdk'
import { formatOfferMoney } from '../lib/format'

export function PlanCard({ plan, onChoose }: { plan: NativeOffer; onChoose?: (id: string) => void }) {
  return (
    <article className="plan-card">
      <h3>{plan.name}</h3>
      <p className="price">{plan.currency} {formatOfferMoney(plan.price_minor)} <span>/ {plan.offer_code}</span></p>
      {typeof plan.content === 'string' && <p className="muted">{plan.content}</p>}
      {onChoose && <button className="primary" onClick={() => onChoose(plan.offer_id)}>选择</button>}
    </article>
  )
}
