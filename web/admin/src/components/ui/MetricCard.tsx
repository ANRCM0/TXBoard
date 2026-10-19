import { ArrowUpRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

export function MetricCard({ label, value, caption, icon, tone = 'blue', href }: {
  label: string
  value: ReactNode
  caption: ReactNode
  icon: ReactNode
  tone?: 'blue' | 'purple' | 'green' | 'orange'
  href?: string
}) {
  const content = <>
    <div className="overview-metric-heading"><span>{label}</span>{icon}</div>
    <strong>{value}</strong>
    <span className="overview-metric-caption">{caption}{href ? <ArrowUpRight size={14} aria-hidden="true" /> : null}</span>
  </>
  const className = 'card overview-metric ui-metric tone-' + tone
  return href ? <Link className={className} to={href}>{content}</Link> : <div className={className}>{content}</div>
}
