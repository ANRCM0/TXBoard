import { t, useLocale } from '../../lib/i18n'
import type { ReactNode } from 'react'
import { X } from 'lucide-react'
import './table-details.css'

export type AppliedFilter = { key: string; label: string }

export function FilterBar({ children, applied = [], onClear, onRemove, hint, className = '' }: {
  children: ReactNode
  applied?: readonly AppliedFilter[]
  onClear?: () => void
  onRemove?: (key: string) => void
  hint?: ReactNode
  className?: string
}) {
  useLocale()
  return <div className={'ui-filter-bar ' + className} role="search">
    <div className="ui-filter-controls">{children}</div>
    {hint ? <p className="ui-filter-hint">{hint}</p> : null}
    {applied.length ? <div className="ui-filter-applied" role="status">
      <span>{t("当前筛选")}</span>
      {applied.map(filter => <span className="ui-filter-chip" key={filter.key}>{filter.label}{onRemove ? <button type="button" aria-label={t("移除筛选 ") + filter.label} onClick={() => onRemove(filter.key)}><X size={12} aria-hidden="true" /></button> : null}</span>)}
      {onClear ? <button type="button" className="ui-filter-clear" onClick={onClear}>{t("清除")}</button> : null}
    </div> : null}
  </div>
}
