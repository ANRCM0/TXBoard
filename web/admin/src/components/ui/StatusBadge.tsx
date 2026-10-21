import { t, useLocale } from '../../lib/i18n'
import type { ReactNode } from 'react'

export type StatusTone = 'success' | 'warning' | 'danger' | 'neutral' | 'info'

export function StatusBadge({ tone = 'neutral', children, dot = true, className = '' }: {
  tone?: StatusTone
  children: ReactNode
  dot?: boolean
  className?: string
}) {
  useLocale()
  return <span className={'ui-status is-' + tone + ' ' + className}>
    {dot ? <i aria-hidden="true" /> : null}
    <span>{children}</span>
  </span>
}
