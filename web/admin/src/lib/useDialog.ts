import { useEffect, useRef } from 'react'

const stack: HTMLElement[] = []
let originalOverflow = ''
const inertElements = new Map<Element, { count: number; wasInert: boolean }>()
const selector = 'a[href], button, input, select, textarea, [tabindex]'

/** Share focus ownership and scroll locking across nested dialogs. */
export function useDialog(open: boolean, onClose: () => void) {
  const ref = useRef<HTMLDivElement>(null)
  const close = useRef(onClose)
  close.current = onClose

  useEffect(() => {
    const dialog = ref.current
    if (!open || !dialog) return
    const trigger = document.activeElement as HTMLElement | null
    if (!stack.length) originalOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    stack.push(dialog)
    const background: Element[] = []
    let branch: Element = dialog
    while (branch.parentElement) {
      for (const sibling of Array.from(branch.parentElement.children)) {
        if (sibling === branch || sibling.matches('script, style, .sidebar-backdrop')) continue
        const state = inertElements.get(sibling) || { count: 0, wasInert: sibling.hasAttribute('inert') }
        state.count += 1
        inertElements.set(sibling, state)
        sibling.setAttribute('inert', '')
        background.push(sibling)
      }
      if (branch.parentElement === document.body) break
      branch = branch.parentElement
    }

    const focusable = () => Array.from(dialog.querySelectorAll<HTMLElement>(selector)).filter(element =>
      element.tabIndex >= 0 && !element.matches(':disabled') &&
      !element.closest('[hidden], [inert]') &&
      getComputedStyle(element).display !== 'none' && getComputedStyle(element).visibility !== 'hidden',
    )
    const focusFirst = () => (dialog.querySelector<HTMLElement>('[data-autofocus]') || focusable()[0] || dialog).focus()
    focusFirst()

    function onKeyDown(event: KeyboardEvent) {
      if (stack.at(-1) !== dialog || event.isComposing) return
      if (event.key === 'Escape') {
        event.preventDefault()
        event.stopImmediatePropagation()
        close.current()
      }
      if (event.key === 'Tab') {
        const items = focusable()
        const index = items.indexOf(document.activeElement as HTMLElement)
        if (!items.length || index < 0 || (event.shiftKey ? index === 0 : index === items.length - 1)) {
          event.preventDefault()
          ;(event.shiftKey ? items.at(-1) || dialog : items[0] || dialog).focus()
        }
      }
    }
    function onFocus(event: FocusEvent) {
      if (stack.at(-1) === dialog && !dialog.contains(event.target as Node)) focusFirst()
    }
    document.addEventListener('keydown', onKeyDown, true)
    document.addEventListener('focusin', onFocus)
    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      document.removeEventListener('focusin', onFocus)
      const wasTop = stack.at(-1) === dialog
      stack.splice(stack.indexOf(dialog), 1)
      for (const element of background) {
        const state = inertElements.get(element)!
        state.count -= 1
        if (!state.count) {
          if (!state.wasInert) element.removeAttribute('inert')
          inertElements.delete(element)
        }
      }
      if (!stack.length) document.body.style.overflow = originalOverflow
      if (wasTop && trigger?.isConnected) trigger.focus()
    }
  }, [open])

  return ref
}
