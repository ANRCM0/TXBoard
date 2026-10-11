import type { ReactNode } from 'react'

export function StatusText({
  children, tone = 'neutral', className = '',
}: {
  children: ReactNode
  tone?: 'neutral' | 'success' | 'warning' | 'danger'
  className?: string
}) {
  return <span className={['admin-v2-status', 'is-' + tone, className].filter(Boolean).join(' ')}>
    <span className="admin-v2-status-dot" aria-hidden="true"/>{children}
  </span>
}
