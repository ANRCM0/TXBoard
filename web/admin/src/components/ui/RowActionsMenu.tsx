import { t, useLocale } from '../../lib/i18n'
import { MoreHorizontal } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Popover } from './Popover'
import './row-actions-menu.css'

export type RowAction = {
  key: string
  label: string
  icon?: ReactNode
  onSelect?: () => void
  href?: string
  ariaLabel?: string
  danger?: boolean
  disabled?: boolean
  separator?: boolean
}

export function RowActionsMenu({ label = t('更多操作'), actions, className = '' }: {
  label?: string
  actions: readonly RowAction[]
  className?: string
}) {
  useLocale()
  return <span className={'row-actions ' + className}>
    <Popover label={label} closeOnSelect trigger={<MoreHorizontal size={17} aria-hidden="true" />}
      triggerClassName="row-actions-trigger" panelClassName="row-actions-panel">
      <div className="row-actions-menu" role="menu" aria-label={label}>
        {actions.map(action => <div className="row-actions-item" key={action.key}>
          {action.separator ? <span className="row-actions-separator" role="separator" /> : null}
          {action.href
            ? <Link role="menuitem" to={action.href} aria-label={action.ariaLabel} className={'row-actions-entry' + (action.danger ? ' is-danger' : '')}>
              {action.icon}<span>{action.label}</span>
            </Link>
            : <button type="button" role="menuitem" disabled={action.disabled} aria-label={action.ariaLabel}
              className={'row-actions-entry' + (action.danger ? ' is-danger' : '')}
              onClick={action.onSelect}>
              {action.icon}<span>{action.label}</span>
            </button>}
        </div>)}
      </div>
    </Popover>
  </span>
}
