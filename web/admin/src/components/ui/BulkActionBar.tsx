import { t, useLocale } from '../../lib/i18n'
import type { ReactNode } from 'react'
import './experience-lists.css'

export function BulkActionBar({ count, onClear, children, noun = t("项") }: {
  count: number
  onClear: () => void
  children: ReactNode
  noun?: string
}) {
  useLocale()
  if (!count) return null
  return <div className="ui-bulk-bar" role="region" aria-label={t("批量操作")}>
    <span className="ui-bulk-count" role="status" aria-live="polite" aria-atomic="true">{t('已选当前页 {count} {noun}', { count, noun })}</span>
    <div className="ui-bulk-actions" role="group" aria-label={t("已选项目操作")}>{children}</div>
    <button type="button" className="button ui-bulk-clear" aria-label={t("清除当前页选择")} onClick={onClear}>{t("清除当前页选择")}</button>
  </div>
}
