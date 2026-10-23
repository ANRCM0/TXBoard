import { useId, type ReactNode } from 'react'

export function FormSection({ title, description, children, action, className = '' }: {
  title: string; description?: string; children: ReactNode; action?: ReactNode; className?: string
}) {
  const titleId = useId()
  return <section className={'form-section ' + className} aria-labelledby={titleId}>
    <header className="form-section-heading"><div><h3 id={titleId}>{title}</h3>{description ? <p>{description}</p> : null}</div>{action}</header>
    <div className="form-section-content">{children}</div>
  </section>
}
