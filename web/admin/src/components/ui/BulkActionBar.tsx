import { t, useLocale } from '../../lib/i18n'
import type { ReactNode } from 'react'

export function BulkActionBar({ count, onClear, children, noun = t("项") }: {
  count: number
  onClear: () => void
  children: ReactNode
  noun?: string
}) {
  useLocale()
  if (!count) return null
  return <div className="ui-bulk-bar" aria-label={t("批量操作")}>
    <span className="ui-bulk-count" role="status" aria-live="polite">{t('已选当前页 {count} {noun}', { count, noun })}</span>
    <div className="ui-bulk-actions">{children}</div>
    <button type="button" className="button" onClick={onClear}>{t("清除选择")}</button>
  </div>
}
