import { Info } from 'lucide-react'
import { useEffect, useId, useRef, useState, type ReactNode } from 'react'

export function Popover({ label, children, trigger, triggerClassName = '' }: {
  label: string
  children: ReactNode
  trigger?: ReactNode
  triggerClassName?: string
}) {
  const [open, setOpen] = useState(false)
  const id = useId()
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    panelRef.current?.focus()
    const closeOnOutside = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpen(false)
      triggerRef.current?.focus()
    }
    document.addEventListener('pointerdown', closeOnOutside)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('pointerdown', closeOnOutside)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [open])

  return <div className="ui-popover" ref={rootRef}>
    <button ref={triggerRef} type="button" className={'ui-popover-trigger ' + triggerClassName}
      aria-label={label} aria-expanded={open} aria-controls={id}
      onClick={() => setOpen(value => !value)}>
      {trigger ?? <Info size={16} aria-hidden="true" />}
    </button>
    {open ? <div id={id} ref={panelRef} tabIndex={-1} className="ui-popover-panel" role="dialog" aria-label={label}>{children}</div> : null}
  </div>
}
