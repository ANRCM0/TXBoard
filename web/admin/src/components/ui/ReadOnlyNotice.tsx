import { Info } from 'lucide-react'
import type { ReactNode } from 'react'

export function ReadOnlyNotice({ title = '只读页面', children }: {
  title?: string
  children: ReactNode
}) {
  return <section className="ui-readonly-notice" role="note">
    <Info size={17} aria-hidden="true" />
    <div>
      <strong>{title}</strong>
      <p>{children}</p>
    </div>
  </section>
}
