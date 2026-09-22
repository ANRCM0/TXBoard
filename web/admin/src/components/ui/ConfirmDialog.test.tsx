import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ConfirmDialogHost, dismissConfirm, requestConfirm } from './ConfirmDialog'

let host: HTMLDivElement
let root: Root

beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
})
afterEach(() => {
  act(() => root.unmount())
  host.remove()
  // Leave no pending confirmation behind for the next test.
  act(() => dismissConfirm())
})

async function mount() {
  await act(async () => {
    root.render(<ConfirmDialogHost/>)
    await new Promise(resolve => setTimeout(resolve, 10))
  })
}

describe('ConfirmDialogHost', () => {
  it('renders nothing until a confirmation is requested', async () => {
    await mount()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('runs the action only after the operator confirms', async () => {
    const action = vi.fn()
    await mount()

    act(() => requestConfirm({
      title: '删除机器',
      message: '确认删除机器？关联节点将自动解绑。',
      danger: true,
      confirmLabel: '删除',
      action,
    }))
    expect(document.body.textContent).toContain('关联节点将自动解绑')
    expect(action).not.toHaveBeenCalled()

    const confirmButton = Array.from(document.querySelectorAll<HTMLButtonElement>('.modal-footer button'))
      .find(button => button.textContent === '删除')!
    act(() => confirmButton.click())

    expect(action).toHaveBeenCalledTimes(1)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('closes on Escape without running the action', async () => {
    const action = vi.fn()
    await mount()

    act(() => requestConfirm({ title: '取消订单', message: '确认取消该订单？', action }))
    act(() => document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })))

    expect(action).not.toHaveBeenCalled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('keeps the danger and label wording supplied by the caller', async () => {
    await mount()
    act(() => requestConfirm({ title: '重置 Token', message: '旧 Token 将失效。', confirmLabel: '重置', action: () => {} }))

    const footer = document.querySelector('.modal-footer')!
    expect(footer.textContent).toContain('重置')
    expect(footer.textContent).toContain('取消')
  })
})