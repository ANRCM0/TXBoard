import type { ReactNode } from 'react'

/** Section heading inside a page; the top-level page title remains an h1. */
export function SectionHeader({ title, description, action, className = '' }: {
  title: string; description?: string; action?: ReactNode; className?: string
}) {
  return <div className={['admin-section-header',className].filter(Boolean).join(' ')}>
    <div className="admin-section-header-copy">
      <h2>{title}</h2>
      {description ? <p>{description}</p> : null}
    </div>
    {action ? <div className="admin-section-header-actions">{action}</div> : null}
  </div>
}
