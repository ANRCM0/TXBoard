import { t, useLocale } from '../../lib/i18n'
import { Info } from 'lucide-react'
import type { ReactNode } from 'react'

export function ReadOnlyNotice({ title = t("只读页面"), children }: {
  title?: string
  children: ReactNode
}) {
  useLocale()
  return <section className="ui-readonly-notice" role="note">
    <Info size={17} aria-hidden="true" />
    <div>
      <strong>{title}</strong>
      <p>{children}</p>
    </div>
  </section>
}
