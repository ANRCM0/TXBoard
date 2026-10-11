import type { ReactNode } from 'react'
import { QueryFeedback } from './QueryFeedback'
import { listSurfaceState } from './listSurfaceState'

export function ListSurface({
  count, loading, error, placeholder, onRetry, label, emptyState,
  pagination, children, className = '',
}: {
  count: number
  loading?: boolean
  error?: boolean
  placeholder?: boolean
  onRetry?: () => void
  label: string
  emptyState: ReactNode
  pagination?: ReactNode
  children: ReactNode
  className?: string
}) {
  const state = listSurfaceState({ count, loading, error, placeholder })
  return <section
    className={['admin-v2-list-surface', 'is-' + state, className].filter(Boolean).join(' ')}
    aria-label={label}
  >
    <QueryFeedback loading={state === 'loading'} error={Boolean(error)} onRetry={onRetry} />
    {state === 'rows' ? <div className="table-wrap" tabIndex={0} role="region"
      aria-label={label} aria-busy={loading || false}>
      {children}
    </div> : state === 'empty' ? (
      <div className="admin-v2-list-empty">{emptyState}</div>
    ) : null}
    {pagination && (state === 'rows' || state === 'empty') ? pagination : null}
  </section>
}
