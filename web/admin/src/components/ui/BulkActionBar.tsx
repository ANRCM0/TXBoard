import type { ReactNode } from 'react'

export function BulkActionBar({ count, onClear, children, noun = '项' }: {
  count: number
  onClear: () => void
  children: ReactNode
  noun?: string
}) {
  if (!count) return null
  return <div className="ui-bulk-bar" aria-label="批量操作">
    <span className="ui-bulk-count" role="status" aria-live="polite">已选当前页 <strong>{count}</strong> {noun}</span>
    <div className="ui-bulk-actions">{children}</div>
    <button type="button" className="button" onClick={onClear}>清除选择</button>
  </div>
}
