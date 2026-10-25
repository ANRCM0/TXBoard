import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Modal } from './Modal'

let host: HTMLDivElement
let root: Root
beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove() })

function Fixture() {
  const [open, setOpen] = useState(false)
  const [nested, setNested] = useState(false)
  return <><button id="trigger" onClick={() => setOpen(true)}>打开</button>
    <Modal open={open} title="编辑用户" onClose={() => setOpen(false)}>
      <input aria-label="邮箱" />
      <button id="nested" onClick={() => setNested(true)}>更多设置</button>
      <Modal open={nested} title="更多设置" onClose={() => setNested(false)}><input /></Modal>
    </Modal>
  </>
}
function key(key: string, shiftKey = false) {
  act(() => document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true })))
}
function open() {
  act(() => root.render(<Fixture />))
  const trigger = document.querySelector<HTMLButtonElement>('#trigger')!
  trigger.focus()
  act(() => trigger.click())
  return trigger
}

describe('Modal keyboard interaction', () => {
  it('traps focus, closes on Escape and restores the trigger', () => {
    const trigger = open()
    const close = document.querySelector<HTMLButtonElement>('.modal-header button')!
    expect(document.activeElement).toBe(close)
    expect(document.body.style.overflow).toBe('hidden')
    key('Tab', true)
    expect(document.activeElement?.id).toBe('nested')
    key('Tab')
    expect(document.activeElement).toBe(close)
    key('Escape')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(trigger)
    expect(document.body.style.overflow).toBe('')
  })

  it('only dismisses the topmost dialog and retains the parent scroll lock', () => {
    open()
    const nested = document.querySelector<HTMLButtonElement>('#nested')!
    nested.focus()
    act(() => nested.click())
    expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(2)
    key('Escape')
    expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(1)
    expect(document.activeElement).toBe(nested)
    expect(document.body.style.overflow).toBe('hidden')
    key('Escape')
    expect(document.body.style.overflow).toBe('')
  })

  it('does not submit an enclosing form when closing', () => {
    const submit = vi.fn(event => event.preventDefault())
    act(() => root.render(<form onSubmit={submit}><Modal open title="编辑" onClose={() => {}}>内容</Modal></form>))
    act(() => document.querySelector<HTMLButtonElement>('.modal-header button')!.click())
    expect(submit).not.toHaveBeenCalled()
  })

  it('supports a right-side drawer placement without changing dialog semantics', () => {
    act(() => root.render(
      <Modal open title="编辑用户" placement="right" onClose={() => {}}>
        <input aria-label="邮箱" />
      </Modal>,
    ))

    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog.classList.contains('modal-placement-right')).toBe(true)
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(document.querySelector('.modal-card')).not.toBeNull()
  })

  it('associates a supplied subtitle with the dialog description', () => {
    act(() => root.render(<Modal open title="编辑" subtitle="核对后保存" onClose={() => {}}>内容</Modal>))
    const dialog = document.querySelector('[role="dialog"]')!
    expect(document.getElementById(dialog.getAttribute('aria-describedby')!)?.textContent).toBe('核对后保存')
  })

  it('skips unavailable autofocus targets and CSS-hidden controls while trapping focus', () => {
    act(() => root.render(<Modal open title="Edit" onClose={() => {}}>
      <input data-autofocus disabled aria-label="Unavailable" />
      <div style={{ display: 'none' }}><button id="hidden-action">Hidden</button></div>
      <input data-autofocus aria-label="Available" />
      <button id="last-action">Save</button>
    </Modal>))
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Available')
    document.querySelector<HTMLButtonElement>('.modal-header button')!.focus()
    key('Tab', true)
    expect(document.activeElement?.id).toBe('last-action')
    key('Tab')
    expect(document.activeElement).toBe(document.querySelector('.modal-header button'))
  })

  it('returns focus to the parent when a child trigger is disabled during its operation', () => {
    function DisabledTrigger() {
      const [nested, setNested] = useState(false)
      const [unavailable, setUnavailable] = useState(false)
      return <Modal open title="Parent" onClose={() => {}}>
        <button id="child-trigger" disabled={unavailable} onClick={() => { setUnavailable(true); setNested(true) }}>Open child</button>
        <Modal open={nested} title="Child" onClose={() => setNested(false)}>Content</Modal>
      </Modal>
    }
    act(() => root.render(<DisabledTrigger />))
    const trigger = document.querySelector<HTMLButtonElement>('#child-trigger')!
    trigger.focus()
    act(() => trigger.click())
    key('Escape')
    expect(document.activeElement).toBe(document.querySelector('.modal-root'))
    expect(trigger.disabled).toBe(true)
    expect(document.body.style.overflow).toBe('hidden')
  })
})
