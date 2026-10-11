import type { ReactNode } from 'react'

type EmptyStateProps = {
  icon: ReactNode
  title: string
  description: string
  action?: ReactNode
  secondaryAction?: ReactNode
  className?: string
}

export function EmptyState({ icon, title, description, action, secondaryAction, className = '' }: EmptyStateProps) {
  return <div className={['tx-empty-state', className].filter(Boolean).join(' ')}>
    <div className="tx-empty-state-icon" aria-hidden="true">{icon}</div>
    <strong>{title}</strong>
    <p>{description}</p>
    {action || secondaryAction ? <div className="tx-empty-state-actions">{action}{secondaryAction}</div> : null}
  </div>
}
