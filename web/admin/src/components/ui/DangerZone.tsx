import type { ReactNode } from 'react'

export function DangerZone({ title = '危险操作', description, children, className = '' }: {
  title?: string
  description?: ReactNode
  children: ReactNode
  className?: string
}) {
  return <section className={'ui-danger-zone ' + className} aria-label={title}>
    <div className="ui-danger-copy">
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
    </div>
    <div className="ui-danger-actions">{children}</div>
  </section>
}
