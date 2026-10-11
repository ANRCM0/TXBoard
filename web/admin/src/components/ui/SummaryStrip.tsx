import type { ReactNode } from 'react'

export type SummaryItem = {
  key: string
  label: string
  value: number | string | null | undefined
  tone?: 'neutral' | 'success' | 'warning' | 'danger'
  icon?: ReactNode
}

export function SummaryStrip({ items, label }: { items: readonly SummaryItem[]; label: string }) {
  return <div className="admin-v2-summary" role="group" aria-label={label}>
    {items.map(item => <div
      className={'admin-v2-summary-item' + (item.tone && item.tone !== 'neutral' ? ' is-' + item.tone : '')}
      key={item.key}
    >
      <span className="admin-v2-summary-label">{item.icon ? <span aria-hidden="true">{item.icon}</span> : null}{item.label}</span>
      <strong className="admin-v2-summary-value">{item.value ?? '—'}</strong>
    </div>)}
  </div>
}
