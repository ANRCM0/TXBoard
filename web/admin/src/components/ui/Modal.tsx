import { t, useLocale } from '../../lib/i18n'
import { X } from 'lucide-react'
import { useId, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useDialog } from '../../lib/useDialog'

export function Modal({
  open,
  title,
  subtitle,
  children,
  headerAction,
  footer,
  onClose,
  wide = false,
  placement = 'center',
  className = '',
  bodyClassName = '',
}: {
  open: boolean
  title: string
  subtitle?: string
  children: ReactNode
  headerAction?: ReactNode
  footer?: ReactNode
  onClose: () => void
  wide?: boolean
  placement?: 'center' | 'right'
  className?: string
  bodyClassName?: string
}) {
  useLocale()
  const dialogRef = useDialog(open, onClose)
  const descriptionId = useId()
  if (!open) return null

  return createPortal(
    <div
      ref={dialogRef}
      tabIndex={-1}
      className={`modal-root modal-placement-${placement}`}
      role="dialog"
      aria-modal="true"
      aria-label={title}
      aria-describedby={subtitle ? descriptionId : undefined}
    >
      <button
        type="button"
        tabIndex={-1}
        className="modal-backdrop"
        onClick={onClose}
        aria-label={t("关闭")}
      />
      <div
        className={[
          wide ? 'modal-card modal-card-wide' : 'modal-card',
          className,
        ].filter(Boolean).join(' ')}
      >
        <div className="modal-header">
          <div className="modal-title">
            <h3>{title}</h3>
            {subtitle ? <p id={descriptionId}>{subtitle}</p> : null}
          </div>
          <div className="modal-header-actions">
            {headerAction}
            <button type="button" className="icon-button" onClick={onClose} aria-label={t("关闭")}>
              <X size={18}/>
            </button>
          </div>
        </div>
        <div className={['modal-body', bodyClassName].filter(Boolean).join(' ')}>
          {children}
        </div>
        {footer ? <div className="modal-footer">{footer}</div> : null}
      </div>
    </div>,
    document.body,
  )
}
