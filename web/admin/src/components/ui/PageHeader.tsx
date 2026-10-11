import type { ReactNode } from 'react'

export function PageHeader({ title, description, action }: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return <div className="page-header">
    <div className="page-header-copy">
      <h1>{title}</h1>
      {description ? <p>{description}</p> : null}
    </div>
    {action ? <div className="page-header-actions">{action}</div> : null}
  </div>
}
