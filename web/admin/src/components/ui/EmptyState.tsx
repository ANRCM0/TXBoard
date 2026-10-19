import { Inbox } from 'lucide-react'
import type { ReactNode } from 'react'

export function EmptyState({ icon, title = '暂无数据', description, action }: {
  icon?: ReactNode
  title?: string
  description?: ReactNode
  action?: ReactNode
}) {
  return <div className="ui-empty">
    <span className="ui-empty-icon" aria-hidden="true">{icon ?? <Inbox size={22} />}</span>
    <strong>{title}</strong>
    {description ? <p>{description}</p> : null}
    {action ? <div className="ui-empty-action">{action}</div> : null}
  </div>
}
