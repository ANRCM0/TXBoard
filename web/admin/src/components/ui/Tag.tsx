import type { ReactNode } from 'react'
import './tag.css'

export type TagTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

export function Tag({ tone = 'neutral', mono = false, children, className = '' }: {
  tone?: TagTone
  mono?: boolean
  children: ReactNode
  className?: string
}) {
  return <span className={['ui-tag', 'is-' + tone, mono ? 'is-mono' : '', className].filter(Boolean).join(' ')}>{children}</span>
}
