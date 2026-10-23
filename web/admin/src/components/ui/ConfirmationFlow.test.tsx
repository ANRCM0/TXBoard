import { act, StrictMode, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ConfirmDialogHost, dismissConfirm, requestConfirm } from './ConfirmDialog'
import { Modal } from './Modal'
import { useConfirmation } from '../../lib/useConfirmation'

let host: HTMLDivElement
let root: Root
let ask: ReturnType<typeof useConfirmation>

function Fixture({ context = 'first', allowed = true }: { context?: string; allowed?: boolean }) {
  ask = useConfirmation(context, () => allowed)
  const [draft, setDraft] = useState('unsaved draft')
  return <><Modal open title="Parent editor" onClose={() => {}}>
    <input aria-label="Draft" value={draft} onChange={event => setDraft(event.target.value)} />
    <button id="ask" onClick={() => void ask('Discard draft?')}>Review</button>
  </Modal><ConfirmDialogHost /></>
}

beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => { act(() => { dismissConfirm(); root.unmount() }); host.remove() })

function render(context = 'first', allowed = true) {
  act(() => root.render(<StrictMode><Fixture context={context} allowed={allowed} /></StrictMode>))
}
function confirm() {
  act(() => Array.from(document.querySelectorAll<HTMLButtonElement>('.admin-confirm-dialog .modal-footer button'))
    .find(button => button.textContent === '确认')!.click())
}

describe('confirmation ownership', () => {
  it('preserves the parent draft, focus and scroll lock after cancelling a child', async () => {
    render()
    const trigger = document.querySelector<HTMLButtonElement>('#ask')!
    trigger.focus(); act(() => trigger.click())
    const dialogs = document.querySelectorAll<HTMLElement>('[role="dialog"]')
    expect(dialogs).toHaveLength(2)
    expect(dialogs[0].hasAttribute('data-dialog-covered')).toBe(true)
    expect(dialogs[0].hasAttribute('inert')).toBe(true)
    act(() => document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })))
    expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(1)
    expect(document.querySelector<HTMLInputElement>('[aria-label="Draft"]')!.value).toBe('unsaved draft')
    expect(document.activeElement).toBe(trigger)
    expect(dialogs[0].hasAttribute('inert')).toBe(false)
    expect(document.body.style.overflow).toBe('hidden')
  })

  it('settles a pending request as cancelled when its resource changes', async () => {
    render()
    let pending!: Promise<boolean>
    act(() => { pending = ask('Old resource') })
    render('second')
    await expect(pending).resolves.toBe(false)
    expect(document.querySelector('.admin-confirm-dialog')).toBeNull()
  })

  it('withdraws a pending confirmation when current permission is lost', async () => {
    render()
    let pending!: Promise<boolean>
    act(() => { pending = ask('Protected operation') })
    render('first', false)
    await expect(pending).resolves.toBe(false)
    expect(document.querySelector('.admin-confirm-dialog')).toBeNull()
  })

  it('cancels on unmount and supports new requests after StrictMode effect replay', async () => {
    render()
    let first!: Promise<boolean>, second!: Promise<boolean>
    act(() => { first = ask('First') })
    confirm()
    await expect(first).resolves.toBe(true)
    act(() => { second = ask('Second'); root.render(null) })
    await expect(second).resolves.toBe(false)
  })

  it('settles a superseded request once without running its action', () => {
    render()
    const cancel = vi.fn(), action = vi.fn()
    act(() => {
      requestConfirm({ title: 'First', action, cancelAction: cancel })
      requestConfirm({ title: 'Second', action: () => {} })
    })
    expect(cancel).toHaveBeenCalledTimes(1)
    expect(action).not.toHaveBeenCalled()
  })
})
