import type { ReactNode } from 'react'

export type AppliedFilter = { key: string; label: string }

export function FilterBar({ children, applied = [], onClear, hint, className = '' }: {
  children: ReactNode
  applied?: readonly AppliedFilter[]
  onClear?: () => void
  hint?: ReactNode
  className?: string
}) {
  return <div className={'ui-filter-bar ' + className} role="search">
    <div className="ui-filter-controls">{children}</div>
    {hint ? <p className="ui-filter-hint">{hint}</p> : null}
    {applied.length ? <div className="ui-filter-applied" role="status">
      <span>当前筛选</span>
      {applied.map(filter => <span className="ui-filter-chip" key={filter.key}>{filter.label}</span>)}
      {onClear ? <button type="button" className="ui-filter-clear" onClick={onClear}>清除</button> : null}
    </div> : null}
  </div>
}
