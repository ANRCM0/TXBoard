import { Info } from 'lucide-react'
import { useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { registerDismissLayer } from '../../lib/dismissLayers'
import './popover-layout.css'

export function Popover({ label, children, trigger, triggerClassName = '', panelClassName = '', open: controlledOpen, onOpenChange, closeOnSelect = false, align = 'end' }: {
  label: string
  children: ReactNode
  trigger?: ReactNode
  triggerClassName?: string
  panelClassName?: string
  open?: boolean
  onOpenChange?: (open: boolean) => void
  closeOnSelect?: boolean
  align?: 'start' | 'end'
}) {
  const [localOpen, setLocalOpen] = useState(false)
  const open = controlledOpen ?? localOpen
  const id = useId()
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const openingFocus = useRef<'first' | 'last' | null>(null)
  const change = useRef((value: boolean) => {})
  change.current = value => { setLocalOpen(value); onOpenChange?.(value) }

  function close(restoreFocus: boolean) {
    change.current(false)
    if (restoreFocus && triggerRef.current?.isConnected && !triggerRef.current.closest('[inert]')) triggerRef.current.focus()
  }

  function actions() {
    return Array.from(panelRef.current?.querySelectorAll<HTMLElement>('button, a[href], input, select, textarea, [tabindex]') || [])
      .filter(element => {
        if (element.tabIndex < 0 || element.matches(':disabled') || element.closest('[hidden], [inert]')) return false
        for (let parent: HTMLElement | null = element; parent && parent !== panelRef.current?.parentElement; parent = parent.parentElement) {
          const style = getComputedStyle(parent)
          if (style.display === 'none' || style.visibility === 'hidden') return false
        }
        return true
      })
  }

  function focusAction(edge: 'first' | 'last') {
    const items = actions()
    ;(edge === 'first' ? items[0] : items.at(-1))?.focus()
  }

  useLayoutEffect(() => {
    const panel = panelRef.current, trigger = triggerRef.current
    if (!open || !panel || !trigger) return
    // Top-layer placement escapes clipping while retaining the active dialog's DOM ownership.
    panel.setAttribute('popover', 'manual')
    if (typeof panel.showPopover === 'function') panel.showPopover()
    const position = () => {
      const anchor = trigger.getBoundingClientRect(), bounds = panel.getBoundingClientRect()
      const viewport = window.visualViewport
      const leftEdge = viewport?.offsetLeft ?? 0, topEdge = viewport?.offsetTop ?? 0
      const width = viewport?.width ?? window.innerWidth, height = viewport?.height ?? window.innerHeight
      const edge = 12, gap = 8, above = anchor.top - topEdge - edge, below = topEdge + height - anchor.bottom - edge
      const flip = bounds.height > below - gap && above > below
      const available = Math.max(0, (flip ? above : below) - gap)
      panel.style.maxHeight = `${available}px`
      const panelHeight = Math.min(bounds.height, available)
      const preferredLeft = align === 'start' ? anchor.left : anchor.right - bounds.width
      panel.style.left = `${Math.max(leftEdge + edge, Math.min(preferredLeft, leftEdge + width - bounds.width - edge))}px`
      panel.style.top = `${Math.max(topEdge + edge, flip ? anchor.top - gap - panelHeight : anchor.bottom + gap)}px`
      panel.dataset.side = flip ? 'top' : 'bottom'
    }
    position()
    const observer = typeof ResizeObserver === 'undefined' ? undefined : new ResizeObserver(position)
    observer?.observe(panel); observer?.observe(trigger)
    window.addEventListener('resize', position)
    document.addEventListener('scroll', position, true)
    window.visualViewport?.addEventListener('resize', position)
    window.visualViewport?.addEventListener('scroll', position)
    return () => {
      observer?.disconnect()
      window.removeEventListener('resize', position)
      document.removeEventListener('scroll', position, true)
      window.visualViewport?.removeEventListener('resize', position)
      window.visualViewport?.removeEventListener('scroll', position)
      if (typeof panel.hidePopover === 'function') panel.hidePopover()
    }
  }, [align, open])

  useEffect(() => {
    const panel = panelRef.current
    if (!open || !panel) return
    if (openingFocus.current && actions().length) focusAction(openingFocus.current)
    else panel.focus({ preventScroll: true })
    openingFocus.current = null
    const release = registerDismissLayer(panel, () => close(true))
    const closeOnOutside = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) close(false)
    }
    const closeOnFocusLeave = (event: FocusEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) close(false)
    }
    document.addEventListener('pointerdown', closeOnOutside)
    document.addEventListener('focusin', closeOnFocusLeave)
    return () => {
      document.removeEventListener('pointerdown', closeOnOutside)
      release()
      document.removeEventListener('focusin', closeOnFocusLeave)
    }
  }, [open])

  return <div className="ui-popover" ref={rootRef}>
    <button ref={triggerRef} type="button" className={'ui-popover-trigger ' + triggerClassName}
      aria-label={label} aria-expanded={open} aria-controls={open ? id : undefined} aria-haspopup="dialog"
      onKeyDown={event => {
        if (event.nativeEvent.isComposing || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')) return
        event.preventDefault()
        event.stopPropagation()
        const edge = event.key === 'ArrowDown' ? 'first' : 'last'
        if (open) focusAction(edge)
        else { openingFocus.current = edge; change.current(true) }
      }}
      onClick={() => change.current(!open)}>
      {trigger ?? <Info size={16} aria-hidden="true" />}
    </button>
    {open ? <div id={id} ref={panelRef} tabIndex={-1} className={'ui-popover-panel ui-positioned-popover ' + panelClassName} role="dialog" aria-label={label}
      onKeyDown={event => {
        if (event.nativeEvent.isComposing || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return
        const target = event.target as HTMLElement
        if (!target.matches('button, a[href]')) return
        const items = actions(), index = items.indexOf(target)
        if (index < 0 || !items.length) return
        event.preventDefault()
        event.stopPropagation()
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
          : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length
        items[next].focus()
      }}
      onClick={event => {
        const item = (event.target as Element).closest('button, a[href], [data-popover-close]')
        if (closeOnSelect && item && !item.matches(':disabled, [aria-disabled="true"], [data-popover-stay-open]')) close(true)
      }}>{children}</div> : null}
  </div>
}
