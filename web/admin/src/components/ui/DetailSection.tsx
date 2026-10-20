import { t, useLocale } from '../../lib/i18n'
import type { ReactNode } from 'react'

export function DetailSection({ label, title, description, icon, actions, children, className = '' }: {
  label: string
  title: ReactNode
  description?: ReactNode
  icon?: ReactNode
  actions?: ReactNode
  children: ReactNode
  className?: string
}) {
  useLocale()
  return <section className={'card ui-detail-section ' + className} aria-label={label}>
    <header className="ui-detail-section-head">
      <div className="ui-detail-section-title">
        {icon ? <span className="ui-detail-section-icon" aria-hidden="true">{icon}</span> : null}
        <div><h2>{title}</h2>{description ? <p>{description}</p> : null}</div>
      </div>
      {actions ? <div className="ui-detail-section-actions">{actions}</div> : null}
    </header>
    <div className="ui-detail-section-body">{children}</div>
  </section>
}
